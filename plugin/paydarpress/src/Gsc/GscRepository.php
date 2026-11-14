<?php
/**
 * Read access to imported Search Console data.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Gsc;

use PaydarWP\PaydarPress\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console repository.
 */
final class GscRepository {

	/** Days after which imported data is considered stale. */
	public const STALE_DAYS = 60;

	/** Days after which the owner is reminded to upload fresh exports (weekly routine). */
	public const REMIND_DAYS = 7;

	/** Latest import of this request: false = not read yet. @var array|null|false */
	private static $latest = false;

	/**
	 * Latest completed (not cleared) Search Console import with decoded summary.
	 */
	public static function latest_import(): ?array {
		global $wpdb;

		// Asked several times per screen (dashboard widget, badges): one query per request.
		if ( false !== self::$latest ) {
			return self::$latest;
		}

		$table = Schema::table( 'imports' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE type = 'gsc' AND status = 'completed' ORDER BY id DESC LIMIT 1", ARRAY_A );

		if ( ! is_array( $row ) ) {
			self::$latest = null;
			return null;
		}

		$summary        = json_decode( (string) $row['summary'], true );
		$row['summary'] = is_array( $summary ) ? $summary : array();
		self::$latest   = $row;

		return $row;
	}

	/**
	 * Forgets the cached latest import (after an import or clear in the same request).
	 */
	public static function forget(): void {
		self::$latest = false;
	}

	/**
	 * Whether Search Console data is available.
	 */
	public static function has_data(): bool {
		return null !== self::latest_import();
	}

	/**
	 * Whole days since the latest import (null without data).
	 */
	public static function days_since(): ?int {
		$import = self::latest_import();
		return null === $import ? null : (int) floor( ( time() - strtotime( $import['created_at'] . ' UTC' ) ) / DAY_IN_SECONDS );
	}

	/**
	 * Whether the weekly refresh is due.
	 */
	public static function needs_refresh(): bool {
		$days = self::days_since();
		return null !== $days && $days >= self::REMIND_DAYS;
	}

	/**
	 * Whether the latest import is older than STALE_DAYS.
	 */
	public static function is_stale(): bool {
		$import = self::latest_import();

		return null !== $import && strtotime( $import['created_at'] . ' UTC' ) < time() - self::STALE_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Metrics per content item (rows that matched the item's current URL), aggregated:
	 * clicks and impressions summed, position weighted by impressions.
	 *
	 * @return array<int, array{clicks:int, impressions:int, ctr:float, position:float, prev_clicks:?int, prev_impressions:?int, prev_position:?float}>
	 */
	public static function page_metrics(): array {
		global $wpdb;

		$table = Schema::table( 'gsc' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT object_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
				SUM(position * impressions) AS pos_weight, AVG(position) AS pos_avg,
				SUM(prev_clicks) AS prev_clicks, SUM(prev_impressions) AS prev_impressions,
				SUM(prev_position * prev_impressions) AS prev_pos_weight, AVG(prev_position) AS prev_pos_avg,
				COUNT(prev_clicks) AS has_prev
			FROM {$table} WHERE kind = 'page' AND match_state = 'matched' AND object_id IS NOT NULL GROUP BY object_id",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$impressions      = (int) $row['impressions'];
			$prev_impressions = (int) $row['prev_impressions'];
			$has_prev         = (int) $row['has_prev'] > 0;

			$out[ (int) $row['object_id'] ] = array(
				'clicks'           => (int) $row['clicks'],
				'impressions'      => $impressions,
				'ctr'              => $impressions > 0 ? round( (int) $row['clicks'] / $impressions, 4 ) : 0.0,
				'position'         => round( $impressions > 0 ? (float) $row['pos_weight'] / $impressions : (float) $row['pos_avg'], 1 ),
				'prev_clicks'      => $has_prev ? (int) $row['prev_clicks'] : null,
				'prev_impressions' => $has_prev ? $prev_impressions : null,
				'prev_position'    => $has_prev ? round( $prev_impressions > 0 ? (float) $row['prev_pos_weight'] / $prev_impressions : (float) $row['prev_pos_avg'], 1 ) : null,
			);
		}

		return $out;
	}

	/**
	 * Search queries, most impressions first.
	 *
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @param string $search Optional substring.
	 * @return array{items: array<int, array>, total: int}
	 */
	public static function queries( int $limit, int $offset = 0, string $search = '' ): array {
		global $wpdb;

		$table = Schema::table( 'gsc' );
		$where = "kind = 'query'";
		$args  = array();
		if ( '' !== $search ) {
			$where .= ' AND item LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item, clicks, impressions, ctr, position, prev_clicks, prev_impressions, prev_position FROM {$table} WHERE {$where} ORDER BY impressions DESC, clicks DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Page rows that did not match current content (archives, old URLs, deleted pages).
	 *
	 * @param int $limit Limit.
	 * @return array<int, array>
	 */
	public static function unmatched( int $limit = 200 ): array {
		global $wpdb;

		$table = Schema::table( 'gsc' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT item, object_id, match_state, clicks, impressions, ctr, position, prev_clicks, prev_impressions, prev_position FROM {$table} WHERE kind = 'page' AND match_state NOT IN ('matched', 'foreign') ORDER BY clicks DESC, impressions DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				$item                = self::cast( $row );
				$item['object_id']   = null === $row['object_id'] ? null : (int) $row['object_id'];
				$item['match_state'] = (string) $row['match_state'];
				return $item;
			},
			(array) $rows
		);
	}

	/**
	 * Deletes all imported Search Console rows and marks the imports as cleared.
	 */
	public static function clear(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'gsc' ) );
		$wpdb->query( 'UPDATE ' . Schema::table( 'imports' ) . " SET status = 'cleared' WHERE type = 'gsc' AND status = 'completed'" );
		self::forget();
		// phpcs:enable
	}

	/**
	 * Casts a metrics row.
	 *
	 * @param array $row Row.
	 */
	private static function cast( array $row ): array {
		return array(
			'item'             => (string) $row['item'],
			'clicks'           => (int) $row['clicks'],
			'impressions'      => (int) $row['impressions'],
			'ctr'              => round( (float) $row['ctr'], 4 ),
			'position'         => round( (float) $row['position'], 1 ),
			'prev_clicks'      => null === $row['prev_clicks'] ? null : (int) $row['prev_clicks'],
			'prev_impressions' => null === $row['prev_impressions'] ? null : (int) $row['prev_impressions'],
			'prev_position'    => null === $row['prev_position'] ? null : round( (float) $row['prev_position'], 1 ),
		);
	}
}
