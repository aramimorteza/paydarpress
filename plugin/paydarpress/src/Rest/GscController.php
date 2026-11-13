<?php
/**
 * REST: Search Console import and data.
 *
 *  GET  /gsc               summary of the current data set (view)
 *  POST /gsc/import        multipart upload: files[] (zip or CSV) (manage)
 *  POST /gsc/clear         delete imported data (manage)
 *  GET  /gsc/pages         ?tab=top|near|ctr|drop|absent|unmatched&page (view)
 *  GET  /gsc/queries       ?page&search (view)
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscImporter;
use PaydarWP\PaydarPress\Gsc\GscInsights;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console controller.
 */
final class GscController {

	public const NAMESPACE = 'paydarpress/v1';

	/** Tabs backed by audit findings. */
	private const FINDING_TABS = array(
		'near'   => 'GSC_NEAR_PAGE_ONE',
		'ctr'    => 'GSC_LOW_CTR',
		'drop'   => 'GSC_CLICK_DROP',
		'absent' => 'GSC_NOT_IN_SEARCH',
	);

	/**
	 * Routes.
	 */
	public function routes(): void {
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		$view   = static fn() => current_user_can( Capabilities::VIEW );
		$paging = array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 25,
				'minimum' => 1,
				'maximum' => 100,
			),
		);

		register_rest_route( self::NAMESPACE, '/gsc', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'summary' ), 'permission_callback' => $view ) );
		register_rest_route( self::NAMESPACE, '/gsc/insights', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'insights' ), 'permission_callback' => $view ) );
		register_rest_route(
			self::NAMESPACE,
			'/gsc/gaps',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'gaps' ),
				'permission_callback' => $view,
				'args'                => $paging + array(
					'status' => array(
						'type'    => 'string',
						'enum'    => array( 'open', 'gap', 'partial', 'planned', 'all' ),
						'default' => 'open',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/gsc/gaps/topic',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'gap_topic' ),
				'permission_callback' => $manage,
				'args'                => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route( self::NAMESPACE, '/gsc/import', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'import' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NAMESPACE, '/gsc/notfound', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'notfound' ), 'permission_callback' => $view ) );
		register_rest_route( self::NAMESPACE, '/gsc/notfound/(?P<id>\\d+)/(?P<op>redirect|restore|ignore|reopen)', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'notfound_act' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NAMESPACE, '/gsc/clear', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'clear' ), 'permission_callback' => $manage ) );
		register_rest_route(
			self::NAMESPACE,
			'/gsc/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pages' ),
				'permission_callback' => $view,
				'args'                => $paging + array(
					'tab' => array(
						'type'    => 'string',
						'default' => 'top',
						'enum'    => array( 'top', 'near', 'ctr', 'drop', 'absent', 'unmatched' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/gsc/queries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'queries' ),
				'permission_callback' => $view,
				'args'                => $paging + array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Summary.
	 */
	public function summary(): WP_REST_Response {
		return new WP_REST_Response( $this->summary_payload() );
	}

	/**
	 * Upload and import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$uploads = $this->uploads( $request->get_file_params() );

		if ( array() === $uploads ) {
			return new WP_Error( 'paydarpress_gsc_no_file', 'فایلی دریافت نشد. فایل zip خروجی سرچ کنسول را انتخاب کنید.', array( 'status' => 400 ) );
		}

		try {
			$result = ( new GscImporter() )->import_uploads( $uploads );
		} catch ( Throwable $e ) {
			return new WP_Error( 'paydarpress_gsc_import', $e->getMessage(), array( 'status' => 422 ) );
		}

		$notfound = 'notfound' === ( $result['type'] ?? '' ) ? $result : ( $result['notfound'] ?? null );
		return new WP_REST_Response(
			$this->summary_payload() + array(
				'performance_imported' => 'notfound' !== ( $result['type'] ?? '' ),
				'notfound_result'      => $notfound,
			)
		);
	}

	/**
	 * Search Console 404 addresses.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function notfound( WP_REST_Request $request ): WP_REST_Response {
		$view = sanitize_key( (string) ( $request->get_param( 'view' ) ?: 'todo' ) );
		return new WP_REST_Response(
			array(
				'items'  => \PaydarWP\PaydarPress\Gsc\NotFound::list( $view ),
				'counts' => \PaydarWP\PaydarPress\Gsc\NotFound::counts(),
				'owner'  => \PaydarWP\PaydarPress\Strategy\RedirectOwner::detect(),
				'view'   => $view,
			)
		);
	}

	/**
	 * Decision on one 404 address.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function notfound_act( WP_REST_Request $request ) {
		try {
			$r = \PaydarWP\PaydarPress\Gsc\NotFound::act( (int) $request['id'], (string) $request['op'], (int) $request->get_param( 'target' ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'paydarpress_notfound', $e->getMessage(), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $r + array( 'counts' => \PaydarWP\PaydarPress\Gsc\NotFound::counts() ) );
	}

	/**
	 * Deletes imported data.
	 */
	public function clear(): WP_REST_Response {
		GscRepository::clear();
		( new AuditEngine() )->run( false );
		Activity::log( 'import.gsc_cleared', 'داده سرچ کنسول حذف شد.', 'import', 0 );

		return new WP_REST_Response( $this->summary_payload() );
	}

	/**
	 * Page lists.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$tab      = (string) $request->get_param( 'tab' );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$offset   = ( $page - 1 ) * $per_page;

		if ( 'unmatched' === $tab ) {
			$all   = GscRepository::unmatched( 1000 );
			$items = array_map( array( $this, 'unmatched_item' ), array_slice( $all, $offset, $per_page ) );
			return $this->paged( $items, count( $all ), $page, $per_page );
		}

		$metrics = GscRepository::page_metrics();

		if ( 'top' === $tab ) {
			uasort( $metrics, static fn( $a, $b ) => array( $b['clicks'], $b['impressions'] ) <=> array( $a['clicks'], $a['impressions'] ) );
			$slice = array_slice( $metrics, $offset, $per_page, true );
			$items = array();
			foreach ( $slice as $id => $m ) {
				$items[] = $this->content_item( (int) $id, $m );
			}
			return $this->paged( $items, count( $metrics ), $page, $per_page );
		}

		global $wpdb;
		$table = Schema::table( 'audit_findings' );
		$code  = self::FINDING_TABS[ $tab ];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE code = %s AND state IN ('open', 'reviewed')", $code ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$table} WHERE code = %s AND state IN ('open', 'reviewed') ORDER BY id LIMIT 2000", $code ) );
		// phpcs:enable

		// Order by opportunity: impressions for "near" and "ctr", lost clicks for "drop".
		$ids = array_map( 'intval', (array) $ids );
		usort(
			$ids,
			static function ( $a, $b ) use ( $metrics, $tab ) {
				$ma = $metrics[ $a ] ?? array( 'impressions' => 0, 'clicks' => 0, 'prev_clicks' => 0 );
				$mb = $metrics[ $b ] ?? array( 'impressions' => 0, 'clicks' => 0, 'prev_clicks' => 0 );
				if ( 'drop' === $tab ) {
					return ( (int) $mb['prev_clicks'] - $mb['clicks'] ) <=> ( (int) $ma['prev_clicks'] - $ma['clicks'] );
				}
				return $mb['impressions'] <=> $ma['impressions'];
			}
		);

		$items = array();
		foreach ( array_slice( $ids, $offset, $per_page ) as $id ) {
			$items[] = $this->content_item( $id, $metrics[ $id ] ?? null );
		}

		return $this->paged( $items, $total, $page, $per_page );
	}

	/**
	 * Profile suggestions from the queries.
	 */
	public function insights(): WP_REST_Response {
		return new WP_REST_Response( GscInsights::profile() );
	}

	/**
	 * Searches with no matching article.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function gaps( WP_REST_Request $request ): WP_REST_Response {
		$all    = GscInsights::gaps();
		$status = (string) $request->get_param( 'status' );
		$counts = array_count_values( array_column( $all, 'status' ) );
		$items  = array_values(
			array_filter(
				$all,
				static fn( $g ) => 'all' === $status || ( 'open' === $status ? 'planned' !== $g['status'] : $status === $g['status'] )
			)
		);

		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$response = $this->paged( array_slice( $items, ( $page - 1 ) * $per_page, $per_page ), count( $items ), $page, $per_page );
		$data           = $response->get_data();
		$data['counts'] = array(
			'gap'     => (int) ( $counts['gap'] ?? 0 ),
			'partial' => (int) ( $counts['partial'] ?? 0 ),
			'planned' => (int) ( $counts['planned'] ?? 0 ),
		);
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Adds a searched query as a suggested topic.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function gap_topic( WP_REST_Request $request ) {
		$query = trim( (string) $request->get_param( 'query' ) );
		$row   = null;
		foreach ( GscInsights::queries() as $q ) {
			if ( $q['query'] === $query ) {
				$row = $q;
				break;
			}
		}
		if ( null === $row ) {
			return new WP_Error( 'paydarpress_not_found', 'این عبارت در داده سرچ کنسول نیست.', array( 'status' => 404 ) );
		}

		$result = GscInsights::add_topic( $row['query'], $row['impressions'], $row['position'] );
		if ( $result['created'] ) {
			Activity::log( 'import.topic_from_gsc', 'جست‌وجوی «' . $row['query'] . '» به موضوعات پیشنهادی اضافه شد.' );
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * Search queries.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function queries( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$result   = GscRepository::queries( $per_page, ( $page - 1 ) * $per_page, (string) $request->get_param( 'search' ) );

		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	/**
	 * Summary payload.
	 */
	private function summary_payload(): array {
		$import = GscRepository::latest_import();
		$stats  = get_option( AuditEngine::OPTION_STATS, array() );
		$codes  = is_array( $stats ) ? (array) ( $stats['by_code'] ?? array() ) : array();

		return array(
			'import'        => null === $import ? null : array(
				'id'          => (int) $import['id'],
				'file_name'   => $import['file_name'],
				'imported_at' => ScanController::iso( $import['created_at'] ),
				'summary'     => $import['summary'],
			),
			'stale'         => GscRepository::is_stale(),
			'days_since'    => GscRepository::days_since(),
			'needs_refresh' => GscRepository::needs_refresh(),
			'counts'        => array(
				'near'   => (int) ( $codes['GSC_NEAR_PAGE_ONE'] ?? 0 ),
				'ctr'    => (int) ( $codes['GSC_LOW_CTR'] ?? 0 ),
				'drop'   => (int) ( $codes['GSC_CLICK_DROP'] ?? 0 ),
				'absent' => (int) ( $codes['GSC_NOT_IN_SEARCH'] ?? 0 ),
			),
			'max_upload'    => min( GscImporter::MAX_UPLOAD_BYTES, wp_max_upload_size() ),
			'zip_supported' => class_exists( '\ZipArchive' ) || file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ),
		);
	}

	/**
	 * Normalised list of uploaded files from $_FILES-like params (single or multiple).
	 *
	 * @param array $params File params.
	 * @return array<int, array{name:string, tmp_name:string, size:int}>
	 */
	private function uploads( array $params ): array {
		$out = array();

		foreach ( $params as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['tmp_name'] ) ) {
				continue;
			}
			$names = (array) $field['name'];
			$tmps  = (array) $field['tmp_name'];
			$sizes = (array) $field['size'];
			$errs  = (array) ( $field['error'] ?? array() );

			foreach ( $tmps as $i => $tmp ) {
				if ( UPLOAD_ERR_OK !== (int) ( $errs[ $i ] ?? UPLOAD_ERR_OK ) || ! is_string( $tmp ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
					continue;
				}
				$out[] = array(
					'name'     => (string) ( $names[ $i ] ?? 'file.csv' ),
					'tmp_name' => $tmp,
					'size'     => (int) ( $sizes[ $i ] ?? 0 ),
				);
			}
		}

		return array_slice( $out, 0, 10 );
	}

	/**
	 * Content item with metrics.
	 *
	 * @param int        $id Object ID.
	 * @param array|null $m  Metrics.
	 */
	private function content_item( int $id, ?array $m ): array {
		return array(
			'id'       => $id,
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'type'     => (string) get_post_type( $id ),
			'url'      => (string) get_permalink( $id ),
			'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			'metrics'  => $m,
		);
	}

	/**
	 * Unmatched URL with a hint of what to do.
	 *
	 * @param array $row Row.
	 */
	private function unmatched_item( array $row ): array {
		$target = null;
		if ( null !== $row['object_id'] ) {
			$target = array(
				'id'    => $row['object_id'],
				'title' => html_entity_decode( get_the_title( $row['object_id'] ), ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( $row['object_id'] ),
			);
		}

		return array(
			'url'     => $row['item'],
			'state'   => $row['match_state'],
			'target'  => $target,
			'metrics' => array_intersect_key( $row, array_flip( array( 'clicks', 'impressions', 'ctr', 'position', 'prev_clicks', 'prev_impressions', 'prev_position' ) ) ),
		);
	}

	/**
	 * Paged response.
	 *
	 * @param array $items    Items.
	 * @param int   $total    Total.
	 * @param int   $page     Page.
	 * @param int   $per_page Per page.
	 */
	private function paged( array $items, int $total, int $page, int $per_page ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'items'       => array_values( $items ),
				'total'       => $total,
				'page'        => $page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}
}
