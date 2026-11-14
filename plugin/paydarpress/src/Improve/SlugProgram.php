<?php
/**
 * Address improvement: Persian, numeric and "-2" addresses become short English ones,
 * a few each week, least-visited pages first. Every change gets a 301 (in the SEO or
 * redirect plugin when one manages redirects), internal links to the old address are
 * updated, and the change is measured. If clicks of changed pages drop beyond the brake
 * setting, the program pauses itself.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Improve;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Import\ImportService;
use PaydarWP\PaydarPress\Strategy\ChangeLog;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Slug program.
 */
final class SlugProgram {

	public const OPTION_PLAN  = 'paydarpress_slug_plan';
	public const OPTION_STATE = 'paydarpress_slug_state';
	public const TYPES        = array( 'post', 'page', 'product' );

	/** A page is "busy" (changed only with a separate confirmation) above these numbers. */
	public const BUSY_CLICKS      = 50;
	public const BUSY_IMPRESSIONS = 1000;

	/**
	 * Valid English slug.
	 *
	 * @param string $slug Slug.
	 */
	public static function valid( string $slug ): bool {
		return (bool) preg_match( '/^[a-z0-9]+(-[a-z0-9]+){0,7}$/', $slug ) && strlen( $slug ) <= 70;
	}

	/**
	 * Every published item with a poor address, least risky first.
	 */
	public static function items(): array {
		global $wpdb;
		$in   = "'" . implode( "','", self::TYPES ) . "'";
		$rows = $wpdb->get_results( "SELECT ID, post_name, post_type, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) ORDER BY ID ASC LIMIT 20000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$m    = \PaydarWP\PaydarPress\Gsc\GscRepository::page_metrics();
		$plan = self::plan();
		$out   = array();
		$front = (int) get_option( 'page_on_front' );
		$names = array();
		foreach ( (array) $rows as $p ) {
			$names[ $p->post_type . '|' . $p->post_name ] = true;
		}
		foreach ( (array) $rows as $p ) {
			if ( $front === (int) $p->ID ) {
				continue;
			}
			$slug = rawurldecode( (string) $p->post_name );
			$why  = '';
			if ( preg_match( '/[^\x00-\x7F]/', $slug ) || preg_match( '/^(post|product|page|p)?-?\d+$/', $slug ) ) {
				// Cheap checks first; the full test only for real candidates (no WP_Post per row).
				$why = ImportService::poor_slug( $slug, new \WP_Post( $p ) );
			} elseif ( preg_match( '/^(.+)-([2-9])$/', (string) $p->post_name, $mm ) && isset( $names[ $p->post_type . '|' . $mm[1] ] ) ) {
				$why = 'تکراری است و پسوند «-' . $mm[2] . '» گرفته';
			}
			if ( '' === $why ) {
				continue;
			}
			$clicks = (int) ( $m[ (int) $p->ID ]['clicks'] ?? 0 );
			$imp    = (int) ( $m[ (int) $p->ID ]['impressions'] ?? 0 );
			$out[]  = array(
				'id'          => (int) $p->ID,
				'type'        => (string) $p->post_type,
				'title'       => html_entity_decode( (string) $p->post_title, ENT_QUOTES, 'UTF-8' ),
				'slug'        => $slug,
				'why'         => $why,
				'clicks'      => $clicks,
				'impressions' => $imp,
				'busy'        => $clicks >= self::BUSY_CLICKS || $imp >= self::BUSY_IMPRESSIONS,
				'new'         => $plan[ (int) $p->ID ] ?? '',
			);
		}
		usort( $out, static fn( $a, $b ) => array( $a['busy'], $a['clicks'], $a['impressions'] ) <=> array( $b['busy'], $b['clicks'], $b['impressions'] ) );
		return $out;
	}

	/**
	 * Suggested English slugs: post id => slug.
	 */
	public static function plan(): array {
		$p = get_option( self::OPTION_PLAN, array() );
		return is_array( $p ) ? array_map( 'strval', $p ) : array();
	}

	/**
	 * Stores suggested slugs (only valid ones for items that still need one).
	 *
	 * @param array $map id => slug.
	 * @return int Stored.
	 */
	public static function store( array $map ): int {
		$plan = self::plan();
		$n    = 0;
		foreach ( $map as $id => $slug ) {
			$slug = sanitize_title( (string) $slug );
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || ! self::valid( $slug ) || '' === ImportService::poor_slug( rawurldecode( $post->post_name ), $post ) ) {
				continue;
			}
			$plan[ (int) $id ] = $slug;
			++$n;
		}
		update_option( self::OPTION_PLAN, $plan, false );
		return $n;
	}

