<?php
/**
 * Orchestrates full and incremental scans through the background queue.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Scanner;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Resources;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\LinkResolver;
use PaydarWP\PaydarPress\Queue\Queue;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Actions (spec section 5.1):
 *  paydarpress/scan/batch     [scan_id, after_id]
 *  paydarpress/scan/finalize  [scan_id]
 *  paydarpress/item/rescan    [post_id]
 *  paydarpress/menus/rescan   []
 *  paydarpress/maintenance/daily
 */
final class ScanManager {

	public const ACTION_BATCH    = 'paydarpress/scan/batch';
	public const ACTION_FINALIZE = 'paydarpress/scan/finalize';
	public const ACTION_ITEM     = 'paydarpress/item/rescan';
	public const ACTION_MENUS    = 'paydarpress/menus/rescan';
	public const ACTION_DAILY    = 'paydarpress/maintenance/daily';
	public const ACTION_AUDIT    = 'paydarpress/audit/run';

	/** Last scheduled scan step, used to resume a scan whose PHP process died (memory/time limit). */
	public const OPTION_CURSOR = 'paydarpress_scan_cursor';

	/** A step with no queued action and no progress for this long is considered dead. */
	private const STALL_AFTER = 180;

	/** Resume attempts for the same step before the scan is marked failed. */
	private const MAX_RETRIES = 3;

	/** Seconds after the last change before the audit reruns (merges bursts of edits). */
	private const AUDIT_DELAY = 90;

	/** Seconds after the last edit before an item is rescanned (merges rapid saves). */
	private const RESCAN_DELAY = 60;

	/**
	 * Registers queue handlers and content-change hooks.
	 */
	public function register(): void {
		add_action( self::ACTION_BATCH, array( $this, 'run_batch' ), 10, 2 );
		add_action( self::ACTION_FINALIZE, array( $this, 'run_finalize' ), 10, 1 );
		add_action( self::ACTION_ITEM, array( $this, 'run_item' ), 10, 1 );
		add_action( self::ACTION_MENUS, array( $this, 'run_menus' ), 10, 0 );
		add_action( self::ACTION_DAILY, array( $this, 'run_daily' ), 10, 0 );
		add_action( self::ACTION_AUDIT, array( $this, 'run_audit' ), 10, 0 );

		// Incremental scanning: hooks only enqueue work (spec section 5.3, Performance Budget).
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 1 );
		add_action( 'wp_update_nav_menu', array( $this, 'on_menu_change' ), 10, 0 );

