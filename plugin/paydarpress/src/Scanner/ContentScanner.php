<?php
/**
 * Scans one content item into the inventory and link graph.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Scanner;

use PaydarWP\PaydarPress\Audit\AltQuality;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Extractors\ElementorExtractor;
use PaydarWP\PaydarPress\Extractors\Extracted;
use PaydarWP\PaydarPress\Extractors\HtmlExtractor;
use PaydarWP\PaydarPress\Links\LinkResolver;
use PaydarWP\PaydarPress\Links\UrlNormalizer;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use PaydarWP\PaydarPress\Text\YearDetector;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * See spec sections 4.1, 4.2 and 6.
 */
final class ContentScanner {

	public const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Bump whenever extraction or detection logic changes (year rules, alt rules, word count…),
	 * so the next scan reprocesses items whose content itself has not changed.
	 */
	public const LOGIC_VERSION = 4;

	/** @var HtmlExtractor */
	private $html;

	/** @var ElementorExtractor */
	private $elementor;

	/** @var UrlNormalizer */
	private $urls;

	/** @var YearDetector */
	private $years;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->html      = new HtmlExtractor();
		$this->elementor = new ElementorExtractor( $this->html );
		$this->urls      = new UrlNormalizer( home_url( '/' ) );
		$this->years     = new YearDetector();
	}

	/**
	 * Post types included in scans.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array( 'post', 'page' );

		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}

		/**
		 * Filters the post types PaydarPress scans.
		 *
		 * @param string[] $types Post types.
		 */
		return array_values( array_unique( (array) apply_filters( 'paydarpress/scan_post_types', $types ) ) );
	}

	/**
	 * Scans a post. Removes it from the inventory if it no longer qualifies.
	 *
	 * @param int $post_id Post ID.
	 * @return string `scanned`, `unchanged` or `removed`.
	 */
	public function scan( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) || ! in_array( $post->post_status, self::STATUSES, true ) ) {
			self::remove( $post_id );
			return 'removed';
		}

		$elementor_raw = '';
		$extracted     = $this->extract( $post, $elementor_raw );
		$seo           = SeoDetector::adapter()->post( $post_id );
		$permalink     = (string) get_permalink( $post );
		$thumbnail_id  = (int) get_post_thumbnail_id( $post );
		$thumb_alt     = $thumbnail_id > 0 ? trim( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ) : '';

		$hash = md5(
			(string) wp_json_encode(
				array( self::LOGIC_VERSION, $post->post_title, $post->post_content, $elementor_raw, $post->post_status, $permalink, $seo, $thumbnail_id, $thumb_alt, $post->post_author, $post->post_modified_gmt, wp_get_post_categories( $post_id ) )
			)
		);

		$existing_hash = $this->existing_hash( $post_id );
		if ( $existing_hash === $hash ) {
			$this->touch( $post_id );
			return 'unchanged';
		}

		$link_stats = $this->store_links( $post_id, $extracted->links, $permalink );
		$this->store_images( $post_id, $extracted->images, $permalink );
		$images     = $this->image_stats( $extracted );

		if ( $thumbnail_id > 0 && '' !== $thumb_alt && AltQuality::is_generic( $thumb_alt, (string) wp_get_attachment_url( $thumbnail_id ) ) ) {
			++$images['generic_alt'];
		}

		$row = array(
			'object_id'            => $post_id,
			'object_type'          => $post->post_type,
			'status'               => $post->post_status,
			'title_norm'           => mb_substr( PersianNormalizer::for_key( $post->post_title ), 0, 255 ),
			'url'                  => mb_substr( $permalink, 0, 500 ),
			'primary_term_id'      => $seo['primary_term_id'] ?? $this->first_term( $post ),
			'focus_keyword'        => null !== $seo['focus_keyword'] ? mb_substr( $seo['focus_keyword'], 0, 255 ) : null,
			'focus_keyword_norm'   => null !== $seo['focus_keyword'] ? mb_substr( PersianNormalizer::for_key( $seo['focus_keyword'] ), 0, 191 ) : null,
			'seo_title'            => null !== $seo['seo_title'] ? mb_substr( $seo['seo_title'], 0, 255 ) : null,
			'meta_description'     => $seo['meta_description'],
			'is_noindex'           => $seo['noindex'] ? 1 : 0,
			'is_cornerstone'       => $seo['cornerstone'] ? 1 : 0,
			'canonical'            => null !== $seo['canonical'] ? mb_substr( $seo['canonical'], 0, 500 ) : null,
			'word_count'           => PersianNormalizer::word_count( $extracted->text ),
			'h2_count'             => count( array_filter( $extracted->headings, static fn( $h ) => 2 === $h['level'] ) ),
			'outline'              => wp_json_encode( $this->outline( $extracted ), JSON_UNESCAPED_UNICODE ),
			'excerpt'              => $this->excerpt( $post, $extracted ),
			'years_mentioned'      => wp_json_encode( $this->years->detect( $post->post_title, $extracted->text ) ),
			'has_direct_answer'    => $this->has_direct_answer( $extracted ) ? 1 : 0,
			'has_featured_image'   => $thumbnail_id > 0 ? 1 : 0,
			'featured_alt_ok'      => ( $thumbnail_id > 0 && '' !== $thumb_alt ) ? 1 : 0,
			'inline_images'        => min( 65535, $images['total'] ),
			'inline_images_no_alt' => min( 65535, $images['missing_alt'] ),
			'images_generic_alt'   => min( 65535, $images['generic_alt'] ),
			'internal_out'         => min( 65535, $link_stats['internal'] ),
			'external_out'         => min( 65535, $link_stats['external'] ),
			'author_id'            => (int) $post->post_author,
			'content_hash'         => $hash,
			'published_at'         => '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : null,
			'modified_at'          => '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : null,
			'scanned_at'           => current_time( 'mysql', true ),
		);

		$this->upsert( $row, null !== $existing_hash );

		return 'scanned';
	}

	/**
	 * Removes a post from the inventory and the link graph, and invalidates links to it.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function remove( int $post_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'inventory' ), array( 'object_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'links' ),
			array(
				'source_id'   => $post_id,
				'source_type' => 'content',
			),
			array( '%d', '%s' )
		);

		LinkResolver::invalidate_target( $post_id );
	}

	/**
	 * Picks the extractor and returns structured content.
	 *
	 * @param WP_Post $post          Post.
	 * @param string  $elementor_raw Receives raw Elementor data (for hashing).
	 */
	private function extract( WP_Post $post, string &$elementor_raw ): Extracted {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$raw  = get_post_meta( $post->ID, '_elementor_data', true );
			$data = ElementorExtractor::decode( $raw );
			if ( null !== $data ) {
				$elementor_raw = is_string( $raw ) ? $raw : (string) wp_json_encode( $raw );
				return $this->elementor->extract( $data );
			}
		}

		return $this->html->extract( (string) $post->post_content );
	}

	/**
	 * Replaces this post's links in the graph. Internal links start as `pending`.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param array  $links     Extracted links.
	 * @param string $permalink Source URL (base for relative links).
	 * @return array{internal:int, external:int}
	 */
	private function store_links( int $post_id, array $links, string $permalink ): array {
		global $wpdb;

		$table = Schema::table( 'links' );

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'source_id'   => $post_id,
				'source_type' => 'content',
			),
			array( '%d', '%s' )
		);

		$stats = array(
			'internal' => 0,
			'external' => 0,
		);

		foreach ( $links as $link ) {
			$url = $this->urls->absolute( (string) $link['url'], $permalink );
			if ( null === $url ) {
				continue;
			}

			if ( ! $this->urls->is_internal( $url ) ) {
				$type  = 'external';
				$state = 'unknown';
				++$stats['external'];
			} elseif ( $this->urls->is_asset( $url ) ) {
				$type  = 'asset';
				$state = self::local_file_state( $url );
			} else {
				$type  = 'internal';
				$state = 'pending';
				++$stats['internal'];
			}

			$written = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'source_id'    => $post_id,
					'source_type'  => 'content',
					'target_url'   => mb_substr( $url, 0, 500 ),
					'anchor'       => mb_substr( (string) $link['anchor'], 0, 255 ),
					'link_type'    => $type,
					'target_state' => $state,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			self::assert_written( $written );
		}

		return $stats;
	}

	/**
	 * Images in the text as link rows (link_type image), with whether the file still exists.
	 *
	 * @param int    $post_id   Post.
	 * @param array  $images    Extracted images.
	 * @param string $permalink Base URL.
	 */
	private function store_images( int $post_id, array $images, string $permalink ): void {
		global $wpdb;
		$table = Schema::table( 'links' );
		$seen  = array();
		foreach ( $images as $image ) {
			$url = $this->urls->absolute( (string) $image['src'], $permalink );
			if ( null === $url || ! $this->urls->is_internal( $url ) || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'source_id'    => $post_id,
					'source_type'  => 'content',
					'target_url'   => mb_substr( $url, 0, 500 ),
					'anchor'       => mb_substr( (string) ( $image['alt'] ?? '' ), 0, 255 ),
					'link_type'    => 'image',
					'target_state' => self::local_file_state( $url ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Whether a file under the uploads folder exists ('ok' / 'missing'; 'unknown' elsewhere).
	 *
	 * @param string $url Absolute URL.
	 */
	public static function local_file_state( string $url ): string {
		$uploads = wp_get_upload_dir();
		$base    = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );
		$path    = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $base || ! str_starts_with( $path, $base . '/' ) ) {
			return 'unknown';
		}
		$rel  = substr( $path, strlen( $base ) );
		$file = wp_normalize_path( $uploads['basedir'] . $rel );
		if ( str_contains( $rel, '..' ) ) {
			return 'unknown';
		}
		return file_exists( $file ) ? 'ok' : 'missing';
	}

	/**
	 * Inline image counts. Missing alt = attribute absent (and not resolvable from the
	 * attachment) or empty.
	 *
	 * @param Extracted $extracted Extracted content.
	 * @return array{total:int, missing_alt:int, generic_alt:int}
	 */
	private function image_stats( Extracted $extracted ): array {
		$missing = 0;
		$generic = 0;

		foreach ( $extracted->images as $image ) {
			$alt = $image['alt'];

			if ( null === $alt && ! empty( $image['attachment_id'] ) ) {
				$alt = trim( (string) get_post_meta( (int) $image['attachment_id'], '_wp_attachment_image_alt', true ) );
			}

			if ( null === $alt || '' === $alt ) {
				++$missing;
			} elseif ( AltQuality::is_generic( $alt, (string) $image['src'] ) ) {
				++$generic;
			}
		}

		return array(
			'total'       => count( $extracted->images ),
			'missing_alt' => $missing,
			'generic_alt' => $generic,
		);
	}

	/**
	 * H2/H3 outline (max 50 items).
	 *
	 * @param Extracted $extracted Extracted content.
	 */
	private function outline( Extracted $extracted ): array {
		$outline = array();

		foreach ( $extracted->headings as $heading ) {
			if ( $heading['level'] >= 2 && $heading['level'] <= 3 ) {
				$outline[] = array(
					'level' => $heading['level'],
					'text'  => mb_substr( $heading['text'], 0, 200 ),
				);
			}
			if ( count( $outline ) >= 50 ) {
				break;
			}
		}

		return $outline;
	}

	/**
	 * Manual excerpt, or the first 300 characters of the text.
	 *
	 * @param WP_Post   $post      Post.
	 * @param Extracted $extracted Extracted content.
	 */
	private function excerpt( WP_Post $post, Extracted $extracted ): string {
		$source = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $extracted->text;
		$text   = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $source ) ) );

		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 297 ) . '…' : $text;
	}

	/**
	 * Spec section 7.3: one of the first two intro paragraphs has 25–90 words.
	 *
	 * @param Extracted $extracted Extracted content.
	 */
	private function has_direct_answer( Extracted $extracted ): bool {
		foreach ( array_slice( $extracted->intro_paragraphs, 0, 2 ) as $paragraph ) {
			$words = PersianNormalizer::word_count( $paragraph );
			if ( $words >= 25 && $words <= 90 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * First category (posts) or product category (products).
	 *
	 * @param WP_Post $post Post.
	 */
	private function first_term( WP_Post $post ): ?int {
		$taxonomy = 'product' === $post->post_type ? 'product_cat' : ( 'post' === $post->post_type ? 'category' : '' );
		if ( '' === $taxonomy ) {
			return null;
		}

		$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );

		return is_array( $terms ) && ! empty( $terms ) ? (int) $terms[0] : null;
	}

	/**
	 * Stored hash, or null if the post is not in the inventory.
	 *
	 * @param int $post_id Post ID.
	 */
	private function existing_hash( int $post_id ): ?string {
		global $wpdb;

		$table = Schema::table( 'inventory' );
		// get_row, not get_var: get_var() returns null for an empty string, which would make an
		// existing row with a cleared hash look new.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT object_id, content_hash FROM {$table} WHERE object_id = %d", $post_id ), ARRAY_A );

		return is_array( $row ) ? (string) $row['content_hash'] : null;
	}

	/**
	 * Updates scanned_at only.
	 *
	 * @param int $post_id Post ID.
	 */
	private function touch( int $post_id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'inventory' ),
			array( 'scanned_at' => current_time( 'mysql', true ) ),
			array( 'object_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Inserts or updates an inventory row (keeps computed columns such as internal_in).
	 *
	 * @param array $row    Row data.
	 * @param bool  $exists Whether the row exists.
	 */
	private function upsert( array $row, bool $exists ): void {
		global $wpdb;

		$table = Schema::table( 'inventory' );

		if ( $exists ) {
			$id = $row['object_id'];
			unset( $row['object_id'] );
			$result = $wpdb->update( $table, $row, array( 'object_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$result = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		self::assert_written( $result );
	}

	/**
	 * Stops the scan when a write fails. Continuing would let the stale-row cleanup at the end
	 * of the scan delete content that simply failed to save.
	 *
	 * @param int|false $result Result of $wpdb->insert/update.
	 * @throws \RuntimeException On database error.
	 */
	private static function assert_written( $result ): void {
		global $wpdb;

		if ( false === $result ) {
			throw new \RuntimeException( 'ذخیره اطلاعات در دیتابیس ناموفق بود: ' . (string) $wpdb->last_error );
		}
	}
}
