<?php
/**
 * REST: settings, activity log, queue status and system info.
 *
 *  GET  /settings          settings + defaults + limits (manage)
 *  POST /settings          { settings: {...} } partial update (manage)
 *  GET  /activity          ?page&per_page&group (view)
 *  GET  /queue             queue health (manage)
 *  POST /queue/run         run due PaydarPress actions now (manage)
 *  GET  /system            system info for support, no personal data (manage)
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Rest;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Capabilities;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Scanner\ScanManager;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings controller.
 */
final class SettingsController {

	public const NAMESPACE = 'paydarpress/v1';

	/** Activity filter groups → event prefixes. */
	private const GROUPS = array( 'scan', 'finding', 'profile', 'export', 'import', 'links', 'settings' );

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Routes.
	 */
	public function routes(): void {
		$manage = static fn() => current_user_can( Capabilities::MANAGE );
		$view   = static fn() => current_user_can( Capabilities::VIEW );

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $manage,
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'activity' ),
				'permission_callback' => $view,
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 100,
					),
					'group'    => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), self::GROUPS ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/queue/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'queue_run' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/system',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'system' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Current settings.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings_payload() );
	}

	/**
	 * Saves a partial settings object. Unknown keys are dropped, numbers clamped.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$before = Settings::all();
		$clean  = Settings::sanitize( (array) $request->get_param( 'settings' ) );

		Settings::replace( $clean );
		$after = Settings::all();

		if ( $before['audit'] !== $after['audit'] ) {
			// Thresholds changed: rerun the audit shortly (not inline).
			Queue::schedule( ScanManager::ACTION_AUDIT, array(), 5 );
		}

		$quiet_keys = array( 'quiet_hours_only', 'quiet_hours_start' );
		foreach ( $quiet_keys as $key ) {
			if ( $before['performance'][ $key ] !== $after['performance'][ $key ] ) {
				ScanManager::reschedule_daily();
				break;
			}
		}

		if ( $before !== $after ) {
			Activity::log( 'settings.updated', 'تنظیمات پایدارپرس تغییر کرد (' . implode( '، ', $this->changed_groups( $before, $after ) ) . ').', 'settings', 0 );
		}

		return new WP_REST_Response( $this->settings_payload() );
	}

	/**
	 * Activity log page.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function activity( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table    = Schema::table( 'activity' );
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$group    = (string) $request->get_param( 'group' );
		$where    = '1=1';
		$args     = array();

		if ( '' !== $group ) {
			$where  = 'event LIKE %s';
			$args[] = $wpdb->esc_like( $group . '.' ) . '%';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event, user_id, object_kind, object_id, message, created_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$names = array();
		$items = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) $row['user_id'];
			if ( $user_id && ! isset( $names[ $user_id ] ) ) {
				$user             = get_userdata( $user_id );
				$names[ $user_id ] = $user ? $user->display_name : 'کاربر حذف‌شده';
			}
			$items[] = array(
				'id'         => (int) $row['id'],
				'event'      => $row['event'],
				'group'      => strtok( (string) $row['event'], '.' ),
				'user'       => $user_id ? $names[ $user_id ] : 'سیستم (خودکار)',
				'message'    => $row['message'],
				'link'       => $this->object_link( (string) $row['object_kind'], (int) $row['object_id'] ),
				'created_at' => gmdate( 'c', (int) strtotime( $row['created_at'] . ' UTC' ) ),
			);
		}

		return new WP_REST_Response(
			array(
				'items'          => $items,
				'total'          => $total,
				'pages'          => (int) ceil( $total / $per_page ),
				'retention_days' => (int) Settings::get( 'retention.activity_days', 90 ),
			)
		);
	}

	/**
	 * Queue health.
	 */
	public function queue(): WP_REST_Response {
		return new WP_REST_Response( $this->queue_payload() );
	}

	/**
	 * Runs due actions now (for hosts where WP-Cron does not run).
	 */
	public function queue_run(): WP_REST_Response {
		$processed = Queue::run_due( 15 );

		return new WP_REST_Response( array_merge( $this->queue_payload(), array( 'processed' => $processed ) ) );
	}

	/**
	 * System info for support. Contains no emails, usernames, keys or paths outside the plugin.
	 */
	public function system(): WP_REST_Response {
		global $wpdb;

		$tables = array();
		foreach ( Schema::TABLES as $name ) {
			$table = Schema::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$tables[ $name ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$theme = wp_get_theme();

		return new WP_REST_Response(
			array(
				'plugin_version'   => PAYDARPRESS_VERSION,
				'db_version'       => (string) get_option( 'paydarpress_db_version', '' ),
				'wordpress'        => get_bloginfo( 'version' ),
				'php'              => PHP_VERSION,
				'database'         => $wpdb->db_server_info(),
				'memory_limit'     => (string) ini_get( 'memory_limit' ),
				'wp_memory_limit'  => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
				'max_execution'    => (int) ini_get( 'max_execution_time' ),
				'locale'           => get_locale(),
				'timezone'         => wp_timezone_string(),
				'theme'            => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
				'block_theme'      => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
				'seo_plugin'       => SeoDetector::adapter()->key(),
				'woocommerce'      => class_exists( 'WooCommerce' ),
				'action_scheduler' => class_exists( '\ActionScheduler_Versions' ) ? \ActionScheduler_Versions::instance()->latest_version() : '',
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'multisite'        => is_multisite(),
				'table_rows'       => $tables,
			)
		);
	}

	/**
	 * Settings payload.
	 */
	private function settings_payload(): array {
		$limits = array();
		foreach ( Settings::LIMITS as $path => $range ) {
			$limits[ $path ] = array(
				'min' => $range[0],
				'max' => $range[1],
			);
		}

		return array(
			'settings'      => Settings::all(),
			'defaults'      => Settings::defaults(),
			'limits'        => $limits,
			'next_daily_at' => $this->next_daily(),
			'timezone'      => wp_timezone_string(),
		);
	}

	/**
	 * Queue health: pending/overdue/failed counts and whether WP-Cron seems to run.
	 */
	private function queue_payload(): array {
		$pending = 0;
		$overdue = 0;
		$failed  = 0;
		$oldest  = null;

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$base    = array(
				'group'    => Queue::GROUP,
				'per_page' => 200,
			);
			$pending = count( as_get_scheduled_actions( $base + array( 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
			$due     = as_get_scheduled_actions(
				$base + array(
					'status'  => \ActionScheduler_Store::STATUS_PENDING,
					'date'    => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ),
					'orderby' => 'date',
					'order'   => 'ASC',
				),
				'ids'
			);
			$overdue = count( $due );
			if ( $due ) {
				$date   = \ActionScheduler::store()->get_date( (int) reset( $due ) );
				$oldest = $date ? $date->format( 'c' ) : null;
			}
			$failed = count(
				as_get_scheduled_actions(
					$base + array(
						'status' => \ActionScheduler_Store::STATUS_FAILED,
						'date'   => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
						'date_compare' => '>=',
					),
					'ids'
				)
			);
		}

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return array(
			'pending'        => $pending,
			'overdue'        => $overdue,
			'oldest_overdue' => $oldest,
			'failed_7d'      => $failed,
			'cron_disabled'  => $cron_disabled,
			// Actions more than 10 minutes late mean nothing is running the queue in the background.
			'healthy'        => 0 === $overdue,
			'next_daily_at'  => $this->next_daily(),
		);
	}

	/**
	 * Next daily maintenance run (ISO) or null.
	 */
	private function next_daily(): ?string {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}
		$next = as_next_scheduled_action( ScanManager::ACTION_DAILY, array(), Queue::GROUP );

		return is_int( $next ) ? gmdate( 'c', $next ) : null;
	}

	/**
	 * Admin link for an activity object.
	 *
	 * @param string $kind Object kind.
	 * @param int    $id   Object ID.
	 */
	private function object_link( string $kind, int $id ): ?string {
		if ( in_array( $kind, array( 'post', 'page', 'product' ), true ) && $id > 0 && current_user_can( 'edit_post', $id ) ) {
			return get_edit_post_link( $id, 'raw' );
		}

		return null;
	}

	/**
	 * Persian names of changed settings groups.
	 *
	 * @param array $before Before.
	 * @param array $after  After.
	 * @return string[]
	 */
	private function changed_groups( array $before, array $after ): array {
		$labels = array(
			'performance' => 'عملکرد',
			'audit'       => 'ممیزی',
			'retention'   => 'نگهداری داده',
			'ui'          => 'ظاهر',
			'uninstall'   => 'حذف افزونه',
		);
		$out = array();
		foreach ( $labels as $group => $label ) {
			if ( ( $before[ $group ] ?? null ) !== ( $after[ $group ] ?? null ) ) {
				$out[] = $label;
			}
		}

		return $out;
	}
}
