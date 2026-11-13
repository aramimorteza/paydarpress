<?php
/**
 * Content structure: how many articles each category should have (by importance and site
 * stage) and how many supporting articles each service/landing/product key page has.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use PaydarWP\PaydarPress\Links\KeyPageLinks;
use PaydarWP\PaydarPress\Policy\SiteStage;
use PaydarWP\PaydarPress\Profile\ProfileRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Targets are guidance for topics and weekly tasks; nothing here changes the site.
 */
final class Structure {

	/** Articles per category at "normal" importance, by stage. */
	public const BASE_TARGET = array(
		'new'     => 5,
		'growing' => 10,
		'mature'  => 15,
	);

	public const IMPORTANCE_FACTOR = array(
		'high'   => 2.0,
		'normal' => 1.0,
		'low'    => 0.5,
	);

	public const IMPORTANCE_LABELS = array(
		'high'   => 'مهم',
		'normal' => 'عادی',
		'low'    => 'کم‌اهمیت',
	);

	/** Supporting articles a money page (service, landing, product, category) should have. */
	public const CLUSTER_TARGET = array(
		'new'     => 3,
		'growing' => 5,
		'mature'  => 8,
	);

	public const PAGE_TYPES = array(
		'service'  => 'صفحه خدمت',
		'landing'  => 'صفحه فرود',
		'product'  => 'محصول اصلی',
		'category' => 'دسته اصلی',
		'contact'  => 'تماس',
		'about'    => 'درباره ما',
		'other'    => 'سایر',
	);

	/** Key page types that need a cluster of supporting articles. */
	public const CLUSTER_TYPES = array( 'service', 'landing', 'product', 'category' );

	/**
	 * Blog categories with importance, target and gap; largest weighted gap first.
	 *
	 * @return array<int, array{id: int, name: string, count: int, importance: string, target: int, gap: int, impressions: int}>
	 */
	public static function categories(): array {
		$stage  = SiteStage::current();
		$base   = self::BASE_TARGET[ $stage ];
		$scopes      = \PaydarWP\PaydarPress\Profile\TaxonomyMap::notes();
		$impressions = self::impressions_by_category();

		$out = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 200 ) ) as $t ) {
			if ( ! $t instanceof \WP_Term || 'uncategorized' === $t->slug ) {
				continue;
			}
			$notes = $scopes[ $t->term_id ] ?? array();
			$role  = \PaydarWP\PaydarPress\Profile\TaxonomyMap::role_of( $notes );
			// Navigation-only and retired categories get no content target.
			if ( ! in_array( $role, \PaydarWP\PaydarPress\Profile\TaxonomyMap::ACTIVE_ROLES, true ) ) {
				continue;
			}
			$importance = $role;
			$target     = (int) ( $notes['target'] ?? 0 ) > 0 ? (int) $notes['target'] : (int) max( 2, round( $base * self::IMPORTANCE_FACTOR[ $importance ] ) );
			$out[]      = array(
				'id'          => (int) $t->term_id,
				'name'        => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'count'       => (int) $t->count,
				'importance'  => $importance,
				'target'      => $target,
				'gap'         => max( 0, $target - (int) $t->count ),
				'impressions' => (int) ( $impressions[ $t->term_id ] ?? 0 ),
				'scope'       => (string) ( $notes['scope'] ?? '' ),
			);
		}
		usort( $out, static fn( $a, $b ) => array( $b['gap'] * self::IMPORTANCE_FACTOR[ $b['importance'] ], $b['impressions'] ) <=> array( $a['gap'] * self::IMPORTANCE_FACTOR[ $a['importance'] ], $a['impressions'] ) );
		return $out;
	}

	/**
	 * Key pages that need supporting articles, with how many they have.
	 *
	 * @return array<int, array{id: int, name: string, type: string, main_keyword: string, supports: int, target: int, gap: int}>
	 */
	public static function clusters(): array {
		global $wpdb;
		$target = self::CLUSTER_TARGET[ SiteStage::current() ];
		$pages  = KeyPageLinks::pages();
		if ( ! $pages ) {
			return array();
		}
		$links = Schema::table( 'links' );
		$inv   = Schema::table( 'inventory' );
		$ids   = implode( ',', array_map( 'intval', array_keys( $pages ) ) );
		// Supporting article = a published post that links to the page.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT l.target_id, COUNT(DISTINCT l.source_id) AS n FROM {$links} l JOIN {$inv} i ON i.object_id = l.source_id AND i.object_type = 'post' AND i.status = 'publish' WHERE l.target_id IN ({$ids}) AND l.source_type = 'content' GROUP BY l.target_id", ARRAY_A );
		$supports = array();
		foreach ( (array) $rows as $r ) {
			$supports[ (int) $r['target_id'] ] = (int) $r['n'];
		}

		$out = array();
		foreach ( $pages as $id => $page ) {
			$type = self::type_of( (int) $id, $page );
			if ( ! in_array( $type, self::CLUSTER_TYPES, true ) || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$n     = $supports[ $id ] ?? 0;
			$out[] = array(
				'id'           => (int) $id,
				'name'         => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'          => (string) get_permalink( $id ),
				'type'         => $type,
				'main_keyword' => (string) ( $page['main_keyword'] ?? '' ),
				'priority'     => (string) ( $page['priority'] ?? 'normal' ),
				'supports'     => $n,
				'target'       => $target,
				'gap'          => max( 0, $target - $n ),
			);
		}
		usort( $out, static fn( $a, $b ) => array( 'high' === $b['priority'], $b['gap'] ) <=> array( 'high' === $a['priority'], $a['gap'] ) );
		return $out;
	}

	/**
	 * Page type of a key page; older profiles have none stored, so it is guessed.
	 *
	 * @param int   $id   Page.
	 * @param array $page Key page entry.
	 */
	public static function type_of( int $id, array $page ): string {
		if ( ! empty( $page['page_type'] ) && isset( self::PAGE_TYPES[ $page['page_type'] ] ) ) {
			return (string) $page['page_type'];
		}
		$post = get_post( $id );
		return $post instanceof \WP_Post ? ProfileRepository::guess_page_type( $post ) : 'other';
	}

	/**
	 * Search Console impressions per category (sum over its posts).
	 *
	 * @return array<int, int>
	 */
	private static function impressions_by_category(): array {
		if ( ! GscRepository::has_data() ) {
			return array();
		}
		global $wpdb;
		$inv = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_id, primary_term_id FROM {$inv} WHERE status = 'publish' AND object_type = 'post' AND primary_term_id IS NOT NULL", ARRAY_A );
		$m    = GscRepository::page_metrics();
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['primary_term_id'] ] = ( $out[ (int) $r['primary_term_id'] ] ?? 0 ) + (int) ( $m[ (int) $r['object_id'] ]['impressions'] ?? 0 );
		}
		return $out;
	}
}
