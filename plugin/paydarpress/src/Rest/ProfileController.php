<?php
/**
 * REST: site profile, inference, export and onboarding.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Export\InventoryExporter;
use PaydarWP\PaydarPress\Links\KeyPageLinks;
use PaydarWP\PaydarPress\Profile\Fields;
use PaydarWP\PaydarPress\Profile\ProfileInference;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Scanner\ScanManager;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints:
 *  GET  /profile            profile, field definitions, completion, choices (pages, categories)
 *  POST /profile            { values: {field: value}, wizard_done?: bool }
 *  POST /profile/infer
 *  GET  /taxmap             { taxonomy, search, page, filter } terms with the owner's notes
 *  POST /taxmap/{id}        { scope, search_patterns, role, target, key_page }
 *  GET  /export             last export info
 *  POST /export             { include_drafts, compact }
 *  GET  /onboarding         first-run checklist
 * Download: admin-post.php?action=paydarpress_download&token=…&_wpnonce=…
 */
final class ProfileController {

	public const DOWNLOAD_ACTION = 'paydarpress_download';

	/** @var ScanManager */
	private $scans;

	/**
	 * @param ScanManager $scans Scan manager.
	 */
	public function __construct( ScanManager $scans ) {
		$this->scans = $scans;
	}

	/**
	 * Registers routes and the download handler.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'download' ) );
	}

	/**
	 * Route definitions.
	 */
	public function routes(): void {
		$ns     = UserPrefsController::NAMESPACE;
		$view   = static fn() => current_user_can( Capabilities::VIEW );
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		$export = static fn() => current_user_can( Capabilities::EXPORT );

		register_rest_route(
			$ns,
			'/profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => $view,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_profile' ),
					'permission_callback' => $manage,
					'args'                => array(
						'values'      => array( 'type' => 'object', 'default' => array() ),
						'wizard_done' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			)
		);

