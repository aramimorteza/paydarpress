<?php
/**
 * Key pages: anchor distribution, anchor suggestions and the per-article link limit.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Rules agreed with site owners:
 * - at most {@see MAX_PER_ARTICLE} different key pages linked from one article;
 * - exact-match anchors (anchor = the page's main phrase) stay under {@see EXACT_CAP} of its links;
 * - an anchor of a key page should not be another page's focus keyword.
 */
final class KeyPageLinks {

	public const MAX_PER_ARTICLE = 2;
	public const EXACT_CAP       = 0.30;

	/** Below this many links the share is not meaningful yet. */
	private const MIN_LINKS_FOR_WARNING = 5;

	/** Anchors that say nothing about the target. */
	private const GENERIC = array( 'اینجا', 'کلیک کنید', 'اینجا کلیک کنید', 'بیشتر بخوانید', 'ادامه مطلب', 'این لینک', 'این صفحه', 'لینک', 'بیشتر', 'مشاهده', 'کلیک' );

	/**
	 * Key pages from the profile, keyed by id.
	 *
	 * @return array<int, array>
	 */
	public static function pages(): array {
		$out = array();
		foreach ( (array) ProfileRepository::value( 'key_pages' ) as $page ) {
			if ( is_array( $page ) && ! empty( $page['id'] ) ) {
				$out[ (int) $page['id'] ] = $page;
			}
		}
		return $out;
	}

	/**
	 * Key pages can be posts/pages/products (positive id) or category archives (negative id
	 * = -term_id; product categories are the main sales pages of a shop).
	 *
	 * @param int $id Key page id.
	 */
	public static function is_term( int $id ): bool {
		return $id < 0;
	}

