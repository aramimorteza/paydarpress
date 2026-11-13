<?php
/**
 * Classification of existing content: which posts or products need better categories and
 * tags, what the AI sees of each item, applying approved suggestions (add only, never
 * remove), and undoing a whole round.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Profile\TaxonomyMap;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Scanner\ScanManager;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Kinds: post (category + post_tag) and product (product_cat + product_tag).
 */
final class Classifier {

	/** Items per AI request. */
	public const CHUNK = 25;

	public const KINDS = array(
		'post'    => array( 'type' => 'post', 'cat' => 'category', 'tag' => 'post_tag', 'label' => 'مقالات', 'unit' => 'مقاله' ),
		'product' => array( 'type' => 'product', 'cat' => 'product_cat', 'tag' => 'product_tag', 'label' => 'محصولات', 'unit' => 'محصول' ),
	);

	public const META = '_paydarpress_classified';

	private const LOG    = 'paydarpress_classify_log';
	private const FLAGS  = 'paydarpress_term_flags';
	private const RECENT = 180;

	/**
	 * Kinds available on this site.
	 *
	 * @return array<string, array>
	 */
	public static function kinds(): array {
		return array_filter( self::KINDS, static fn( $k ) => post_type_exists( $k['type'] ) && taxonomy_exists( $k['cat'] ) );
	}

	/**
	 * Items that need classification.
	 *
	 * @param string $kind  post|product.
	 * @param string $scope weak (no real category or fewer than 2 tags) | all.
	 * @return int[] Post IDs, oldest classification first.
	 */
	public static function candidates( string $kind, string $scope = 'weak' ): array {
		global $wpdb;
		$k = self::kind( $kind );

		// Recently classified items are skipped (the owner already reviewed them).
		$since = gmdate( 'Y-m-d H:i:s', time() - ( 'all' === $scope ? 30 : self::RECENT ) * DAY_IN_SECONDS );
		$skip  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value >= %s", self::META, $since ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$skip  = array_flip( $skip );

		$default = (int) get_option( 'default_category' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID,
					SUM(CASE WHEN tt.taxonomy = %s AND tt.term_id <> %d THEN 1 ELSE 0 END) AS cats,
					SUM(CASE WHEN tt.taxonomy = %s THEN 1 ELSE 0 END) AS tags
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
				WHERE p.post_type = %s AND p.post_status = 'publish'
				GROUP BY p.ID ORDER BY p.ID ASC",
				$k['cat'],
				'category' === $k['cat'] ? $default : 0,
				$k['tag'],
				$k['type']
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$id = (int) $r['ID'];
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}
			if ( 'weak' === $scope && (int) $r['cats'] > 0 && ( (int) $r['tags'] >= 2 || ! taxonomy_exists( $k['tag'] ) ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * Plan for the UI: counts, requests needed, URL risk, structure review state.
	 *
	 * @param string $kind  Kind.
	 * @param string $scope Scope.
	 */
	public static function plan( string $kind, string $scope ): array {
		$ids       = self::candidates( $kind, $scope );
		$k         = self::kind( $kind );
		$structure = get_option( 'paydarpress_structure_plan', array() );
		return array(
			'kind'              => $kind,
			'scope'             => $scope,
			'items'             => count( $ids ),
			'requests'          => (int) ceil( count( $ids ) / self::CHUNK ),
			'chunk'             => self::CHUNK,
			'category_in_url'   => self::category_in_url( $kind ),
			'structure_checked' => ! empty( $structure ),
			'label'             => $k['label'],
			'unit'              => $k['unit'],
			'flags'             => count( self::flags() ),
			'log'               => self::log_summary(),
		);
	}

	/**
	 * Text block describing items for the prompt.
	 *
	 * @param int[] $ids Post IDs.
	 */
	public static function items_text( array $ids ): string {
		global $wpdb;
		$inv   = Schema::table( 'inventory' );
		$lines = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$kind = 'product' === $post->post_type ? 'product' : 'post';
			$k    = self::KINDS[ $kind ];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row     = $wpdb->get_row( $wpdb->prepare( "SELECT focus_keyword, outline FROM {$inv} WHERE object_id = %d", $id ), ARRAY_A );
			$outline = json_decode( (string) ( $row['outline'] ?? '' ), true );
			$heads   = is_array( $outline ) ? implode( ' / ', array_slice( array_map( static fn( $h ) => (string) $h['text'], $outline ), 0, 12 ) ) : '';
			$text    = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ) );
			$words   = preg_split( '/\s+/u', $text, 301 );
			$start   = implode( ' ', array_slice( is_array( $words ) ? $words : array(), 0, 300 ) );
			$cats    = wp_get_post_terms( $id, $k['cat'], array( 'fields' => 'names' ) );
			$tags    = taxonomy_exists( $k['tag'] ) ? wp_get_post_terms( $id, $k['tag'], array( 'fields' => 'names' ) ) : array();
			$lines[] = "### ITEM {$id} ({$kind})"
				. "\ntitle: " . html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' )
				. ( ! empty( $row['focus_keyword'] ) ? "\nfocus keyword: {$row['focus_keyword']}" : '' )
				. "\ncurrent categories: " . ( is_array( $cats ) && $cats ? implode( '، ', $cats ) : '(none)' )
				. "\ncurrent tags: " . ( is_array( $tags ) && $tags ? implode( '، ', $tags ) : '(none)' )
				. ( '' !== $heads ? "\nheadings: {$heads}" : '' )
				. "\nbeginning: {$start}";
		}
		return implode( "\n\n", $lines );
	}

