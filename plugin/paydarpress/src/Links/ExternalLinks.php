<?php
/**
 * Optional weekly check of links to other sites (setting links.check_external, off by default).
 *
 * Careful by design: a link is "broken" only after a definite 404/410 in two checks at least
 * six days apart. Connection errors, timeouts, 403/429/5xx and filtering count as
 * "unreachable" and never as broken. If most links of a run are unreachable, the host
 * probably cannot reach the internet (common on Iranian hosts) and the run is noted as such.
 * Only HEAD requests (GET with a 1 KB limit when HEAD is refused); no data is sent.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * External link checker.
 */
final class ExternalLinks {

	public const OPTION_RUN = 'paydarpress_extlinks_run';
	public const RECHECK    = 6 * DAY_IN_SECONDS;

	/**
	 * Runs at most once a week (called from the daily maintenance).
	 *
	 * @param bool $force Ignore the weekly spacing.
	 */
	public static function maybe_run( bool $force = false ): ?array {
		if ( ! Settings::get( 'links.check_external', false ) ) {
			return null;
		}
		$last = get_option( self::OPTION_RUN, array() );
		if ( ! $force && is_array( $last ) && ! empty( $last['at'] ) && time() - (int) $last['at'] < 7 * DAY_IN_SECONDS ) {
			return null;
		}
		return self::run( (int) Settings::get( 'links.external_per_run', 50 ) );
	}

	/**
	 * Checks up to $limit links: never checked first, then the oldest checks.
	 *
	 * @param int $limit Links.
	 */
	public static function run( int $limit ): array {
		global $wpdb;
		self::sync();
		$table = Schema::table( 'extlinks' );
		$due   = gmdate( 'Y-m-d H:i:s', time() - self::RECHECK );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, status, fails, checked_at FROM {$table} WHERE checked_at IS NULL OR checked_at < %s ORDER BY checked_at IS NOT NULL, checked_at ASC LIMIT %d", $due, $limit ), ARRAY_A );

		$count = array( 'checked' => 0, 'ok' => 0, 'broken' => 0, 'unreachable' => 0 );
		foreach ( (array) $rows as $r ) {
			list( $state, $code ) = self::probe( (string) $r['url'] );
			$fails  = (int) $r['fails'];
			$status = 'ok';
			if ( 'dead' === $state ) {
				++$fails;
				$status = $fails >= 2 ? 'broken' : 'fail1';
			} elseif ( 'unreachable' === $state ) {
				$status = 'broken' === $r['status'] ? 'broken' : 'unreachable';
			} else {
				$fails = 0;
			}
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'status'     => $status,
					'code'       => $code,
					'fails'      => min( 255, $fails ),
					'checked_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $r['id'] )
			);
			++$count['checked'];
			++$count[ 'broken' === $status ? 'broken' : ( in_array( $status, array( 'unreachable' ), true ) ? 'unreachable' : 'ok' ) ];
		}
		$count['offline'] = $count['checked'] >= 5 && $count['unreachable'] / $count['checked'] > 0.8;
		$count['at']      = time();
		update_option( self::OPTION_RUN, $count, false );
		return $count;
	}

	/**
	 * Adds external URLs found by the scan; forgets ones no longer linked.
	 */
	public static function sync(): void {
		global $wpdb;
		$links = Schema::table( 'links' );
		$table = Schema::table( 'extlinks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$urls = $wpdb->get_col( "SELECT DISTINCT target_url FROM {$links} WHERE link_type = 'external' AND source_type = 'content' LIMIT 20000" );
		$keep = array();
		$now  = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$have = array_flip( (array) $wpdb->get_col( "SELECT url_hash FROM {$table}" ) );
		foreach ( (array) $urls as $url ) {
			if ( ! preg_match( '#^https?://#i', (string) $url ) ) {
				continue;
			}
			$hash          = md5( (string) $url );
			$keep[ $hash ] = true;
			if ( ! isset( $have[ $hash ] ) ) {
				$wpdb->insert( $table, array( 'url_hash' => $hash, 'url' => mb_substr( (string) $url, 0, 1000 ), 'status' => 'pending', 'first_seen' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		foreach ( array_keys( $have ) as $hash ) {
			if ( ! isset( $keep[ $hash ] ) ) {
				$wpdb->delete( $table, array( 'url_hash' => $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * One request: ok, dead (404/410) or unreachable.
	 *
	 * @param string $url URL.
	 * @return array{0: string, 1: int}
	 */
	public static function probe( string $url ): array {
		$args = array(
			'timeout'     => 8,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; PaydarPress link check; +' . home_url( '/' ) . ')',
			'sslverify'   => true,
		);
		$res  = wp_safe_remote_head( $url, $args );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 403, 405, 501 ), true ) ) {
			$res  = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 1024 ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		}
		if ( $code >= 200 && $code < 400 ) {
			return array( 'ok', $code );
		}
		if ( 404 === $code || 410 === $code ) {
			return array( 'dead', $code );
		}
		return array( 'unreachable', $code );
	}

	/**
	 * Summary for the settings and broken-links page.
	 */
	public static function status(): array {
		global $wpdb;
		$table = Schema::table( 'extlinks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$by   = array();
		foreach ( (array) $rows as $r ) {
			$by[ $r['status'] ] = (int) $r['n'];
		}
		$last = get_option( self::OPTION_RUN, array() );
		return array(
			'enabled' => (bool) Settings::get( 'links.check_external', false ),
			'counts'  => $by,
			'last'    => is_array( $last ) && ! empty( $last['at'] ) ? $last + array( 'iso' => gmdate( 'c', (int) $last['at'] ) ) : null,
		);
	}
}
