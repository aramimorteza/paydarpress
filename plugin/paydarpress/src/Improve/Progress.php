<?php
/**
 * Improvement program overview: one monthly snapshot per area so the owner sees the site
 * getting better (or not) over time.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Improve;

use PaydarWP\PaydarPress\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Monthly snapshots.
 */
final class Progress {

	public const OPTION = 'paydarpress_progress';

	/**
	 * Current numbers of every area.
	 */
	public static function now(): array {
		global $wpdb;
		$inv   = Schema::table( 'inventory' );
		$stats = get_option( 'paydarpress_stats', array() );
		$stats = is_array( $stats ) ? $stats : array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT COUNT(*) AS n, AVG(internal_in) AS inbound, SUM(CASE WHEN internal_in = 0 THEN 1 ELSE 0 END) AS orphans, SUM(inline_images_no_alt) AS no_alt, AVG(word_count) AS words FROM {$inv} WHERE status = 'publish' AND object_type = 'post'", ARRAY_A );
		$tags  = TagProgram::state();
		$slugs = SlugProgram::items();
		$links = LinkProgram::gaps();
		$clicks = 0;
		foreach ( \PaydarWP\PaydarPress\Gsc\GscRepository::page_metrics() as $m ) {
			$clicks += (int) $m['clicks'];
		}
		return array(
			'tags_good'    => $tags['percent'],
			'tags_dups'    => $tags['dups'],
			'tags_over'    => $tags['over'],
			'slugs_poor'   => count( $slugs ),
			'refresh'      => (int) ( $stats['refresh'] ?? 0 ),
			'competing'    => count( ArticleProgram::competing( 200 ) ),
			'inbound_ok'   => $links['total'] > 0 ? (int) round( 100 * $links['ok'] / $links['total'] ) : 100,
			'orphans'      => (int) ( $row['orphans'] ?? 0 ),
			'inbound'      => round( (float) ( $row['inbound'] ?? 0 ), 1 ),
			'words'        => (int) round( (float) ( $row['words'] ?? 0 ) ),
			'no_alt'       => (int) ( $row['no_alt'] ?? 0 ),
			'clicks'       => $clicks,
		);
	}

	/**
	 * Stores this month's numbers (daily; the last value of a month stays).
	 */
	public static function snapshot(): void {
		$all = self::history();
		$all[ gmdate( 'Y-m' ) ] = self::now();
		ksort( $all );
		update_option( self::OPTION, array_slice( $all, -12, null, true ), false );
	}

	/**
	 * Month => numbers.
	 */
	public static function history(): array {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) ? $h : array();
	}
}
