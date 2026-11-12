<?php
/**
 * Activity log.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Core;

use PaydarWP\PaydarPress\Database\Schema;

/**
 * Writes rows to paydarpress_activity and prunes old ones.
 */
final class Activity {

	/**
	 * Records an event.
	 *
	 * @param string $event       Event key, e.g. `scan.completed`.
	 * @param string $message     Human-readable Persian message.
	 * @param string $object_kind Optional object kind.
	 * @param int    $object_id   Optional object ID.
	 */
	public static function log( string $event, string $message, string $object_kind = '', int $object_id = 0 ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( 'activity' ),
			array(
				'event'       => substr( $event, 0, 60 ),
				'user_id'     => get_current_user_id(),
				'object_kind' => substr( $object_kind, 0, 20 ),
				'object_id'   => $object_id,
				'message'     => $message,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Deletes entries older than the retention setting.
	 */
	public static function prune(): void {
		global $wpdb;

		$days   = max( 7, (int) Settings::get( 'retention.activity_days', 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = Schema::table( 'activity' );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