		// Admin and cron requests only: public page loads must not run any PaydarPress query.
		add_action(
			'action_scheduler_init',
			function () {
				if ( is_admin() || wp_doing_cron() ) {
					$this->ensure_daily();
				}
			}
		);
	}

	/**
	 * Starts a full scan unless one is already running.
	 *
	 * @return int Scan ID.
	 */
	public function start(): int {
		global $wpdb;

		$running = $this->running_scan();
		if ( null !== $running ) {
			return (int) $running['id'];
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'scans' ),
			array(
				'type'       => 'full',
				'status'     => 'running',
				'total'      => $this->count_items(),
				'processed'  => 0,
				'started_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);

		$scan_id = (int) $wpdb->insert_id;

		Activity::log( 'scan.started', 'اسکن کامل سایت شروع شد.', 'scan', $scan_id );
		$this->schedule_step( self::ACTION_BATCH, array( $scan_id, 0 ) );

		return $scan_id;
	}

	/**
	 * Cancels the running scan.
	 */
	public function cancel(): void {
		$running = $this->running_scan();
		if ( null === $running ) {
			return;
		}

		Queue::cancel( self::ACTION_BATCH );
		Queue::cancel( self::ACTION_FINALIZE );
		$this->update_scan( (int) $running['id'], array( 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ) );
		delete_option( self::OPTION_CURSOR );
		Activity::log( 'scan.cancelled', 'اسکن سایت لغو شد.', 'scan', (int) $running['id'] );
	}

	/**
	 * Processes one batch of content.
	 *
	 * @param int $scan_id  Scan ID.
	 * @param int $after_id Last processed post ID.
	 */
	public function run_batch( $scan_id, $after_id ): void {
		$scan_id  = (int) $scan_id;
		$after_id = (int) $after_id;
		$scan     = $this->get_scan( $scan_id );

		if ( null === $scan || 'running' !== $scan['status'] ) {
			return;
		}

		$limits   = $this->limits();
		$deadline = microtime( true ) + $limits['time'];
		$ids      = $this->next_ids( $after_id, $limits['batch'] );
		$scanner  = new ContentScanner();
		$done     = 0;
		$last_id  = $after_id;

		try {
			foreach ( $ids as $id ) {
				$scanner->scan( $id );
				$last_id = $id;
				++$done;

				if ( 0 === $done % 10 ) {
					Resources::release_cache();
				}

				// Stop early on slow hosts or small memory limits; the next batch continues from here.
				if ( microtime( true ) > $deadline || Resources::memory_tight( 0.75 ) ) {
					break;
				}
			}
			Resources::release_cache();
		} catch ( Throwable $e ) {
			$this->fail( $scan_id, $e );
			return;
		}

		$this->update_scan( $scan_id, array( 'processed' => min( (int) $scan['total'], (int) $scan['processed'] + $done ) ) );

		if ( $done > 0 && ( count( $ids ) > $done || count( $ids ) === $limits['batch'] ) ) {
			$this->schedule_step( self::ACTION_BATCH, array( $scan_id, $last_id ), false );
			return;
		}

		$this->schedule_step( self::ACTION_FINALIZE, array( $scan_id ) );
	}

	/**
	 * Removes stale rows, scans menus, resolves links and recounts. Reschedules itself until
	 * all pending links are resolved.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function run_finalize( $scan_id ): void {
		global $wpdb;

		$scan_id = (int) $scan_id;
		$scan    = $this->get_scan( $scan_id );

		if ( null === $scan || 'running' !== $scan['status'] ) {
			return;
		}

		try {
			if ( empty( $scan['error'] ) || 'menus_done' !== $scan['error'] ) {
				// Content not seen in this scan no longer exists or no longer qualifies.
				$table = Schema::table( 'inventory' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stale = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$table} WHERE scanned_at < %s", $scan['started_at'] ) );
				foreach ( (array) $stale as $id ) {
					ContentScanner::remove( (int) $id );
				}

				( new MenuScanner() )->scan();
				// The `error` column doubles as a step marker while the scan is running.
				$this->update_scan( $scan_id, array( 'error' => 'menus_done' ) );
			}

			$resolver = new LinkResolver();
			$deadline = microtime( true ) + $this->limits()['time'];

			while ( $resolver->resolve_pending( 300 ) > 0 ) {
				if ( microtime( true ) > $deadline ) {
					$this->schedule_step( self::ACTION_FINALIZE, array( $scan_id ), false, true );
					return;
				}
			}

			$resolver->recount_inbound();

			// Refresh detected/inferred profile values (confirmed fields are never overwritten),
			// then audit, so key pages are known to the WEAK_INBOUND rule.
			\PaydarWP\PaydarPress\Profile\ProfileInference::run();

			// The audit runs inside the scan so a completed scan always has fresh findings.
			Queue::cancel( self::ACTION_AUDIT );
			( new AuditEngine() )->run( true );
		} catch ( Throwable $e ) {
			$this->fail( $scan_id, $e );
			return;
		}

		$this->update_scan(
			$scan_id,
			array(
				'status'      => 'completed',
				'processed'   => (int) $scan['total'],
				'finished_at' => current_time( 'mysql', true ),
				'error'       => null,
			)
		);

		delete_option( self::OPTION_CURSOR );
		Activity::log( 'scan.completed', sprintf( 'اسکن کامل سایت انجام شد (%s مورد).', \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( (int) $scan['total'] ) ), 'scan', $scan_id );

		/**
		 * Fires after a full scan completes. The audit engine hooks in here.
		 *
		 * @param int $scan_id Scan ID.
		 */
		do_action( 'paydarpress/scan/completed', $scan_id );
	}

	/**
	 * Incremental rescan of one item.
	 *
	 * @param int $post_id Post ID.
	 */
	public function run_item( $post_id ): void {
		$post_id = (int) $post_id;

		if ( null !== $this->running_scan() ) {
			return; // The full scan covers it.
		}

		$result = ( new ContentScanner() )->scan( $post_id );

		if ( 'unchanged' !== $result ) {
			// Permalink or status may have changed: re-resolve links pointing here.
			LinkResolver::invalidate_target( $post_id );
			$resolver = new LinkResolver();
			while ( $resolver->resolve_pending( 300 ) > 0 ) {
				// Small: only this item's links and links to it are pending.
			}
			$resolver->recount_inbound();
		}

		Queue::schedule( self::ACTION_AUDIT, array(), self::AUDIT_DELAY );

		/**
		 * Fires after an incremental rescan.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $result  scanned|unchanged|removed.
		 */
		do_action( 'paydarpress/item/rescanned', $post_id, $result );
	}

	/**
	 * Rebuilds menu links after a menu change.
	 */
	public function run_menus(): void {
		( new MenuScanner() )->scan();
		$resolver = new LinkResolver();
		while ( $resolver->resolve_pending( 300 ) > 0 ) {
			// Menus are small.
		}
		$resolver->recount_inbound();
		Queue::schedule( self::ACTION_AUDIT, array(), self::AUDIT_DELAY );
	}

	/**
	 * Reruns the audit (after incremental changes or settings changes).
	 */
	public function run_audit(): void {
		if ( null !== $this->running_scan() ) {
			return; // The full scan runs its own audit.
		}

		( new AuditEngine() )->run( false );
	}

	/**
	 * Daily maintenance.
	 */
	public function run_daily(): void {
		$this->recover_stalled();
		Activity::prune();
		\PaydarWP\PaydarPress\Export\InventoryExporter::cleanup();
		\PaydarWP\PaydarPress\Strategy\Redirects::flatten();
		\PaydarWP\PaydarPress\Import\LinkSuggestionRepository::prune();
		\PaydarWP\PaydarPress\Links\ExternalLinks::maybe_run();
		\PaydarWP\PaydarPress\Improve\TagProgram::groups();
		\PaydarWP\PaydarPress\Improve\Progress::snapshot();
	}

	/**
	 * Schedules the daily maintenance action once.
	 */
	public function ensure_daily(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::ACTION_DAILY, array(), Queue::GROUP ) ) {
			as_schedule_recurring_action( self::next_daily_time(), DAY_IN_SECONDS, self::ACTION_DAILY, array(), Queue::GROUP );
		}
	}

	/**
	 * Moves the daily maintenance to the new time after performance settings change.
	 */
	public static function reschedule_daily(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::ACTION_DAILY, array(), Queue::GROUP );
		as_schedule_recurring_action( self::next_daily_time(), DAY_IN_SECONDS, self::ACTION_DAILY, array(), Queue::GROUP );
	}

	/**
	 * First run of the daily maintenance: in one hour, or at the start of the quiet hours
	 * (site time zone) when "quiet hours only" is on.
	 */
	public static function next_daily_time(): int {
		if ( ! (bool) Settings::get( 'performance.quiet_hours_only', false ) ) {
			return time() + HOUR_IN_SECONDS;
		}

		$hour = (int) Settings::get( 'performance.quiet_hours_start', 1 );
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$next = $now->setTime( $hour, 15 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * save_post: queue a delayed rescan (never scans inline).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! is_object( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, ContentScanner::post_types(), true ) || ! $this->has_completed_scan() ) {
			return;
		}

		$this->queue_item( (int) $post_id );
	}

	/**
	 * deleted_post: queue removal.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_deleted_post( $post_id ): void {
		if ( $this->has_completed_scan() ) {
			$this->queue_item( (int) $post_id );
		}
	}

	/**
	 * Menu changed.
	 */
	public function on_menu_change(): void {
		if ( $this->has_completed_scan() ) {
			Queue::schedule( self::ACTION_MENUS, array(), self::RESCAN_DELAY );
		}
	}

	/**
	 * Schedules the next scan step and records it as the resume point.
	 *
	 * @param string $hook     Action hook.
	 * @param array  $args     Arguments.
	 * @param bool   $unique   Skip if already queued.
	 * @param bool   $progress Same step continues with progress (keeps the retry count at zero).
	 */
	private function schedule_step( string $hook, array $args, bool $unique = true, bool $progress = false ): void {
		$cursor  = get_option( self::OPTION_CURSOR, array() );
		$same    = is_array( $cursor ) && ( $cursor['hook'] ?? '' ) === $hook && ( $cursor['args'] ?? null ) === $args;
		$retries = ( $same && ! $progress ) ? (int) ( $cursor['retries'] ?? 0 ) : 0;

		update_option(
			self::OPTION_CURSOR,
			array(
				'hook'    => $hook,
				'args'    => $args,
				'at'      => time(),
				'retries' => $retries,
			),
			false
		);

		Queue::schedule( $hook, $args, 0, $unique );
	}

	/**
	 * Resumes a scan whose last step died without scheduling the next one (for example a PHP
	 * fatal error from the host's memory or time limit). After MAX_RETRIES attempts on the same
	 * step the scan is marked failed with a message the site owner can act on.
	 *
	 * Called from the status poll and from daily maintenance; cheap when nothing is running.
	 */
	public function recover_stalled(): void {
		$scan = $this->running_scan();
		if ( null === $scan ) {
			return;
		}

		$cursor = get_option( self::OPTION_CURSOR, array() );
		if ( ! is_array( $cursor ) || empty( $cursor['hook'] ) || time() - (int) ( $cursor['at'] ?? 0 ) < self::STALL_AFTER ) {
			return;
		}

		// Only pending actions count: an action still "in-progress" after STALL_AFTER seconds
		// (batches stop after at most 60 s) belongs to a PHP process that died.
		foreach ( array( self::ACTION_BATCH, self::ACTION_FINALIZE ) as $hook ) {
			if ( $this->has_pending( $hook ) ) {
				return; // Still queued; WP-Cron may simply be slow.
			}
		}

		$retries = (int) ( $cursor['retries'] ?? 0 );
		if ( $retries >= self::MAX_RETRIES ) {
			$this->fail(
				(int) $scan['id'],
				new \RuntimeException( 'اسکن چند بار در یک مرحله متوقف شد؛ احتمالاً محدودیت حافظه یا زمان اجرای PHP هاست کافی نیست. «حالت هاست اشتراکی» را در تنظیمات روشن کنید یا از پشتیبانی هاست بخواهید memory_limit را دست‌کم به 128M برساند، سپس دوباره اسکن کنید.' )
			);
			return;
		}

		$cursor['retries'] = $retries + 1;
		$cursor['at']      = time();
		update_option( self::OPTION_CURSOR, $cursor, false );

		Activity::log( 'scan.resumed', 'یک مرحله اسکن ناتمام ماند و دوباره از همان نقطه ادامه داده شد.', 'scan', (int) $scan['id'] );
		Queue::schedule( (string) $cursor['hook'], (array) $cursor['args'], 0, false );
	}

	/**
	 * Whether a pending (not in-progress) action exists for the hook.
	 *
	 * @param string $hook Hook.
	 */
	private function has_pending( string $hook ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		return array() !== as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => Queue::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * Latest scan row (any status).
	 */
	public function latest_scan(): ?array {
		global $wpdb;

		$table = Schema::table( 'scans' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Queues a rescan, replacing any pending one so rapid saves merge.
	 *
	 * @param int $post_id Post ID.
	 */
	private function queue_item( int $post_id ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::ACTION_ITEM, array( $post_id ), Queue::GROUP );
		}
		Queue::schedule( self::ACTION_ITEM, array( $post_id ), self::RESCAN_DELAY, false );
	}

	/**
	 * Whether at least one full scan finished (before that, saves are covered by the first scan).
	 */
	private function has_completed_scan(): bool {
		global $wpdb;

		$table = Schema::table( 'scans' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'completed' LIMIT 1" );
	}

	/**
	 * Running scan younger than 6 hours (older ones are considered dead).
	 */
	private function running_scan(): ?array {
		global $wpdb;

		$table  = Schema::table( 'scans' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'running' AND started_at > %s ORDER BY id DESC LIMIT 1", $cutoff ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Scan row by ID.
	 *
	 * @param int $scan_id Scan ID.
	 */
	private function get_scan( int $scan_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'scans' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $scan_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Updates a scan row.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $data    Columns.
	 */
	private function update_scan( int $scan_id, array $data ): void {
		global $wpdb;

		$wpdb->update( Schema::table( 'scans' ), $data, array( 'id' => $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Marks a scan as failed.
	 *
	 * @param int       $scan_id Scan ID.
	 * @param Throwable $e       Error.
	 */
	private function fail( int $scan_id, Throwable $e ): void {
		delete_option( self::OPTION_CURSOR );
		$this->update_scan(
			$scan_id,
			array(
				'status'      => 'failed',
				'finished_at' => current_time( 'mysql', true ),
				'error'       => mb_substr( $e->getMessage(), 0, 1000 ),
			)
		);
		Activity::log( 'scan.failed', 'اسکن سایت با خطا متوقف شد: ' . mb_substr( $e->getMessage(), 0, 300 ), 'scan', $scan_id );
	}

	/**
	 * Batch size and time limit, honoring shared-hosting mode (spec section 5.2).
	 *
	 * @return array{batch:int, time:int}
	 */
	private function limits(): array {
		if ( (bool) Settings::get( 'performance.shared_hosting', false ) ) {
			return array(
				'batch' => 15,
				'time'  => 10,
			);
		}

		return array(
			'batch' => max( 5, min( 200, (int) Settings::get( 'performance.batch_size', 50 ) ) ),
			'time'  => max( 5, min( 60, (int) Settings::get( 'performance.batch_time_limit', 20 ) ) ),
		);
	}

	/**
	 * Number of items a full scan will process.
	 */
	private function count_items(): int {
		global $wpdb;

		list( $types_sql, $statuses_sql ) = $this->filters_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types_sql}) AND post_status IN ({$statuses_sql})" );
	}

	/**
	 * Next IDs after $after_id.
	 *
	 * @param int $after_id Last processed ID.
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	private function next_ids( int $after_id, int $limit ): array {
		global $wpdb;

		list( $types_sql, $statuses_sql ) = $this->filters_sql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$types_sql}) AND post_status IN ({$statuses_sql}) AND ID > %d ORDER BY ID ASC LIMIT %d", $after_id, $limit ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Escaped IN() lists for post types and statuses.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function filters_sql(): array {
		$quote = static function ( array $values ): string {
			return implode( ',', array_map( static fn( $v ) => "'" . esc_sql( (string) $v ) . "'", $values ) );
		};

		return array( $quote( ContentScanner::post_types() ), $quote( ContentScanner::STATUSES ) );
	}
}