	/**
	 * Program state and this week's batch.
	 */
	public static function state(): array {
		$items  = self::items();
		$total  = (int) array_sum( array_map( static fn( $t ) => (int) wp_count_posts( $t )->publish, array_filter( self::TYPES, 'post_type_exists' ) ) );
		$quota  = (int) Settings::get( 'improve.slugs_per_week', 10 );
		$st     = self::status();
		$done   = self::done_this_week();
		$room   = max( 0, $quota - $done );
		$week   = array();
		foreach ( $items as $it ) {
			if ( count( $week ) >= $room ) {
				break;
			}
			$week[] = $it;
		}
		return array(
			'poor'       => count( $items ),
			'total'      => $total,
			'percent'    => $total > 0 ? (int) round( 100 * ( $total - count( $items ) ) / $total ) : 100,
			'planned'    => count( array_filter( $items, static fn( $i ) => '' !== $i['new'] ) ),
			'quota'      => $quota,
			'done_week'  => $done,
			'week'       => $week,
			'paused'     => $st['paused'],
			'pause_why'  => $st['why'],
			'needs_slug' => array_values( array_map( static fn( $i ) => $i['id'], array_filter( $items, static fn( $i ) => '' === $i['new'] ) ) ),
		);
	}

	/**
	 * Changes made in the last 7 days.
	 */
	public static function done_this_week(): int {
		global $wpdb;
		$t = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE kind = 'slug' AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
	}

