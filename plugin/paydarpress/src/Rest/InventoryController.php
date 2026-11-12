<?php
/**
 * REST: content inventory list and details.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints:
 *  GET /paydarpress/v1/inventory
 *  GET /paydarpress/v1/inventory/{id}
 */
final class InventoryController {

	private const SORTABLE = array( 'title_norm', 'word_count', 'internal_in', 'internal_out', 'refresh_score', 'modified_at', 'published_at' );

	private const FILTERS = array(
		'orphan'     => 'i.internal_in = 0',
		'no_links'   => 'i.internal_out = 0',
		'no_keyword' => 'i.focus_keyword IS NULL',
		'no_meta'    => 'i.meta_description IS NULL',
		'no_image'   => 'i.has_featured_image = 0',
		'alt'        => '(i.inline_images_no_alt > 0 OR (i.has_featured_image = 1 AND i.featured_alt_ok = 0))',
		'noindex'    => 'i.is_noindex = 1',
		'cornerstone' => 'i.is_cornerstone = 1',
	);

	/**
	 * Registers routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		register_rest_route(
			UserPrefsController::NAMESPACE,
			'/inventory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::VIEW ),
				'args'                => array(
					'type'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'   => array(
						'type'    => 'string',
						'default' => 'publish',
					),
					'filter'   => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), array_keys( self::FILTERS ) ),
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'modified_at',
						'enum'    => self::SORTABLE,
					),
					'order'    => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			UserPrefsController::NAMESPACE,
			'/inventory/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => static fn() => current_user_can( Capabilities::VIEW ),
			)
		);
	}

	/**
	 * Paginated inventory list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table = Schema::table( 'inventory' );
		$where = array( '1=1' );
		$args  = array();

		$type = (string) $request->get_param( 'type' );
		if ( '' !== $type ) {
			$where[] = 'i.object_type = %s';
			$args[]  = $type;
		}

		$status = (string) $request->get_param( 'status' );
		if ( 'all' !== $status ) {
			$where[] = 'i.status = %s';
			$args[]  = '' === $status ? 'publish' : $status;
		}

		$filter = (string) $request->get_param( 'filter' );
		if ( isset( self::FILTERS[ $filter ] ) ) {
			$where[] = self::FILTERS[ $filter ];
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$where[] = '(i.title_norm LIKE %s OR i.focus_keyword_norm LIKE %s)';
			$like    = '%' . $wpdb->esc_like( PersianNormalizer::for_key( $search ) ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}

		$orderby  = in_array( $request->get_param( 'orderby' ), self::SORTABLE, true ) ? $request->get_param( 'orderby' ) : 'modified_at';
		$order    = 'asc' === $request->get_param( 'order' ) ? 'ASC' : 'DESC';
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} i WHERE {$where_sql}";
		$list_sql  = "SELECT i.* FROM {$table} i WHERE {$where_sql} ORDER BY i.{$orderby} {$order}, i.object_id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		$items = array_map( array( $this, 'format' ), (array) $rows );

		// Search Console metrics for the listed items (only when data was imported).
		$gsc = \PaydarWP\PaydarPress\Gsc\GscRepository::has_data() ? \PaydarWP\PaydarPress\Gsc\GscRepository::page_metrics() : null;
		if ( null !== $gsc ) {
			foreach ( $items as &$item ) {
				$item['gsc'] = $gsc[ (int) $item['id'] ] ?? array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );
			}
			unset( $item );
		}

		$response = new WP_REST_Response(
			array(
				'items'       => $items,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
				'counts'      => $this->counts(),
			)
		);

		return $response;
	}

	/**
	 * One item with its inbound and outbound links.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request['id'];
		$table = Schema::table( 'inventory' );
		$links = Schema::table( 'links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'paydarpress_not_found', 'این محتوا در فهرست پایدارپرس نیست.', array( 'status' => 404 ) );
		}

		$outbound = $wpdb->get_results( $wpdb->prepare( "SELECT target_id, target_url, anchor, link_type, target_state FROM {$links} WHERE source_type = 'content' AND source_id = %d ORDER BY id", $id ), ARRAY_A );
		$inbound  = $wpdb->get_results( $wpdb->prepare( "SELECT l.source_id, l.source_type, l.anchor, i.url FROM {$links} l LEFT JOIN {$table} i ON i.object_id = l.source_id AND l.source_type = 'content' WHERE l.target_id = %d AND l.link_type = 'internal' ORDER BY l.source_type, l.source_id", $id ), ARRAY_A );
		// phpcs:enable

		$item             = $this->format( $row );
		$item['outline']  = json_decode( (string) $row['outline'], true ) ?: array();
		$item['outbound'] = array_map(
			static function ( array $link ): array {
				return array(
					'target_id' => null === $link['target_id'] ? null : (int) $link['target_id'],
					'url'       => $link['target_url'],
					'anchor'    => $link['anchor'],
					'type'      => $link['link_type'],
					'state'     => $link['target_state'],
				);
			},
			(array) $outbound
		);
		$item['inbound']  = array_map(
			static function ( array $link ): array {
				$is_menu = 'menu' === $link['source_type'];
				return array(
					'source_id'   => (int) $link['source_id'],
					'source_type' => $link['source_type'],
					'title'       => $is_menu ? wp_get_nav_menu_object( (int) $link['source_id'] )->name ?? '' : get_the_title( (int) $link['source_id'] ),
					'url'         => $link['url'],
					'anchor'      => $link['anchor'],
				);
			},
			(array) $inbound
		);

		return new WP_REST_Response( $item );
	}

	/**
	 * API representation of an inventory row.
	 *
	 * @param array $row DB row.
	 */
	private function format( array $row ): array {
		$id = (int) $row['object_id'];

		return array(
			'id'                 => $id,
			'type'               => $row['object_type'],
			'status'             => $row['status'],
			'title'              => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'url'                => $row['url'],
			'edit_url'           => get_edit_post_link( $id, 'raw' ),
			'primary_term'       => $row['primary_term_id'] ? $this->term_name( (int) $row['primary_term_id'] ) : null,
			'focus_keyword'      => $row['focus_keyword'],
			'has_meta'           => null !== $row['meta_description'],
			'noindex'            => (bool) (int) $row['is_noindex'],
			'cornerstone'        => (bool) (int) $row['is_cornerstone'],
			'word_count'         => (int) $row['word_count'],
			'h2_count'           => (int) $row['h2_count'],
			'internal_in'        => (int) $row['internal_in'],
			'menu_in'            => (int) $row['menu_in'],
			'internal_out'       => (int) $row['internal_out'],
			'external_out'       => (int) $row['external_out'],
			'featured_image'     => (bool) (int) $row['has_featured_image'],
			'featured_alt_ok'    => (bool) (int) $row['featured_alt_ok'],
			'inline_images'      => (int) $row['inline_images'],
			'inline_missing_alt' => (int) $row['inline_images_no_alt'],
			'direct_answer'      => (bool) (int) $row['has_direct_answer'],
			'years'              => json_decode( (string) $row['years_mentioned'], true ) ?: array(),
			'refresh_score'      => (int) $row['refresh_score'],
			'author'             => get_the_author_meta( 'display_name', (int) $row['author_id'] ),
			'published_at'       => ScanController::iso( $row['published_at'] ),
			'modified_at'        => ScanController::iso( $row['modified_at'] ),
		);
	}

	/**
	 * Term name by ID (cached per request).
	 *
	 * @param int $term_id Term ID.
	 */
	private function term_name( int $term_id ): ?string {
		static $cache = array();

		if ( ! array_key_exists( $term_id, $cache ) ) {
			$term              = get_term( $term_id );
			$cache[ $term_id ] = $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : null;
		}

		return $cache[ $term_id ];
	}

	/**
	 * Counts for filter tabs (published content only).
	 */
	private function counts(): array {
		global $wpdb;

		$table  = Schema::table( 'inventory' );
		$select = array( 'COUNT(*) AS total' );
		foreach ( self::FILTERS as $key => $condition ) {
			$select[] = "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS {$key}";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( 'SELECT ' . implode( ', ', $select ) . " FROM {$table} i WHERE i.status = 'publish'", ARRAY_A );

		return array_map( 'intval', is_array( $row ) ? $row : array() );
	}
}
