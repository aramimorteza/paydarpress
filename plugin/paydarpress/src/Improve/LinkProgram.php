<?php
/**
 * Internal link program: every article has an inbound-link target; each week PaydarPress
 * discovers new link suggestions for the articles furthest below it (key pages and pages
 * Google already shows first). Suggestions wait in the link queue for approval.
 *
 * @package PaydarPress
 */

namespace PaydarWP\PaydarPress\Improve;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound targets and weekly discovery.
 */
final class LinkProgram {

	/** Last weekly discovery: timestamp. */
	public const OPTION_RUN = 'paydarpress_linkprogram_run';

	/** Key pages and cluster pillars need this many times the normal target. */
	public const KEY_FACTOR = 2;

	/**
	 * Inbound target of a normal article.
	 */
	public static function target(): int {
		return max( 1, (int) Settings::get( 'improve.inbound_target', 3 ) );
	}

	/**
	 * Ids of key pages (posts/pages/products) and cluster pillars: id => true.
	 */
	private static function important(): array {
		global $wpdb;
		$ids = array();
		foreach ( array_keys( \PaydarWP\PaydarPress\Links\KeyPageLinks::pages() ) as $id ) {
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		$t = Schema::table( 'clusters' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( "SELECT pillar_id FROM {$t} WHERE pillar_id IS NOT NULL" ) as $id ) {
			$ids[ (int) $id ] = true;
		}
		return $ids;
	}

	/**
	 * Published items below their inbound target, most important first: key pages and
	 * pillars, then by Google impressions, then fewest inbound links.
	 *
	 * @return array{items: array<int, array>, total:int, ok:int}
	 */
	public static function gaps(): array {
		global $wpdb;
		$inv = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = (array) $wpdb->get_results( "SELECT object_id, object_type, internal_in, menu_in FROM {$inv} WHERE status = 'publish' AND object_type IN ('post','page','product') AND is_noindex = 0", ARRAY_A );
		$system  = array_flip( \PaydarWP\PaydarPress\Audit\AuditEngine::system_page_ids() );
		$imp     = self::important();
		$metrics = GscRepository::page_metrics();
		$base    = self::target();
		$items   = array();
		$ok      = 0;
		$total   = 0;
		foreach ( $rows as $r ) {
			$id = (int) $r['object_id'];
			if ( isset( $system[ $id ] ) ) {
				continue;
			}
			$key = isset( $imp[ $id ] );
			// Plain pages (about, contact) only count when they are key pages.
			if ( 'page' === $r['object_type'] && ! $key ) {
				continue;
			}
			++$total;
			$target = $key ? $base * self::KEY_FACTOR : $base;
			$have   = (int) $r['internal_in'];
			if ( $have >= $target ) {
				++$ok;
				continue;
			}
			$items[] = array(
				'id'          => $id,
				'type'        => (string) $r['object_type'],
				'have'        => $have,
				'target'      => $target,
				'key'         => $key,
				'impressions' => (int) ( $metrics[ $id ]['impressions'] ?? 0 ),
			);
		}
		usort( $items, static fn( $a, $b ) => array( $b['key'], $b['impressions'], $a['have'] ) <=> array( $a['key'], $a['impressions'], $b['have'] ) );
		return array(
			'items' => $items,
			'total' => $total,
			'ok'    => $ok,
		);
	}

	/**
	 * Suggestions still waiting for approval.
	 */
	public static function pending(): int {
		global $wpdb;
		$t = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'pending'" );
	}

	/**
	 * Weekly discovery: new suggestions for the items furthest below their target, as many
	 * as the weekly link budget allows minus what is already waiting in the queue.
	 *
	 * @param bool $force Run even if it ran this week.
	 * @return array{created:int, targets:int, room:int, skipped:string}
	 */
	public static function discover( bool $force = false ): array {
		$last = (int) get_option( self::OPTION_RUN, 0 );
		if ( ! $force && $last > time() - WEEK_IN_SECONDS + HOUR_IN_SECONDS ) {
			return array( 'created' => 0, 'targets' => 0, 'room' => 0, 'skipped' => 'این هفته انجام شده است.' );
		}
		$room = (int) Settings::get( 'policy.links_per_week', 15 ) - self::pending();
		if ( $room <= 0 ) {
			update_option( self::OPTION_RUN, time(), false );
			return array( 'created' => 0, 'targets' => 0, 'room' => 0, 'skipped' => 'صف لینک هنوز پیشنهاد بررسی‌نشده دارد؛ اول آن‌ها را تأیید یا رد کنید.' );
		}
		$gaps    = self::gaps();
		$targets = array();
		foreach ( $gaps['items'] as $g ) {
			$targets[ $g['id'] ] = min( 3, $g['target'] - $g['have'] );
			if ( count( $targets ) >= 60 ) {
				break;
			}
		}
		$res = ( new \PaydarWP\PaydarPress\Links\LinkQueue() )->generate_for( $targets, $room );
		update_option( self::OPTION_RUN, time(), false );
		return array(
			'created' => $res['created'],
			'targets' => $res['targets'],
			'room'    => $room,
			'skipped' => '',
		);
	}

	/**
	 * Program state for the progress page.
	 */
	public static function state(): array {
		$g    = self::gaps();
		$top  = array();
		foreach ( array_slice( $g['items'], 0, 10 ) as $i ) {
			$top[] = $i + array(
				'title' => html_entity_decode( get_the_title( $i['id'] ), ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( $i['id'] ),
			);
		}
		$last = (int) get_option( self::OPTION_RUN, 0 );
		return array(
			'target'     => self::target(),
			'key_target' => self::target() * self::KEY_FACTOR,
			'total'      => $g['total'],
			'ok'         => $g['ok'],
			'below'      => count( $g['items'] ),
			'percent'    => $g['total'] > 0 ? (int) round( 100 * $g['ok'] / $g['total'] ) : 100,
			'top'        => $top,
			'pending'    => self::pending(),
			'per_week'   => (int) Settings::get( 'policy.links_per_week', 15 ),
			'last_run'   => $last > 0 ? wp_date( 'Y-m-d', $last ) : '',
			'next_run'   => $last > 0 ? wp_date( 'Y-m-d', $last + WEEK_IN_SECONDS ) : '',
		);
	}
}