		register_rest_route( $ns, '/profile/key-pages/stats', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'key_page_stats' ), 'permission_callback' => $view ) );
		register_rest_route(
			$ns,
			'/profile/key-pages/(?P<id>\\d+)/anchors',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'anchor_suggestions' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route( $ns, '/taxmap', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'taxmap' ), 'permission_callback' => $view ) );
		register_rest_route( $ns, '/taxmap/confirm', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'taxmap_confirm' ), 'permission_callback' => $manage ) );
		register_rest_route( $ns, '/taxmap/(?P<id>\\d+)', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'taxmap_save' ), 'permission_callback' => $manage ) );
		register_rest_route( $ns, '/profile/infer', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'infer' ), 'permission_callback' => $manage ) );

		register_rest_route(
			$ns,
			'/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_export' ),
					'permission_callback' => $export,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_export' ),
					'permission_callback' => $export,
					'args'                => array( 'include_drafts' => array( 'type' => 'boolean', 'default' => false ) ),
				),
			)
		);

		register_rest_route( $ns, '/onboarding', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'onboarding' ), 'permission_callback' => $view ) );
		register_rest_route( $ns, '/onboarding/mark', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'onboarding_mark' ), 'permission_callback' => $manage ) );
	}

	/**
	 * Profile with definitions and choices for the UI.
	 */
	public function get_profile(): WP_REST_Response {
		return new WP_REST_Response( $this->profile_payload() );
	}

	/**
	 * Saves user values.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_profile( WP_REST_Request $request ): WP_REST_Response {
		$values = (array) $request->get_param( 'values' );
		ProfileRepository::save_user( $values );

		if ( $request->get_param( 'wizard_done' ) ) {
			$this->set_onboarding( 'wizard_done', true );
			Activity::log( 'profile.wizard', 'ویزارد راه‌اندازی پروفایل سایت تکمیل شد.', 'profile', 0 );
		}

		// Key pages affect the WEAK_INBOUND rule.
		if ( array_key_exists( 'key_pages', $values ) && function_exists( 'as_enqueue_async_action' ) ) {
			\PaydarWP\PaydarPress\Queue\Queue::schedule( ScanManager::ACTION_AUDIT, array(), 30 );
		}

		return new WP_REST_Response( $this->profile_payload() );
	}

	/**
	 * Re-runs detection and inference (keeps confirmed fields).
	 */
	public function infer(): WP_REST_Response {
		ProfileInference::run();

		return new WP_REST_Response( $this->profile_payload() );
	}

	/**
	 * Last export info.
	 */
	public function get_export(): WP_REST_Response {
		return new WP_REST_Response( $this->export_payload() );
	}

	/**
	 * Creates an export file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_export( WP_REST_Request $request ) {
		$latest = $this->scans->latest_scan();
		if ( null === $latest || 'completed' !== $latest['status'] ) {
			return new WP_Error( 'paydarpress_no_scan', 'ابتدا یک اسکن کامل از سایت انجام دهید.', array( 'status' => 409 ) );
		}

		try {
			( new InventoryExporter() )->create_file( (bool) $request->get_param( 'include_drafts' ), (bool) $request->get_param( 'compact' ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'paydarpress_export', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->export_payload() );
	}

	/**
	 * Streams the last export file (nonce + capability protected).
	 */
	public function download(): void {
		if ( ! current_user_can( Capabilities::EXPORT ) ) {
			wp_die( esc_html__( 'شما اجازه دانلود این فایل را ندارید.', 'paydarpress' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DOWNLOAD_ACTION );

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$path  = InventoryExporter::file_for( $token );

		if ( null === $path ) {
			wp_die( esc_html__( 'این فایل منقضی شده یا وجود ندارد. یک خروجی جدید بسازید.', 'paydarpress' ), '', array( 'response' => 404 ) );
		}

		$last = get_option( InventoryExporter::OPTION_LAST, array() );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $last['filename'] ) . '"' );
		$guard = strlen( InventoryExporter::GUARD );
		header( 'Content-Length: ' . (string) ( filesize( $path ) - $guard ) );
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false !== $handle ) {
			fseek( $handle, $guard );
			fpassthru( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/**
	 * First-run checklist.
	 */
	public function onboarding(): WP_REST_Response {
		global $wpdb;

		$latest     = $this->scans->latest_scan();
		$completion = ProfileRepository::completion();
		$state      = get_option( 'paydarpress_onboarding', array() );
		$state      = is_array( $state ) ? $state : array();
		$table      = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$handled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state IN ('reviewed', 'ignored', 'resolved')" );

		$imports = Schema::table( 'imports' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$imported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$imports}" );
		$ai       = \PaydarWP\PaydarPress\Ai\Credentials::public_view();

		$steps = array(
			array(
				'key'   => 'scan',
				'title' => 'اسکن سایت',
				'text'  => 'شناسایی مطالب، دسته‌ها، لینک‌ها و تصاویر در پس‌زمینه.',
				'done'  => null !== $latest && 'completed' === $latest['status'],
				'link'  => '#/welcome',
			),
			array(
				'key'   => 'profile',
				'title' => 'تکمیل پروفایل و صفحات کلیدی',
				'text'  => 'حوزه کاری، مخاطب، لحن و صفحاتی که باید لینک بگیرند؛ بیشترش خودکار پیشنهاد شده است.',
				'done'  => ! empty( $state['wizard_done'] ) || 100 === $completion['percent'],
				'link'  => '#/wizard',
			),
			array(
				'key'   => 'taxmap',
				'title' => 'نقشه دسته‌ها و برچسب‌ها',
				'text'  => 'نقش هر دسته و برچسب (اصلی، عادی، فقط ناوبری، کنار گذاشته) تا پیشنهادها درست طبقه‌بندی شوند.',
				'done'  => ! empty( $state['taxmap_done'] ) || array() !== \PaydarWP\PaydarPress\Profile\TaxonomyMap::notes(),
				'link'  => '#/profile',
			),
			array(
				'key'   => 'ai',
				'title' => 'روش تولید محتوا',
				'text'  => 'اتصال هوش مصنوعی (درگاه ایرانی یا خارجی) برای تولید خودکار، یا حالت دستی با هر دستیار هوش مصنوعی.',
				'done'  => $ai['configured'] || $imported > 0 || ! empty( $state['manual_chosen'] ),
				'link'  => '#/auto',
			),
			array(
				'key'   => 'gsc',
				'title' => 'بارگذاری خروجی سرچ کنسول',
				'text'  => 'برای اولویت‌بندی با داده واقعی گوگل و پیدا کردن صفحات ۴۰۴. اختیاری ولی توصیه‌شده.',
				'done'  => null !== \PaydarWP\PaydarPress\Gsc\GscRepository::latest_import() || ! empty( $state['gsc_skipped'] ),
				'link'  => '#/gsc',
			),
			array(
				'key'   => 'findings',
				'title' => 'اولین کارهای امروز',
				'text'  => 'بررسی چند مشکل مهم یا انجام اولین کار پیشنهادی.',
				'done'  => $handled > 0,
				'link'  => '#/tasks',
			),
		);

		return new WP_REST_Response(
			array(
				'steps'      => $steps,
				'done'       => count( array_filter( $steps, static fn( $s ) => $s['done'] ) ),
				'total'      => count( $steps ),
				'completion' => $completion,
				'dismissed'  => ! empty( $state['dismissed'] ),
			)
		);
	}

	/**
	 * Marks an onboarding choice: manual (content method), gsc_skipped, taxmap, dismissed.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function onboarding_mark( WP_REST_Request $request ): WP_REST_Response {
		$map   = array( 'manual' => 'manual_chosen', 'gsc_skip' => 'gsc_skipped', 'taxmap' => 'taxmap_done', 'dismiss' => 'dismissed', 'restore' => 'dismissed' );
		$what  = (string) $request->get_param( 'what' );
		$state = get_option( 'paydarpress_onboarding', array() );
		$state = is_array( $state ) ? $state : array();
		if ( isset( $map[ $what ] ) ) {
			$state[ $map[ $what ] ] = 'restore' !== $what;
			update_option( 'paydarpress_onboarding', $state, false );
		}
		return $this->onboarding();
	}

	/**
	 * Anchor distribution and warnings per key page.
	 */
	public function key_page_stats(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'pages'           => array_values( KeyPageLinks::stats() ),
				'exact_cap'       => KeyPageLinks::EXACT_CAP,
				'max_per_article' => KeyPageLinks::MAX_PER_ARTICLE,
			)
		);
	}

	/**
	 * Anchor variations for one page.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function anchor_suggestions( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( 'publish' !== get_post_status( $id ) ) {
			return new WP_Error( 'paydarpress_not_found', 'صفحه پیدا نشد.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'main_phrase' => KeyPageLinks::main_phrase( $id ),
				'anchors'     => KeyPageLinks::suggest( $id ),
			)
		);
	}

	/**
	 * Profile response.
	 */
	private function profile_payload(): array {
		$profile = ProfileRepository::get();

		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => array( 'type' => 'ASC', 'title' => 'ASC' ),
			)
		);

		return array(
			'fields'     => $profile['fields'],
			'updated_at' => $profile['updated_at'],
			'completion' => ProfileRepository::completion( $profile ),
			'defs'       => Fields::all(),
			'industries' => \PaydarWP\PaydarPress\Profile\Industries::for_ui(),
			'default_industry' => \PaydarWP\PaydarPress\Profile\Industries::DEFAULT_EXAMPLE,
			'groups'     => Fields::groups(),
			'choices'    => array(
				'pages' => array_map(
					static fn( $p ) => array(
						'id'    => $p->ID,
						'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
						'type'  => $p->post_type,
					),
					$pages
				),
				'categories' => array_map(
					static fn( $t ) => array(
						'id'    => (int) $t->term_id,
						'name'  => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
						'count' => (int) $t->count,
					),
					(array) get_terms(
						array(
							'taxonomy'   => 'category',
							'hide_empty' => false,
							'number'     => 200,
						)
					)
				),
			),
		);
	}

	/**
	 * Export response.
	 */
	private function export_payload(): array {
		$last  = get_option( InventoryExporter::OPTION_LAST, null );
		$valid = is_array( $last ) && null !== InventoryExporter::file_for( (string) $last['token'] );

		return array(
			'last'       => is_array( $last ) ? array(
				'filename'     => $last['filename'],
				'size'         => (int) $last['size'],
				'created_at'   => $last['created_at'],
				'counts'       => $last['counts'],
				'drafts'       => (bool) $last['drafts'],
				'compact'      => ! empty( $last['compact'] ),
				'missing'      => $last['missing'],
				'expired'      => ! $valid,
				// Not wp_nonce_url(): it HTML-escapes "&", which breaks the URL once it is JSON.
				'download_url' => $valid ? add_query_arg(
					array(
						'action'   => self::DOWNLOAD_ACTION,
						'token'    => rawurlencode( (string) $last['token'] ),
						'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION ),
					),
					admin_url( 'admin-post.php' )
				) : null,
			) : null,
			'completion' => ProfileRepository::completion(),
			'items'      => $this->publishable_items(),
			'compact_at' => InventoryExporter::COMPACT_SUGGEST_AT,
		);
	}

	/**
	 * Published posts and products in the inventory (decides the suggested format).
	 */
	private function publishable_items(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'paydarpress_inventory';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'publish' AND object_type <> 'page'" );
	}

	/**
	 * Updates an onboarding flag.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	private function set_onboarding( string $key, $value ): void {
		$state         = get_option( 'paydarpress_onboarding', array() );
		$state         = is_array( $state ) ? $state : array();
		$state[ $key ] = $value;
		update_option( 'paydarpress_onboarding', $state, false );
	}

	/**
	 * Taxonomy map page.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function taxmap( WP_REST_Request $request ) {
		$taxes    = \PaydarWP\PaydarPress\Profile\TaxonomyMap::taxonomies();
		$taxonomy = (string) $request->get_param( 'taxonomy' );
		$taxonomy = isset( $taxes[ $taxonomy ] ) ? $taxonomy : (string) array_key_first( $taxes );
		try {
			$page = \PaydarWP\PaydarPress\Profile\TaxonomyMap::page( $taxonomy, sanitize_text_field( (string) $request->get_param( 'search' ) ), max( 1, (int) $request->get_param( 'page' ) ), 30, sanitize_key( (string) ( $request->get_param( 'filter' ) ?: 'all' ) ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'paydarpress_taxmap', $e->getMessage(), array( 'status' => 400 ) );
		}
		$tabs = array();
		foreach ( $taxes as $key => $label ) {
			$tabs[] = array(
				'key'     => $key,
				'label'   => $label,
				'summary' => \PaydarWP\PaydarPress\Profile\TaxonomyMap::summary( $key ),
			);
		}
		$pages = array();
		foreach ( \PaydarWP\PaydarPress\Profile\ProfileRepository::key_page_ids() as $id ) {
			$pages[] = array(
				'id'    => $id,
				'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return new WP_REST_Response(
			$page + array(
				'taxonomy'  => $taxonomy,
				'tabs'      => $tabs,
				'roles'     => \PaydarWP\PaydarPress\Profile\TaxonomyMap::ROLES,
				'key_pages' => $pages,
				'targets'   => 'category' === $taxonomy ? self::auto_targets() : null,
				'max_tags'  => \PaydarWP\PaydarPress\Profile\TaxonomyMap::MAX_TAGS,
			)
		);
	}

	/**
	 * Confirms the map as it is (profile completion).
	 */
	public function taxmap_confirm(): WP_REST_Response {
		\PaydarWP\PaydarPress\Profile\ProfileRepository::save_user( array( 'categories_scope' => array_values( \PaydarWP\PaydarPress\Profile\TaxonomyMap::notes() ) ) );
		$state                = get_option( 'paydarpress_onboarding', array() );
		$state                = is_array( $state ) ? $state : array();
		$state['taxmap_done'] = true;
		update_option( 'paydarpress_onboarding', $state, false );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Saves notes for one term.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function taxmap_save( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( \PaydarWP\PaydarPress\Profile\TaxonomyMap::save( (int) $request['id'], (array) $request->get_json_params() ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'paydarpress_taxmap', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Automatic category targets per role (current site stage).
	 */
	private static function auto_targets(): array {
		$base = \PaydarWP\PaydarPress\Strategy\Structure::BASE_TARGET[ \PaydarWP\PaydarPress\Policy\SiteStage::current() ];
		$out  = array();
		foreach ( \PaydarWP\PaydarPress\Strategy\Structure::IMPORTANCE_FACTOR as $role => $factor ) {
			$out[ $role ] = (int) max( 2, round( $base * $factor ) );
		}
		return $out;
	}
}