	/**
	 * Active terms of a kind for the prompt.
	 *
	 * @param string $kind Kind.
	 */
	public static function terms_text( string $kind ): string {
		$k     = self::kind( $kind );
		$notes = TaxonomyMap::notes();
		$cats  = array();
		foreach ( (array) get_terms( array( 'taxonomy' => $k['cat'], 'hide_empty' => false, 'number' => 300 ) ) as $t ) {
			if ( ! $t instanceof WP_Term || ( 'category' === $k['cat'] && (int) $t->term_id === (int) get_option( 'default_category' ) ) ) {
				continue;
			}
			$n    = $notes[ (int) $t->term_id ] ?? array();
			$role = TaxonomyMap::role_of( $n );
			if ( ! in_array( $role, TaxonomyMap::ACTIVE_ROLES, true ) ) {
				continue;
			}
			$parent = $t->parent ? get_term( $t->parent ) : null;
			$cats[] = '- ' . html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) . ( $parent instanceof WP_Term ? ' (under ' . html_entity_decode( $parent->name, ENT_QUOTES, 'UTF-8' ) . ')' : '' ) . ( '' !== (string) ( $n['scope'] ?? '' ) ? ' — covers: ' . $n['scope'] : '' );
		}
		$tags = array();
		if ( taxonomy_exists( $k['tag'] ) ) {
			foreach ( (array) get_terms( array( 'taxonomy' => $k['tag'], 'hide_empty' => false, 'number' => 400, 'orderby' => 'count', 'order' => 'DESC' ) ) as $t ) {
				if ( $t instanceof WP_Term && in_array( TaxonomyMap::role( (int) $t->term_id ), TaxonomyMap::ACTIVE_ROLES, true ) ) {
					$tags[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		return "## CATEGORIES (exact names)\n" . ( $cats ? implode( "\n", $cats ) : '- (none)' )
			. "\n\n## EXISTING TAGS (exact names, most used first)\n" . ( $tags ? implode( '، ', array_slice( $tags, 0, 250 ) ) : '(none)' );
	}

	/**
	 * Applies one approved suggestion: adds terms, sets the primary category, keeps the old
	 * address reachable when the permalink changed.
	 *
	 * @param array $c       Checked suggestion (post_id, add_cat_ids, add_tag_ids, primary_id).
	 * @param array $created New tag name => term id (approved new tags).
	 * @return array{ok: bool, message: string, undo: array}
	 */
	public static function apply( array $c, array $created ): array {
		$post = get_post( (int) $c['post_id'] );
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return array( 'ok' => false, 'message' => 'مطلب پیدا نشد یا اجازه ویرایش ندارید.', 'undo' => array() );
		}
		$k      = self::KINDS[ 'product' === $post->post_type ? 'product' : 'post' ];
		$before = (string) get_permalink( $post );
		$undo   = array(
			'post'      => $post->ID,
			'terms'     => array(),
			'primary'   => null,
			'url'       => $before,
		);

		$cats = array_values( array_diff( array_map( 'intval', (array) $c['add_cat_ids'] ), wp_get_post_terms( $post->ID, $k['cat'], array( 'fields' => 'ids' ) ) ) );
		if ( $cats ) {
			wp_set_object_terms( $post->ID, $cats, $k['cat'], true );
			$undo['terms'] = array_merge( $undo['terms'], $cats );
		}
		$tag_ids = array_map( 'intval', (array) $c['add_tag_ids'] );
		foreach ( (array) ( $c['new_tags'] ?? array() ) as $name ) {
			if ( isset( $created[ $name ] ) ) {
				$tag_ids[] = (int) $created[ $name ];
			}
		}
		if ( $tag_ids && taxonomy_exists( $k['tag'] ) ) {
			$current = wp_get_post_terms( $post->ID, $k['tag'], array( 'fields' => 'ids' ) );
			$room    = max( 0, TaxonomyMap::MAX_TAGS - count( (array) $current ) );
			$tag_ids = array_slice( array_values( array_diff( array_unique( $tag_ids ), (array) $current ) ), 0, $room );
			if ( $tag_ids ) {
				wp_set_object_terms( $post->ID, $tag_ids, $k['tag'], true );
				$undo['terms'] = array_merge( $undo['terms'], $tag_ids );
			}
		}

		// Primary category (posts only; stored by the SEO plugin).
		$primary = (int) ( $c['primary_id'] ?? 0 );
		if ( $primary > 0 && 'post' === $post->post_type ) {
			$adapter = \PaydarWP\PaydarPress\Seo\SeoDetector::adapter();
			if ( 'none' !== $adapter->key() ) {
				if ( ! has_term( $primary, 'category', $post ) ) {
					wp_set_object_terms( $post->ID, array( $primary ), 'category', true );
					$undo['terms'][] = $primary;
				}
				$old             = $adapter->post( $post->ID );
				$undo['primary'] = (int) ( $old['primary_term_id'] ?? 0 );
				$adapter->write_post( $post->ID, array( 'primary_term_id' => $primary ) );
			}
		}
		// Uncategorized goes once the post has a real category.
		if ( 'post' === $post->post_type ) {
			$default = (int) get_option( 'default_category' );
			$now     = wp_get_post_categories( $post->ID );
			if ( count( $now ) > 1 && in_array( $default, $now, true ) ) {
				wp_remove_object_terms( $post->ID, $default, 'category' );
				$undo['removed_default'] = $default;
			}
		}

		clean_post_cache( $post->ID );
		update_post_meta( $post->ID, self::META, current_time( 'mysql', true ) );

		$message = '';
		$after   = (string) get_permalink( $post->ID );
		if ( Redirects::path( $after ) !== Redirects::path( $before ) ) {
			// WordPress changed the address (category in the permalink); the old one must not 404.
			$r       = RedirectOwner::ensure( $before, $post->ID, 'category' );
			$message = 'آدرس تغییر کرد. ' . $r['message'];
		}
		Queue::schedule( ScanManager::ACTION_ITEM, array( $post->ID ), 10, false );

		foreach ( (array) ( $c['misfit'] ?? array() ) as $m ) {
			self::flag( $post->ID, (int) $m['term_id'], (string) $m['reason'] );
		}
		return array( 'ok' => true, 'message' => $message, 'undo' => $undo );
	}

	/**
	 * Records one applied round (for undo).
	 *
	 * @param array $undo    Undo entries.
	 * @param int[] $created Created term IDs.
	 */
	public static function log( array $undo, array $created ): void {
		if ( ! $undo && ! $created ) {
			return;
		}
		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'id'      => wp_generate_password( 8, false, false ),
			'at'      => time(),
			'items'   => array_slice( $undo, 0, 2000 ),
			'created' => array_values( $created ),
		);
		update_option( self::LOG, array_slice( $log, -10 ), false );
		Activity::log( 'classify.applied', sprintf( 'طبقه‌بندی %s مطلب اعمال شد (%s برچسب تازه).', PersianNormalizer::fa_number( count( $undo ) ), PersianNormalizer::fa_number( count( $created ) ) ) );
	}

	/**
	 * Undoes a round: removes the terms it added, restores primary categories and removes
	 * the tags it created when no other content uses them.
	 *
	 * @param string $id Round id.
	 * @return int Items restored.
	 * @throws RuntimeException When the round is unknown.
	 */
	public static function undo( string $id ): int {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$hit = null;
		foreach ( $log as $i => $round ) {
			if ( $round['id'] === $id ) {
				$hit = $i;
			}
		}
		if ( null === $hit ) {
			throw new RuntimeException( 'این مرحله طبقه‌بندی پیدا نشد.' );
		}
		$n = 0;
		foreach ( $log[ $hit ]['items'] as $u ) {
			$post = get_post( (int) $u['post'] );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$before = (string) get_permalink( $post );
			foreach ( (array) $u['terms'] as $term_id ) {
				$term = get_term( (int) $term_id );
				if ( $term instanceof WP_Term ) {
					wp_remove_object_terms( $post->ID, (int) $term_id, $term->taxonomy );
				}
			}
			if ( ! empty( $u['removed_default'] ) && ! wp_get_post_categories( $post->ID ) ) {
				wp_set_object_terms( $post->ID, array( (int) $u['removed_default'] ), 'category', true );
			}
			if ( null !== $u['primary'] ) {
				if ( (int) $u['primary'] > 0 ) {
					\PaydarWP\PaydarPress\Seo\SeoDetector::adapter()->write_post( $post->ID, array( 'primary_term_id' => (int) $u['primary'] ) );
				} else {
					delete_post_meta( $post->ID, '_yoast_wpseo_primary_category' );
					delete_post_meta( $post->ID, 'rank_math_primary_category' );
				}
			}
			delete_post_meta( $post->ID, self::META );
			clean_post_cache( $post->ID );
			$after = (string) get_permalink( $post->ID );
			if ( Redirects::path( $after ) !== Redirects::path( $before ) ) {
				RedirectOwner::ensure( $before, $post->ID, 'category' );
			}
			Queue::schedule( ScanManager::ACTION_ITEM, array( $post->ID ), 10, false );
			++$n;
		}
		foreach ( (array) $log[ $hit ]['created'] as $term_id ) {
			$term = get_term( (int) $term_id );
			if ( $term instanceof WP_Term && 0 === (int) $term->count ) {
				wp_delete_term( (int) $term_id, $term->taxonomy );
			}
		}
		unset( $log[ $hit ] );
		update_option( self::LOG, array_values( $log ), false );
		Activity::log( 'classify.undone', sprintf( 'طبقه‌بندی %s مطلب بازگردانده شد.', PersianNormalizer::fa_number( $n ) ) );
		return $n;
	}

	/**
	 * Recent rounds for the UI.
	 */
	public static function log_summary(): array {
		$log = get_option( self::LOG, array() );
		return array_reverse(
			array_map(
				static fn( $r ) => array(
					'id'      => $r['id'],
					'at'      => gmdate( 'c', (int) $r['at'] ),
					'items'   => count( $r['items'] ),
					'created' => count( $r['created'] ),
				),
				is_array( $log ) ? $log : array()
			)
		);
	}

	/**
	 * Terms reported as not fitting an item (for the owner's decision later).
	 *
	 * @return array<int, array{post: int, term: int, reason: string, at: int}>
	 */
	public static function flags(): array {
		$f = get_option( self::FLAGS, array() );
		return is_array( $f ) ? $f : array();
	}

	/**
	 * Reports a term that does not fit an item. Nothing is removed.
	 *
	 * @param int    $post_id Post.
	 * @param int    $term_id Term.
	 * @param string $reason  Reason.
	 */
	public static function flag( int $post_id, int $term_id, string $reason ): void {
		if ( $post_id <= 0 || $term_id <= 0 ) {
			return;
		}
		$flags                              = self::flags();
		$flags[ $post_id . ':' . $term_id ] = array(
			'post'   => $post_id,
			'term'   => $term_id,
			'reason' => mb_substr( sanitize_text_field( $reason ), 0, 200 ),
			'at'     => time(),
		);
		update_option( self::FLAGS, array_slice( $flags, -500, null, true ), false );
	}

	/**
	 * Whether the permalink of this kind contains its category.
	 *
	 * @param string $kind Kind.
	 */
	public static function category_in_url( string $kind ): bool {
		if ( 'product' === $kind ) {
			$base = (array) get_option( 'woocommerce_permalinks', array() );
			return false !== strpos( (string) ( $base['product_base'] ?? '' ), '%product_cat%' );
		}
		return false !== strpos( (string) get_option( 'permalink_structure' ), '%category%' );
	}

	/**
	 * Kind definition.
	 *
	 * @param string $kind Kind.
	 * @throws RuntimeException On unknown kind.
	 */
	public static function kind( string $kind ): array {
		$kinds = self::kinds();
		if ( ! isset( $kinds[ $kind ] ) ) {
			throw new RuntimeException( 'این نوع محتوا در سایت فعال نیست.' );
		}
		return $kinds[ $kind ];
	}
}
