<?php
/**
 * Thin wrapper around Action Scheduler.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All PaydarPress background work runs in the `paydarpress` group.
 */
final class Queue {

	public const GROUP = 'paydarpress';

	/**
	 * Schedules an action. With $unique, an identical pending action is not duplicated.
	 *
	 * @param string $hook   Action hook.
	 * @param array  $args   Arguments (positional).
	 * @param int    $delay  Delay in seconds.
	 * @param bool   $unique Skip if the same hook+args is already pending.
	 */
	public static function schedule( string $hook, array $args = array(), int $delay = 0, bool $unique = true ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( $unique && false !== as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}

		if ( $delay <= 0 ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
			return;
		}

		as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
	}

	/**
	 * Cancels all pending actions for a hook.
	 *
	 * @param string $hook Action hook.
	 */
	public static function cancel( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
	}

	/**
	 * Runs due PaydarPress actions in the current request, within a time budget.
	 *
	 * Used while an admin keeps the PaydarPress screen open, so scans finish even on hosts where
	 * WP-Cron or loopback requests do not work (common on shared hosting).
	 *
	 * @param int $seconds Time budget.
	 * @return int Number of actions processed.
	 */
	public static function run_due( int $seconds = 8 ): int {
		if ( ! class_exists( '\ActionScheduler' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$deadline  = microtime( true ) + $seconds;
		$processed = 0;

		while ( microtime( true ) < $deadline ) {
			// Leave room for the rest of the request (and for the audit, which needs a few MB).
			if ( $processed > 0 && \PaydarWP\PaydarPress\Core\Resources::memory_tight( 0.6 ) ) {
				break;
			}

			$ids = as_get_scheduled_actions(
				array(
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'date'     => gmdate( 'Y-m-d H:i:s' ),
					'per_page' => 1,
					'orderby'  => 'date',
					'order'    => 'ASC',
				),
				'ids'
			);

			if ( empty( $ids ) ) {
				break;
			}

			$id = (int) reset( $ids );
			\ActionScheduler::runner()->process_action( $id, 'PaydarPress screen' );
			\PaydarWP\PaydarPress\Core\Resources::release_cache();
			++$processed;
		}

		return $processed;
	}
}
