<?php
/**
 * Taxonomy map: the site's categories and tags (posts and products) with the owner's notes.
 *
 * The list of terms is always read live from WordPress (renames and deletions never go out of
 * sync). The profile keeps only the owner's notes per term, sparsely, in the `categories_scope`
 * field: scope, search patterns, role, target and related key page. A term without notes uses
 * the defaults, so every new category or tag appears in the map automatically.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Profile;

use RuntimeException;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Roles: high / normal / low = active with that importance; nav = navigation only (no content
 * targets, no new content); retired = do not use for new content (candidate for merge/delete).
 */
final class TaxonomyMap {

	public const TAXONOMIES = array(
		'category'    => 'دسته مقالات',
		'post_tag'    => 'برچسب مقالات',
		'product_cat' => 'دسته محصولات',
		'product_tag' => 'برچسب محصولات',
	);

	public const ROLES = array(
		'high'    => 'اصلی (مهم)',
		'normal'  => 'عادی',
		'low'     => 'فرعی (کم‌اهمیت)',
		'nav'     => 'فقط ناوبری',
		'retired' => 'کنار گذاشته',
	);

	/** Roles that receive new content. */
	public const ACTIVE_ROLES = array( 'high', 'normal', 'low' );

	/** Maximum tags per post (audit, prompts, import). */
	public const MAX_TAGS = 5;

	/** Products carry more tags (colour, material, use). */
	public const MAX_PRODUCT_TAGS = 10;

	/**
	 * Tag cap for a post type or tag taxonomy.
	 *
	 * @param string $type post|product|post_tag|product_tag.
	 */
	public static function max_tags( string $type ): int {
		return in_array( $type, array( 'product', 'product_tag' ), true ) ? self::MAX_PRODUCT_TAGS : self::MAX_TAGS;
	}

	/** Stored notes per term (at most). */
	public const MAX_NOTES = 2000;

	private const MIGRATED = 'paydarpress_taxmap_migrated';

	/**
	 * Taxonomies that exist on this site.
	 *
	 * @return array<string, string>
	 */
	public static function taxonomies(): array {
		return array_filter( self::TAXONOMIES, static fn( $t ) => taxonomy_exists( $t ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Owner notes keyed by term id.
	 *
	 * @return array<int, array>
	 */
	public static function notes(): array {
		self::migrate();
		$out = array();
		foreach ( (array) ProfileRepository::value( 'categories_scope' ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$out[ (int) $item['id'] ] = $item;
			}
		}
		return $out;
	}

	/**
	 * One term with its notes and defaults applied.
	 *
	 * @param WP_Term    $term  Term.
	 * @param array|null $notes Notes (null: look up).
	 */
	public static function entry( WP_Term $term, ?array $notes = null ): array {
		$n    = $notes ?? ( self::notes()[ (int) $term->term_id ] ?? array() );
		$role = self::role_of( $n );
		$link = get_term_link( $term );
		return array(
			'id'              => (int) $term->term_id,
			'taxonomy'        => $term->taxonomy,
			'name'            => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'slug'            => rawurldecode( $term->slug ),
			'parent'          => (int) $term->parent,
			'count'           => (int) $term->count,
			'url'             => is_string( $link ) ? $link : '',
			'scope'           => (string) ( $n['scope'] ?? '' ),
			'search_patterns' => (string) ( $n['search_patterns'] ?? '' ),
			'role'            => $role,
			'target'          => (int) ( $n['target'] ?? 0 ),
			'key_page'        => (int) ( $n['key_page'] ?? 0 ),
			'noted'           => array() !== $n,
		);
	}

	/**
	 * Page of terms of one taxonomy (live), with notes.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $search   Name filter.
	 * @param int    $page     Page (1-based).
	 * @param int    $per_page Per page.
	 * @param string $filter   all|noted|empty|unscoped|retired.
	 * @return array{items: array[], total: int, pages: int, summary: array}
	 */
	public static function page( string $taxonomy, string $search = '', int $page = 1, int $per_page = 30, string $filter = 'all' ): array {
		if ( ! isset( self::taxonomies()[ $taxonomy ] ) ) {
			throw new RuntimeException( 'این نوع طبقه‌بندی در سایت فعال نیست.' );
		}
		$notes = self::notes();
		$args  = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
			'orderby'    => 'count',
			'order'      => 'DESC',
		);
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		$terms = get_terms( $args );
		$all   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( ! $t instanceof WP_Term ) {
				continue;
			}
			$e = self::entry( $t, $notes[ (int) $t->term_id ] ?? array() );
			if ( 'noted' === $filter && ! $e['noted'] ) {
				continue;
			}
			if ( 'empty' === $filter && $e['count'] > 0 ) {
				continue;
			}
			if ( 'unscoped' === $filter && ( '' !== $e['scope'] || ! in_array( $e['role'], self::ACTIVE_ROLES, true ) ) ) {
				continue;
			}
			if ( 'retired' === $filter && ! in_array( $e['role'], array( 'nav', 'retired' ), true ) ) {
				continue;
			}
			$all[] = $e;
		}
		$per_page = max( 10, min( 100, $per_page ) );
		$total    = count( $all );
		return array(
			'items'   => array_slice( $all, ( max( 1, $page ) - 1 ) * $per_page, $per_page ),
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per_page ),
			'summary' => self::summary( $taxonomy ),
		);
	}