	/**
	 * Paused? Checks the brake: clicks of measured slug changes now vs before.
	 */
	public static function status(): array {
		$st = get_option( self::OPTION_STATE, array() );
		$st = is_array( $st ) ? $st : array();
		if ( ! empty( $st['paused'] ) ) {
			return array( 'paused' => true, 'why' => (string) ( $st['why'] ?? '' ) );
		}
		global $wpdb;
		$t    = Schema::table( 'changes' );
		$rows = $wpdb->get_results( "SELECT before_metrics, after_metrics FROM {$t} WHERE kind = 'slug' AND measured_at IS NOT NULL AND before_metrics IS NOT NULL AND after_metrics IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$b    = 0;
		$a    = 0;
		foreach ( (array) $rows as $r ) {
			$b += (int) ( json_decode( (string) $r['before_metrics'], true )['clicks'] ?? 0 );
			$a += (int) ( json_decode( (string) $r['after_metrics'], true )['clicks'] ?? 0 );
		}
		$limit = (int) Settings::get( 'improve.brake_drop', 20 );
		if ( count( (array) $rows ) >= 3 && $b >= 30 && $a < $b * ( 1 - $limit / 100 ) ) {
			$why = sprintf( 'کلیک صفحه‌هایی که آدرسشان عوض شد از %s به %s رسید (بیش از %s٪ افت). برنامه آدرس‌ها متوقف شد تا بررسی کنید.', PersianNormalizer::fa_number( $b ), PersianNormalizer::fa_number( $a ), PersianNormalizer::fa_number( $limit ) );
			update_option( self::OPTION_STATE, array( 'paused' => true, 'why' => $why, 'at' => time() ), false );
			\PaydarWP\PaydarPress\Core\Activity::log( 'improve.slugs_paused', $why );
			return array( 'paused' => true, 'why' => $why );
		}
		return array( 'paused' => false, 'why' => '' );
	}

	/**
	 * Resumes after a pause.
	 */
	public static function resume(): void {
		update_option( self::OPTION_STATE, array( 'paused' => false, 'resumed' => time() ), false );
	}

	/**
	 * Changes the address of the given items.
	 *
	 * @param array $items   [ { id, slug } ].
	 * @param bool  $busy_ok The owner confirmed busy pages.
	 * @return array{done: array, errors: array}
	 * @throws RuntimeException When paused.
	 */
	public static function apply( array $items, bool $busy_ok = false ): array {
		if ( self::status()['paused'] ) {
			throw new RuntimeException( 'برنامه آدرس‌ها به دلیل افت کلیک متوقف است؛ پیش از ادامه، «ادامه برنامه» را بزنید.' );
		}
		$m      = \PaydarWP\PaydarPress\Gsc\GscRepository::page_metrics();
		$done   = array();
		$errors = array();
		foreach ( array_slice( $items, 0, 50 ) as $it ) {
			$id   = (int) ( $it['id'] ?? 0 );
			$slug = sanitize_title( (string) ( $it['slug'] ?? '' ) );
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! current_user_can( 'edit_post', $id ) ) {
				$errors[] = '#' . $id . ': پیدا نشد یا اجازه ویرایش ندارید.';
				continue;
			}
			if ( ! self::valid( $slug ) ) {
				$errors[] = '«' . $post->post_title . '»: نامک انگلیسی معتبر نیست (حروف کوچک لاتین، عدد و خط تیره).';
				continue;
			}
			$busy = (int) ( $m[ $id ]['clicks'] ?? 0 ) >= self::BUSY_CLICKS || (int) ( $m[ $id ]['impressions'] ?? 0 ) >= self::BUSY_IMPRESSIONS;
			if ( $busy && ! $busy_ok ) {
				$errors[] = '«' . $post->post_title . '»: صفحه پربازدید است؛ تأیید جداگانه لازم دارد.';
				continue;
			}
			$old = (string) get_permalink( $post );
			$r   = wp_update_post( array( 'ID' => $id, 'post_name' => $slug ), true );
			if ( is_wp_error( $r ) ) {
				$errors[] = '«' . $post->post_title . '»: ' . $r->get_error_message();
				continue;
			}
			clean_post_cache( $id );
			$new = (string) get_permalink( $id );
			if ( $new === $old ) {
				continue;
			}
			try {
				$red = \PaydarWP\PaydarPress\Strategy\RedirectOwner::ensure( $old, $id, 'slug' );
			} catch ( \RuntimeException $e ) {
				$red = array( 'message' => 'ریدایرکت وردپرس (نامک قبلی)' );
			}
			$links = self::update_links( $old, $new );
			ChangeLog::record( 'slug', $id, 'آدرس «' . rawurldecode( (string) wp_parse_url( $old, PHP_URL_PATH ) ) . '» ← «' . rawurldecode( (string) wp_parse_url( $new, PHP_URL_PATH ) ) . '»' . ( $links ? '؛ ' . PersianNormalizer::fa_number( $links ) . ' لینک داخلی به‌روز شد' : '' ) );
			$plan = self::plan();
			unset( $plan[ $id ] );
			update_option( self::OPTION_PLAN, $plan, false );
			$done[] = array( 'id' => $id, 'title' => $post->post_title, 'url' => $new, 'links' => $links, 'redirect' => $red['message'] );
		}
		return array( 'done' => $done, 'errors' => $errors );
	}

	/**
	 * Points internal links at the new address (so they do not go through the redirect).
	 *
	 * @param string $old Old URL.
	 * @param string $new New URL.
	 * @return int Links changed.
	 */
	public static function update_links( string $old, string $new ): int {
		$n = 0;
		foreach ( array_unique( array_merge( \PaydarWP\PaydarPress\Links\LinkFixer::sources( $old ), \PaydarWP\PaydarPress\Links\LinkFixer::sources( rawurldecode( $old ) ) ) ) as $sid ) {
			$src = get_post( (int) $sid );
			if ( ! $src instanceof \WP_Post || 'builder' === get_post_meta( $src->ID, '_elementor_edit_mode', true ) ) {
				continue;
			}
			list( $html, $changed ) = \PaydarWP\PaydarPress\Links\LinkFixer::edit( (string) $src->post_content, $old, $new, (string) get_permalink( $src ) );
			if ( $changed > 0 ) {
				wp_update_post( array( 'ID' => $src->ID, 'post_content' => wp_slash( $html ) ) );
				$n += $changed;
			}
		}
		return $n;
	}
}
