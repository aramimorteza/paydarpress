<?php
/**
 * Internal link suggestions waiting for approval (review UI in milestone M9).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Import;

use PaydarWP\PaydarPress\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link suggestion repository.
 */
final class LinkSuggestionRepository {

	/**
	 * Inserts a pending suggestion unless the same pending source → target pair exists.
	 *
	 * @param int    $source_id Source content.
	 * @param int    $target_id Target content.
	 * @param string $anchor    Anchor text.
	 * @param string $sentence  Sentence where the link fits.
	 * @param string $origin    contentmaster|paydarpress.
	 * @param int    $import_id Import.
	 * @param string $status    pending, or approved for links that come with a new article
	 *                          (inserted automatically when the article is published).
	 */
	public static function insert( int $source_id, int $target_id, string $anchor, string $sentence, string $origin, int $import_id, string $status = 'pending' ): int {
		global $wpdb;

		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id ) {
			return 0;
		}

		$table = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_id = %d AND target_id = %d AND status IN ('pending','approved')", $source_id, $target_id ) );
		if ( $exists ) {
			return (int) $exists;
		}

		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'anchor'     => mb_substr( $anchor, 0, 255 ),
				'sentence'   => $sentence,
				'origin'     => $origin,
				'status'     => 'approved' === $status ? 'approved' : 'pending',
				'import_id'  => $import_id,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Number of pending suggestions.
	 */
	public static function pending_count(): int {
		global $wpdb;

		$table = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}
}
