<?php
/**
 * 301 redirects for merged or deleted content, and merging one article into another.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\UrlNormalizer;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects only run for requests that WordPress could not resolve (404), so normal pages
 * are never slowed down and an existing page is never hidden by a redirect.
 */
final class Redirects {

	/**
	 * Public hook.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirects a 404 request whose path has a redirect.
	 */
	public function maybe_redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$path = self::path( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $path ) {
			return;
		}
		global $wpdb;
		$table = Schema::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, target_url, target_id FROM {$table} WHERE source_hash = %s", md5( $path ) ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		$target = $row['target_id'] ? (string) get_permalink( (int) $row['target_id'] ) : (string) $row['target_url'];
		if ( '' === $target || self::path( $target ) === $path ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), (int) $row['id'] ) );
		wp_safe_redirect( $target, 301, 'PaydarPress' );
		exit;
	}

	/**
	 * Normalised, decoded path of a URL (no query, no trailing slash).
	 *
	 * @param string $url URL or path.
	 */
	public static function path( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		$p = rawurldecode( $p );
		$p = '/' . trim( $p, '/' );
		return '/' === $p ? '' : mb_strtolower( $p );
	}

	/**
	 * Adds (or updates) a redirect.
	 *
	 * @param string   $source    Old URL or path (on this site).
	 * @param string   $target    Target URL.
	 * @param int|null $target_id Target post (follows its address if it changes).
	 * @param string   $reason    merge|deleted|manual.
	 * @throws RuntimeException On invalid input.
	 */
	public static function add( string $source, string $target, ?int $target_id, string $reason ): int {
		global $wpdb;
		$path = self::path( $source );
		if ( '' === $path ) {
			throw new RuntimeException( 'آدرس مبدأ معتبر نیست (صفحه اصلی را نمی‌توان ریدایرکت کرد).' );
		}
		$urls = new UrlNormalizer( home_url( '/' ) );
		$abs  = $urls->absolute( $target, home_url( '/' ) );
		if ( null === $abs || ! $urls->is_internal( $abs ) ) {
			throw new RuntimeException( 'مقصد باید آدرسی از همین سایت باشد.' );
		}
		if ( self::path( $abs ) === $path ) {
			throw new RuntimeException( 'مبدأ و مقصد یکی است.' );
		}
		$table = Schema::table( 'redirects' );
		$data  = array(
			'source_path' => mb_substr( $path, 0, 500 ),
			'source_hash' => md5( $path ),
			'target_url'  => esc_url_raw( $abs ),
			'target_id'   => $target_id,
			'reason'      => sanitize_key( $reason ),
			'created_at'  => current_time( 'mysql', true ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_hash = %s", $data['source_hash'] ) );
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new = (int) $wpdb->insert_id;
		self::flatten();
		return $new;
	}

	/**
	 * Shortens chains: when a redirect's target is itself redirected (A → B, then B → C),
	 * A points straight to C. Only PaydarPress's own redirects; content does not change,
	 * so this runs automatically (daily and after each new redirect).
	 *
	 * @return int Redirects shortened.
	 */
	public static function flatten(): int {
		global $wpdb;
		$table = Schema::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, source_path, target_url, target_id FROM {$table}", ARRAY_A );
		$by   = array();
		foreach ( (array) $rows as $r ) {
			$by[ (string) $r['source_path'] ] = $r;
		}
		$n = 0;
		foreach ( (array) $rows as $r ) {
			$url  = (string) $r['target_url'];
			$id   = $r['target_id'] ? (int) $r['target_id'] : null;
			$hops = 0;
			while ( $hops < 5 && isset( $by[ self::path( $url ) ] ) && self::path( $url ) !== (string) $r['source_path'] ) {
				$next = $by[ self::path( $url ) ];
				$url  = (string) $next['target_url'];
				$id   = $next['target_id'] ? (int) $next['target_id'] : null;
				++$hops;
			}
			if ( $hops > 0 && self::path( $url ) !== (string) $r['source_path'] ) {
				$wpdb->update( $table, array( 'target_url' => esc_url_raw( $url ), 'target_id' => $id ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$n;
			}
		}
		if ( $n > 0 ) {
			\PaydarWP\PaydarPress\Core\Activity::log( 'redirects.flattened', sprintf( '%d ریدایرکت زنجیره‌ای کوتاه شد و مستقیم به مقصد نهایی می‌رود.', $n ) );
		}
		return $n;
	}

	/**
	 * All redirects.
	 */
	public static function all(): array {
		global $wpdb;
		$table = Schema::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500", ARRAY_A );
		return array_map(
			static fn( $r ) => array(
				'id'       => (int) $r['id'],
				'source'   => $r['source_path'],
				'target'   => $r['target_id'] ? rawurldecode( (string) get_permalink( (int) $r['target_id'] ) ) : rawurldecode( (string) $r['target_url'] ),
				'reason'   => $r['reason'],
				'hits'     => (int) $r['hits'],
				'last_hit' => $r['last_hit'] ? gmdate( 'c', (int) strtotime( $r['last_hit'] . ' UTC' ) ) : null,
				'created'  => gmdate( 'c', (int) strtotime( $r['created_at'] . ' UTC' ) ),
			),
			(array) $rows
		);
	}

	/**
	 * Deletes a redirect.
	 *
	 * @param int $id Redirect.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'redirects' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merges an article into another: the old one becomes a draft, its address redirects
	 * (301) to the target, and internal links to it are pointed to the target.
	 *
	 * @param int $from Article being retired.
	 * @param int $into Article that stays.
	 * @return array{redirect: int, message: string, links: int}
	 * @throws RuntimeException On invalid input.
	 */
	public static function merge( int $from, int $into ): array {
		global $wpdb;
		$a = get_post( $from );
		$b = get_post( $into );
		if ( ! $a || ! $b || $from === $into || 'publish' !== $b->post_status ) {
			throw new RuntimeException( 'مقاله مقصد باید منتشرشده و متفاوت با مقاله فعلی باشد.' );
		}
		if ( ! current_user_can( 'edit_post', $from ) || ! current_user_can( 'edit_post', $into ) ) {
			throw new RuntimeException( 'اجازه ویرایش این مقاله‌ها را ندارید.' );
		}
		$old_url = (string) get_permalink( $from );
		$new_url = (string) get_permalink( $into );

		// Internal links: every published item that links to the old address now links to the target.
		$urls   = new UrlNormalizer( home_url( '/' ) );
		$old_k  = $urls->key( $old_url );
		$links  = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sources = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$links} WHERE target_id = %d AND source_type = 'content'", $from ) );
		$changed = 0;
		foreach ( array_map( 'intval', (array) $sources ) as $sid ) {
			if ( $sid === $from || $sid === $into ) {
				continue;
			}
			$content = (string) get_post_field( 'post_content', $sid );
			$new     = preg_replace_callback(
				'#(<a\b[^>]*\bhref\s*=\s*)(["\'])(.*?)\2#isu',
				static function ( $m ) use ( $urls, $old_k, $new_url ) {
					$abs = $urls->absolute( html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' ), home_url( '/' ) );
					return null !== $abs && $urls->key( $abs ) === $old_k ? $m[1] . $m[2] . esc_url( $new_url ) . $m[2] : $m[0];
				},
				$content
			);
			if ( is_string( $new ) && $new !== $content ) {
				wp_update_post( wp_slash( array( 'ID' => $sid, 'post_content' => $new ) ) );
				++$changed;
			}
		}

		wp_update_post( array( 'ID' => $from, 'post_status' => 'draft' ) );
		update_post_meta( $from, '_paydarpress_merged_into', $into );

		// One owner per redirect: verify an existing redirect manager first (the old address
		// is a 404 now), otherwise PaydarPress redirects it.
		$redirect = RedirectOwner::ensure( $old_url, $into, 'merge' );

		$title_a = html_entity_decode( get_the_title( $from ), ENT_QUOTES, 'UTF-8' );
		$title_b = html_entity_decode( get_the_title( $into ), ENT_QUOTES, 'UTF-8' );
		ChangeLog::record( 'merge', $into, '«' . $title_a . '» در این مقاله ادغام شد (ریدایرکت ۳۰۱)' );
		Activity::log( 'import.merge', sprintf( '«%s» در «%s» ادغام شد: %s %d نوشته لینک‌شان اصلاح شد.', $title_a, $title_b, $redirect['message'], $changed ), 'post', $into );

		return array(
			'redirect' => $redirect['created'] ? 1 : 0,
			'message'  => $redirect['message'],
			'links'    => $changed,
		);
	}
}