	/**
	 * Counts for the tab header.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array{total: int, empty: int, scoped: int, active: int}
	 */
	public static function summary( string $taxonomy ): array {
		$notes = self::notes();
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0 ) );
		$out   = array( 'total' => 0, 'empty' => 0, 'scoped' => 0, 'active' => 0 );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( ! $t instanceof WP_Term ) {
				continue;
			}
			$n = $notes[ (int) $t->term_id ] ?? array();
			++$out['total'];
			if ( 0 === (int) $t->count ) {
				++$out['empty'];
			}
			if ( '' !== (string) ( $n['scope'] ?? '' ) ) {
				++$out['scoped'];
			}
			if ( in_array( self::role_of( $n ), self::ACTIVE_ROLES, true ) ) {
				++$out['active'];
			}
		}
		return $out;
	}

	/**
	 * Saves the owner's notes for one term (defaults are not stored).
	 *
	 * @param int   $term_id Term.
	 * @param array $patch   scope, search_patterns, role, target, key_page.
	 * @return array Updated entry.
	 * @throws RuntimeException On invalid input.
	 */
	public static function save( int $term_id, array $patch ): array {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term || ! isset( self::TAXONOMIES[ $term->taxonomy ] ) ) {
			throw new RuntimeException( 'دسته یا برچسب پیدا نشد.' );
		}
		$notes   = self::notes();
		$current = $notes[ $term_id ] ?? array( 'id' => $term_id );
		$merged  = array_merge( $current, array_intersect_key( $patch, array_flip( array( 'scope', 'search_patterns', 'role', 'target', 'key_page' ) ) ) );
		// «Navigation only» is a decision: such a term is not asked about when empty. A retired
		// term stays a candidate for merge or delete.
		if ( isset( $patch['role'] ) && 'nav' === $patch['role'] ) {
			$merged['decision']   = 'keep';
			$merged['decided_at'] = time();
		}
		$clean   = self::clean( $term, $merged );

		$default = self::is_default( $clean );
		if ( $default ) {
			unset( $notes[ $term_id ] );
		} else {
			$notes[ $term_id ] = $clean;
		}
		if ( count( $notes ) > self::MAX_NOTES ) {
			throw new RuntimeException( 'تعداد یادداشت‌ها از سقف گذشته است؛ برای برچسب‌های کم‌اهمیت یادداشت ننویسید.' );
		}
		ProfileRepository::save_user( array( 'categories_scope' => array_values( $notes ) ) );
		return self::entry( $term, $default ? array() : $clean );
	}

	/**
	 * Sanitizes notes for one term (used by the profile field sanitizer too).
	 *
	 * @param WP_Term $term Term.
	 * @param array   $raw  Raw notes.
	 */
	public static function clean( WP_Term $term, array $raw ): array {
		$role = self::role_of( $raw );
		$page = (int) ( $raw['key_page'] ?? 0 );
		$out  = array(
			'id'              => (int) $term->term_id,
			'taxonomy'        => $term->taxonomy,
			'name'            => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'count'           => (int) $term->count,
			'scope'           => mb_substr( sanitize_textarea_field( (string) ( $raw['scope'] ?? '' ) ), 0, 500 ),
			'search_patterns' => mb_substr( sanitize_text_field( (string) ( $raw['search_patterns'] ?? '' ) ), 0, 300 ),
			'role'            => $role,
			// Kept for code that still reads «importance» (structure targets).
			'importance'      => in_array( $role, self::ACTIVE_ROLES, true ) ? $role : 'normal',
			'target'          => max( 0, min( 500, (int) ( $raw['target'] ?? 0 ) ) ),
			'key_page'        => $page > 0 && 'publish' === get_post_status( $page ) ? $page : 0,
		);
		// The owner's decision on an empty / thin term (see TermDecisions).
		if ( in_array( $raw['decision'] ?? '', array( 'keep', 'later' ), true ) ) {
			$out['decision']   = $raw['decision'];
			$out['decided_at'] = (int) ( $raw['decided_at'] ?? 0 );
			$out['until']      = (int) ( $raw['until'] ?? 0 );
		}
		if ( ! empty( $raw['pp_noindex'] ) ) {
			$out['pp_noindex'] = 1;
		}
		return $out;
	}

	/**
	 * Role from stored notes (older notes only have «importance»).
	 *
	 * @param array $n Notes.
	 */
	public static function role_of( array $n ): string {
		$role = (string) ( $n['role'] ?? ( $n['importance'] ?? 'normal' ) );
		return isset( self::ROLES[ $role ] ) ? $role : 'normal';
	}

	/**
	 * Role of a term id.
	 *
	 * @param int $term_id Term.
	 */
	public static function role( int $term_id ): string {
		return self::role_of( self::notes()[ $term_id ] ?? array() );
	}

	/**
	 * Tags a new article may use: active tags, the most used first (those of the given
	 * category first). Retired / navigation tags are never offered.
	 *
	 * @param int $category_id Category of the article (0: any).
	 * @param int $limit       Maximum.
	 * @return array<int, array{id: int, name: string, count: int, scope: string}>
	 */
	public static function allowed_tags( int $category_id = 0, int $limit = 40, string $tax = 'post_tag' ): array {
		global $wpdb;
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$cat_tax = 'product_tag' === $tax ? 'product_cat' : 'category';
		$notes = self::notes();
		$boost = array();
		if ( $category_id > 0 ) {
			// Tags used together with this category.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt2.term_id, COUNT(*) AS n
					FROM {$wpdb->term_relationships} r1
					JOIN {$wpdb->term_taxonomy} tt1 ON tt1.term_taxonomy_id = r1.term_taxonomy_id AND tt1.taxonomy = %s AND tt1.term_id = %d
					JOIN {$wpdb->term_relationships} r2 ON r2.object_id = r1.object_id
					JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = r2.term_taxonomy_id AND tt2.taxonomy = %s
					GROUP BY tt2.term_id ORDER BY n DESC LIMIT 200",
					$cat_tax,
					$category_id,
					$tax
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$boost[ (int) $r['term_id'] ] = (int) $r['n'];
			}
		}
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 500, 'orderby' => 'count', 'order' => 'DESC' ) );
		$list  = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$n    = $notes[ (int) $t->term_id ] ?? array();
			$role = self::role_of( $n );
			if ( ! in_array( $role, self::ACTIVE_ROLES, true ) ) {
				continue;
			}
			$weight = ( $boost[ (int) $t->term_id ] ?? 0 ) * 10 + (int) $t->count + ( 'high' === $role ? 1000 : 0 ) + ( array() !== $n ? 50 : 0 );
			$list[] = array(
				'id'     => (int) $t->term_id,
				'name'   => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'count'  => (int) $t->count,
				'scope'  => (string) ( $n['scope'] ?? '' ),
				'weight' => $weight,
			);
		}
		usort( $list, static fn( $a, $b ) => $b['weight'] <=> $a['weight'] );
		return array_map(
			static function ( $x ) {
				unset( $x['weight'] );
				return $x;
			},
			array_slice( $list, 0, $limit )
		);
	}

	/**
	 * Whether notes equal the defaults (then nothing is stored).
	 *
	 * @param array $c Clean notes.
	 */
	private static function is_default( array $c ): bool {
		return '' === $c['scope'] && '' === $c['search_patterns'] && 'normal' === $c['role'] && 0 === $c['target'] && 0 === $c['key_page'] && empty( $c['decision'] ) && empty( $c['pp_noindex'] );
	}

	/**
	 * One-time move of the old «approved tags» list into the map.
	 */
	private static function migrate(): void {
		if ( get_option( self::MIGRATED ) ) {
			return;
		}
		update_option( self::MIGRATED, 1, false );

		$approved = trim( (string) ProfileRepository::value( 'approved_tags' ) );
		if ( '' === $approved || ! taxonomy_exists( 'post_tag' ) ) {
			return;
		}
		$notes = array();
		foreach ( (array) ProfileRepository::value( 'categories_scope' ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$notes[ (int) $item['id'] ] = $item;
			}
		}
		$added = 0;
		foreach ( preg_split( '/[،,\n]+/u', $approved ) as $name ) {
			$term = get_term_by( 'name', trim( $name ), 'post_tag' );
			if ( $term instanceof WP_Term && ! isset( $notes[ (int) $term->term_id ] ) ) {
				$notes[ (int) $term->term_id ] = self::clean( $term, array( 'role' => 'high' ) );
				++$added;
			}
		}
		if ( $added > 0 ) {
			ProfileRepository::save_user( array( 'categories_scope' => array_values( $notes ) ) );
		}
	}
}