	/**
	 * Title of a key page.
	 *
	 * @param int $id Key page id.
	 */
	public static function title_of( int $id ): string {
		if ( $id < 0 ) {
			$t = get_term( -$id );
			return $t instanceof \WP_Term ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : '';
		}
		return trim( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Address of a key page ('' when it no longer exists).
	 *
	 * @param int $id Key page id.
	 */
	public static function url_of( int $id ): string {
		if ( $id < 0 ) {
			$t    = get_term( -$id );
			$link = $t instanceof \WP_Term ? get_term_link( $t ) : '';
			return is_string( $link ) ? $link : '';
		}
		return 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Whether the key page exists and is public.
	 *
	 * @param int $id Key page id.
	 */
	public static function is_live( int $id ): bool {
		return '' !== self::url_of( $id );
	}

	/**
	 * The phrase an exact-match anchor would repeat: focus keyword, otherwise the title.
	 *
	 * @param int $id Page.
	 */
	public static function main_phrase( int $id ): string {
		global $wpdb;
		$page = self::pages()[ $id ] ?? array();
		if ( ! empty( $page['main_keyword'] ) ) {
			return trim( (string) $page['main_keyword'] );
		}
		if ( $id < 0 ) {
			return self::title_of( $id );
		}
		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$focus = (string) $wpdb->get_var( $wpdb->prepare( "SELECT focus_keyword FROM {$table} WHERE object_id = %d", $id ) );
		if ( '' !== trim( $focus ) ) {
			return trim( $focus );
		}
		return trim( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Anchor type: exact, partial, branded, generic or other.
	 *
	 * @param string $anchor Anchor text.
	 * @param string $main   Main phrase of the target.
	 * @param string $brand  Brand / site name.
	 */
	public static function classify( string $anchor, string $main, string $brand ): string {
		$a = self::norm( $anchor );
		$m = self::norm( $main );
		$b = self::norm( $brand );

		if ( '' === $a ) {
			return 'other';
		}
		if ( in_array( $a, array_map( array( self::class, 'norm' ), self::GENERIC ), true ) ) {
			return 'generic';
		}
		if ( '' !== $m && $a === $m ) {
			return 'exact';
		}
		if ( '' !== $b && false !== mb_strpos( $a, $b ) ) {
			return 'branded';
		}
		if ( '' !== $m ) {
			if ( false !== mb_strpos( $a, $m ) ) {
				return 'partial';
			}
			$words  = array_filter( explode( ' ', $m ), static fn( $w ) => mb_strlen( $w ) > 2 );
			$shared = array_filter( $words, static fn( $w ) => false !== mb_strpos( ' ' . $a . ' ', ' ' . $w . ' ' ) );
			if ( $words && count( $shared ) * 2 >= count( $words ) ) {
				return 'partial';
			}
		}
		return 'other';
	}

	/**
	 * Anchor distribution of every key page, from the last scan's link table.
	 *
	 * @return array<int, array> Per page: counts, exact share, warning, top anchors, conflicts.
	 */
	public static function stats(): array {
		global $wpdb;

		$pages = self::pages();
		if ( ! $pages ) {
			return array();
		}

		$brand = self::brand();
		$table = Schema::table( 'links' );
		$by_page = self::links_by_page( array_keys( $pages ) );

		$conflicts = self::conflicts( $pages );
		$out       = array();
		foreach ( $pages as $id => $page ) {
			$main   = self::main_phrase( $id );
			$counts = array( 'exact' => 0, 'partial' => 0, 'branded' => 0, 'generic' => 0, 'other' => 0 );
			$top    = array();
			foreach ( $by_page[ $id ] ?? array() as $row ) {
				$type             = self::classify( (string) $row['anchor'], $main, $brand );
				$counts[ $type ] += (int) $row['n'];
				$top[]            = array(
					'anchor' => (string) $row['anchor'],
					'count'  => (int) $row['n'],
					'type'   => $type,
				);
			}
			usort( $top, static fn( $x, $y ) => $y['count'] <=> $x['count'] );
			$total = array_sum( $counts );
			$share = $total > 0 ? $counts['exact'] / $total : 0.0;

			$warnings = array();
			if ( $total >= self::MIN_LINKS_FOR_WARNING && $share > self::EXACT_CAP ) {
				$warnings[] = sprintf( '%s٪ لینک‌ها دقیقاً با متن «%s» هستند (سقف ۳۰٪). در مقالات بعدی از متن‌های متنوع‌تر استفاده کنید.', PersianNormalizer::fa_number( (int) round( $share * 100 ) ), $main );
			}
			if ( $counts['generic'] > 0 ) {
				$warnings[] = sprintf( '%s لینک با متن بی‌معنی مثل «اینجا» یا «بیشتر بخوانید» است.', PersianNormalizer::fa_number( $counts['generic'] ) );
			}
			foreach ( $conflicts[ $id ] ?? array() as $c ) {
				$warnings[] = sprintf( 'متن لینک «%s» کلمه کلیدی نوشته «%s» است؛ لینک با این متن به صفحه دیگری، گوگل را درباره صفحه اصلی آن عبارت گیج می‌کند.', $c['anchor'], $c['title'] );
			}

			$out[ $id ] = array(
				'id'          => $id,
				'name'        => (string) ( $page['name'] ?? '' ),
				'main_phrase' => $main,
				'total'       => $total,
				'counts'      => $counts,
				'exact_share' => round( $share, 3 ),
				'over_cap'    => $total >= self::MIN_LINKS_FOR_WARNING && $share > self::EXACT_CAP,
				'top'         => array_slice( $top, 0, 8 ),
				'warnings'    => $warnings,
			);
		}

		return $out;
	}

	/**
	 * Saved anchors of a key page that are the focus keyword of another item.
	 *
	 * @param array<int, array> $pages Key pages.
	 * @return array<int, array<int, array{anchor: string, id: int, title: string}>>
	 */
	public static function conflicts( array $pages ): array {
		global $wpdb;

		$wanted = array();
		foreach ( $pages as $id => $page ) {
			foreach ( (array) ( $page['anchors'] ?? array() ) as $anchor ) {
				$norm = PersianNormalizer::for_key( (string) $anchor );
				if ( '' !== $norm ) {
					$wanted[ $norm ][] = array( (int) $id, (string) $anchor );
				}
			}
		}
		if ( ! $wanted ) {
			return array();
		}

		$table        = Schema::table( 'inventory' );
		$placeholders = implode( ',', array_fill( 0, count( $wanted ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, focus_keyword_norm FROM {$table} WHERE status = 'publish' AND focus_keyword_norm IN ({$placeholders})", array_keys( $wanted ) ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			foreach ( $wanted[ $row['focus_keyword_norm'] ] ?? array() as list( $page_id, $anchor ) ) {
				if ( (int) $row['object_id'] !== $page_id ) {
					$out[ $page_id ][] = array(
						'anchor' => $anchor,
						'id'     => (int) $row['object_id'],
						'title'  => html_entity_decode( get_the_title( (int) $row['object_id'] ), ENT_QUOTES, 'UTF-8' ),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * 4–6 anchor variations for a page, built from its main phrase, title, city and brand.
	 *
	 * @param int $id Page.
	 * @return array<int, array{anchor: string, type: string}>
	 */
	public static function suggest( int $id ): array {
		$main  = self::main_phrase( $id );
		$title = self::title_of( $id );
		$brand = self::brand();
		$city  = self::city();

		$candidates = array( $main );
		if ( '' !== $city && false === mb_strpos( $main, $city ) ) {
			$candidates[] = $main . ' در ' . $city;
		}
		if ( self::norm( $title ) !== self::norm( $main ) ) {
			$candidates[] = $title;
		}
		$candidates[] = 'اطلاعات کامل درباره ' . $main;
		$candidates[] = 'راهنمای ' . $main;
		if ( '' !== $brand ) {
			$candidates[] = $main . ' ' . $brand;
			$candidates[] = $brand;
		}

		$out  = array();
		$seen = array();
		foreach ( $candidates as $anchor ) {
			$anchor = trim( preg_replace( '/\s+/u', ' ', $anchor ) );
			$key    = self::norm( $anchor );
			$words  = count( explode( ' ', $key ) );
			if ( '' === $key || isset( $seen[ $key ] ) || $words > 8 ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'anchor' => $anchor,
				'type'   => self::classify( $anchor, $main, $brand ),
			);
		}
		return array_slice( $out, 0, 6 );
	}

	/**
	 * Internal content links to key pages, grouped by page: anchor, count and source.
	 * Posts are matched by target id, category archives by address.
	 *
	 * @param int[] $ids Key page ids.
	 * @return array<int, array<int, array{anchor: string, n: int}>>
	 */
	public static function links_by_page( array $ids ): array {
		global $wpdb;
		$table = Schema::table( 'links' );
		$out   = array();
		$posts = array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 );
		if ( $posts ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( 'SELECT target_id, anchor, COUNT(*) AS n FROM ' . $table . ' WHERE target_id IN (' . implode( ',', $posts ) . ") AND source_type = 'content' AND link_type = 'internal' GROUP BY target_id, anchor", ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['target_id'] ][] = $row;
			}
		}
		$urls = new UrlNormalizer( home_url( '/' ) );
		foreach ( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i < 0 ) as $id ) {
			$url = self::url_of( $id );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' === $path ) {
				continue;
			}
			$key = $urls->key( $url );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT target_url, anchor, source_id FROM ' . $table . " WHERE source_type = 'content' AND link_type = 'internal' AND (target_url LIKE %s OR target_url LIKE %s)", '%' . $wpdb->esc_like( $path ) . '%', '%' . $wpdb->esc_like( rawurldecode( $path ) ) . '%' ), ARRAY_A );
			$count = array();
			foreach ( (array) $rows as $row ) {
				$abs = $urls->absolute( (string) $row['target_url'], home_url( '/' ) );
				if ( null !== $abs && $urls->key( $abs ) === $key ) {
					$count[ (string) $row['anchor'] ][] = (int) $row['source_id'];
				}
			}
			foreach ( $count as $anchor => $sources ) {
				$out[ $id ][] = array( 'anchor' => $anchor, 'n' => count( $sources ), 'sources' => array_values( array_unique( $sources ) ) );
			}
		}
		return $out;
	}

	/**
	 * Ids of key pages an HTML fragment already links to.
	 *
	 * @param string $html  Post content.
	 * @param int[]  $ids   Key page ids to look for (defaults to all key pages).
	 * @return int[]
	 */
	public static function linked_in( string $html, ?array $ids = null ): array {
		$ids = $ids ?? array_keys( self::pages() );
		if ( ! $ids || ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#isu', $html, $m ) ) {
			return array();
		}

		$urls = new UrlNormalizer( home_url( '/' ) );
		$map  = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id < 0 ) {
				$url = self::url_of( $id );
				if ( '' !== $url ) {
					$map[ $urls->key( $url ) ] = $id;
				}
				continue;
			}
			$map[ $urls->key( (string) get_permalink( $id ) ) ] = $id;
			$map[ $urls->key( home_url( '/?page_id=' . $id ) ) ] = $id;
			$map[ $urls->key( home_url( '/?p=' . $id ) ) ]       = $id;
		}

		$found = array();
		foreach ( $m[2] as $href ) {
			$abs = $urls->absolute( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ), home_url( '/' ) );
			if ( null === $abs ) {
				continue;
			}
			$key = $urls->key( $abs );
			if ( isset( $map[ $key ] ) ) {
				$found[ $map[ $key ] ] = true;
			}
		}
		return array_keys( $found );
	}

	/**
	 * Whether a post belongs to the categories a key page should be linked from.
	 *
	 * @param int   $post_id Source post.
	 * @param array $page    Key page entry.
	 */
	public static function category_allows( int $post_id, array $page ): bool {
		$cats = array_map( 'intval', (array) ( $page['link_categories'] ?? array() ) );
		if ( ! $cats ) {
			return true;
		}
		return (bool) array_intersect( $cats, wp_get_post_categories( $post_id ) );
	}

	/**
	 * Brand / site name.
	 */
	public static function brand(): string {
		$name = trim( (string) ProfileRepository::value( 'site_name' ) );
		return '' !== $name ? $name : trim( (string) get_bloginfo( 'name' ) );
	}

	/**
	 * A single city from the geographic scope, if the scope names exactly one place.
	 */
	public static function city(): string {
		$scope = trim( (string) ProfileRepository::value( 'geographic_scope' ) );
		if ( '' === $scope || mb_strlen( $scope ) > 25 || preg_match( '/[،,؛;\/]|سراسر|کل کشور|ایران|آنلاین|بین‌?المللی/u', $scope ) ) {
			return '';
		}
		return $scope;
	}

	/**
	 * Normalized comparison form.
	 *
	 * @param string $text Text.
	 */
	public static function norm( string $text ): string {
		return PersianNormalizer::for_key( $text );
	}
}
