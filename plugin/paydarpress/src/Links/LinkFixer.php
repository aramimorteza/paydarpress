<?php
/**
 * Broken links, images and files in content: what is wrong, the suggested fix, and the fix
 * itself in one click (change the address, remove the link but keep its text, remove a
 * missing image, or one 301 redirect that repairs every page linking to a deleted address).
 *
 * Every edit is a normal post update (WordPress keeps a revision) and is logged in the
 * change report. Elementor pages are not edited (their content lives in Elementor data).
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Strategy\ChangeLog;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Broken link problems and fixes.
 */
final class LinkFixer {

	public const WHY = array(
		'missing'     => 'این صفحه وجود ندارد (حذف شده یا آدرس اشتباه است)',
		'unpublished' => 'صفحه مقصد منتشر نشده است (پیش‌نویس یا زباله‌دان)',
		'redirect'    => 'آدرس قدیمی صفحه است؛ از مسیر ریدایرکت باز می‌شود',
		'image'       => 'فایل تصویر روی هاست نیست',
		'asset'       => 'فایل روی هاست نیست',
		'external'    => 'سایت مقصد دو هفته پشت سر هم ۴۰۴ یا ۴۱۰ داده است',
	);

	/**
	 * URL helper.
	 *
	 * @var UrlNormalizer|null
	 */
	private static $urls = null;

	/**
	 * 404 analysis context and resolver, built once per request.
	 *
	 * @var array|null
	 */
	private static $ctx = null;

	/**
	 * Normalizer.
	 */
	private static function urls(): UrlNormalizer {
		if ( null === self::$urls ) {
			self::$urls = new UrlNormalizer( home_url( '/' ) );
		}
		return self::$urls;
	}

	/**
	 * Problems of one post, each with a suggested fix.
	 *
	 * @param int $post_id Post.
	 */
	public static function problems( int $post_id ): array {
		global $wpdb;
		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT target_url, anchor, link_type, target_state FROM {$table} WHERE source_id = %d AND source_type = 'content' AND ( ( link_type = 'internal' AND target_state IN ('missing','unpublished','redirect') ) OR ( link_type IN ('image','asset') AND target_state = 'missing' ) OR link_type = 'external' )", $post_id ), ARRAY_A );

		$dead = array();
		if ( Settings::get( 'links.check_external', false ) ) {
			$ext = Schema::table( 'extlinks' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dead = (array) $wpdb->get_results( "SELECT url_hash, code FROM {$ext} WHERE status = 'broken'", OBJECT_K );
		}

		$out  = array();
		$seen = array();
		foreach ( (array) $rows as $r ) {
			$kind = 'internal' === $r['link_type'] ? (string) $r['target_state'] : (string) $r['link_type'];
			if ( 'external' === $kind && ! isset( $dead[ md5( (string) $r['target_url'] ) ] ) ) {
				continue;
			}
			$key = $kind . '|' . $r['target_url'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'url'    => (string) $r['target_url'],
				'label'  => rawurldecode( (string) $r['target_url'] ),
				'anchor' => (string) $r['anchor'],
				'kind'   => $kind,
				'why'    => self::WHY[ $kind ] ?? '',
				'fix'    => self::suggest( $kind, (string) $r['target_url'] ),
				'others' => in_array( $kind, array( 'missing', 'unpublished' ), true ) ? self::sources_count( (string) $r['target_url'] ) - 1 : 0,
			);
		}
		return $out;
	}

	/**
	 * Posts with problems (for the weekly tasks), most problems first.
	 *
	 * @param int $limit Posts.
	 * @return array<int, int> post id => problems.
	 */
	public static function posts( int $limit = 20 ): array {
		global $wpdb;
		$findings = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, data FROM {$findings} WHERE state = 'open' AND object_kind = 'content' AND code IN ('BROKEN_INTERNAL_LINK','BROKEN_IMAGE','BROKEN_EXTERNAL_LINK','REDIRECTED_INTERNAL_LINK') LIMIT %d", $limit * 4 ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$d                                = json_decode( (string) $r['data'], true );
			$out[ (int) $r['object_id'] ] = ( $out[ (int) $r['object_id'] ] ?? 0 ) + (int) ( $d['count'] ?? 1 );
		}
		arsort( $out );
		return array_slice( $out, 0, $limit, true );
	}

	/**
	 * Suggested fix for one problem.
	 *
	 * @param string $kind Kind.
	 * @param string $url  Address.
	 */
	public static function suggest( string $kind, string $url ): array {
		if ( 'image' === $kind || 'asset' === $kind ) {
			return array( 'action' => 'remove', 'label' => 'image' === $kind ? 'برداشتن تصویر از متن' : 'برداشتن لینک فایل (متن می‌ماند)' );
		}
		if ( 'external' === $kind ) {
			return array( 'action' => 'remove', 'label' => 'برداشتن لینک (متن می‌ماند)' );
		}
		$target = self::candidate( $url );
		if ( null !== $target ) {
			return array(
				'action'    => 'replace',
				'target_id' => $target['id'],
				'title'     => $target['title'],
				'reason'    => $target['reason'],
				'label'     => 'تغییر آدرس به «' . $target['title'] . '»',
			);
		}
		return array( 'action' => 'remove', 'label' => 'برداشتن لینک (متن می‌ماند)' );
	}

	/**
	 * The page an internal address should point to now: its current address (old slug),
	 * a PaydarPress redirect target, or the 404 analysis (trash, merged, moved, similar).
	 *
	 * @param string $url Address.
	 * @return array{id: int, title: string, reason: string}|null
	 */
	public static function candidate( string $url ): ?array {
		$urls = self::urls();
		if ( null === self::$ctx ) {
			self::$ctx = array( new LinkResolver(), \PaydarWP\PaydarPress\Gsc\NotFound::context( $urls ) );
		}
		$resolver = self::$ctx[0];
		list( $id, $state ) = $resolver->resolve( $url );
		if ( 'redirect' === $state && $id > 0 && 'publish' === get_post_status( (int) $id ) ) {
			return self::target( (int) $id, 'آدرس فعلی همین صفحه' );
		}
		$path = \PaydarWP\PaydarPress\Strategy\Redirects::path( $url );
		global $wpdb;
		$table = Schema::table( 'redirects' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$red = $wpdb->get_row( $wpdb->prepare( "SELECT target_id FROM {$table} WHERE source_hash = %s", md5( $path ) ), ARRAY_A );
		if ( $red && $red['target_id'] && 'publish' === get_post_status( (int) $red['target_id'] ) ) {
			return self::target( (int) $red['target_id'], 'مقصد ریدایرکت همین آدرس' );
		}
		$a = \PaydarWP\PaydarPress\Gsc\NotFound::analyse( $url, rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), $urls, $resolver, self::$ctx[1] );
		if ( ! empty( $a['candidate_id'] ) && 'publish' === get_post_status( (int) $a['candidate_id'] ) ) {
			return self::target( (int) $a['candidate_id'], \PaydarWP\PaydarPress\Gsc\NotFound::REASON_LABELS[ $a['reason'] ] ?? '' );
		}
		return null;
	}

	/**
	 * Target summary.
	 *
	 * @param int    $id     Post.
	 * @param string $reason Why.
	 */
	private static function target( int $id, string $reason ): array {
		return array(
			'id'     => $id,
			'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'reason' => $reason,
		);
	}

	/**
	 * How many published posts link to an address.
	 *
	 * @param string $url Address.
	 */
	public static function sources_count( string $url ): int {
		return count( self::sources( $url ) );
	}

	/**
	 * Posts linking to an address.
	 *
	 * @param string $url Address.
	 * @return int[]
	 */
	public static function sources( string $url ): array {
		global $wpdb;
		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$table} WHERE source_type = 'content' AND target_url = %s", $url ) ) );
	}

	/**
	 * Applies a fix.
	 *
	 * @param int    $post_id   Post.
	 * @param string $url       Broken address.
	 * @param string $action    replace|remove|redirect.
	 * @param int    $target_id New target (replace, redirect).
	 * @throws RuntimeException On failure.
	 */
	public static function fix( int $post_id, string $url, string $action, int $target_id = 0 ): array {
		if ( 'redirect' === $action ) {
			if ( $target_id <= 0 || 'publish' !== get_post_status( $target_id ) ) {
				throw new RuntimeException( 'مقصد ریدایرکت باید یک مطلب منتشرشده باشد.' );
			}
			$r       = \PaydarWP\PaydarPress\Strategy\RedirectOwner::ensure( $url, $target_id, 'broken' );
			$sources = self::sources( $url );
			ChangeLog::record( 'redirect', $target_id, 'آدرس خراب «' . rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . '» به این صفحه ریدایرکت شد', 'post', sprintf( '%d صفحه به این آدرس لینک می‌دادند.', count( $sources ) ) );
			self::rescan( $sources );
			return array( 'ok' => true, 'message' => $r['message'] . ' لینک‌های داخلی همچنان بهتر است به آدرس تازه تغییر کنند تا از ریدایرکت عبور نکنند.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'اجازه ویرایش این نوشته را ندارید.' );
		}
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			throw new RuntimeException( 'این صفحه با المنتور ساخته شده؛ لینک را در ویرایشگر المنتور اصلاح کنید.' );
		}

		$new_url = '';
		if ( 'replace' === $action ) {
			if ( $target_id <= 0 || 'publish' !== get_post_status( $target_id ) ) {
				throw new RuntimeException( 'مقصد تازه باید یک مطلب منتشرشده باشد.' );
			}
			$new_url = (string) get_permalink( $target_id );
		} elseif ( 'remove' !== $action ) {
			throw new RuntimeException( 'اقدام نامعتبر است.' );
		}

		list( $content, $changed, $anchor ) = self::edit( (string) $post->post_content, $url, $new_url, (string) get_permalink( $post ) );
		if ( 0 === $changed ) {
			throw new RuntimeException( 'این لینک در متن پیدا نشد (شاید در قالب یا ابزارک است). در ویرایشگر بررسی کنید.' );
		}
		$r = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $content ) ), true );
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( 'ذخیره نوشته ناموفق بود: ' . $r->get_error_message() );
		}

		$label = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		ChangeLog::record( 'link', $post_id, 'replace' === $action ? 'لینک خراب «' . $label . '» به «' . get_the_title( $target_id ) . '» تغییر کرد' : 'لینک یا تصویر خراب «' . $label . '» برداشته شد' );

		$msg = 'replace' === $action ? 'آدرس لینک اصلاح شد.' : ( '' === trim( $anchor ) ? 'از متن برداشته شد.' : 'برداشته شد؛ متن لینک در جای خودش ماند.' );
		// The removed link's text is still in the article: offer a related page for it.
		if ( 'remove' === $action && '' !== trim( $anchor ) && ! str_starts_with( $anchor, '<' ) ) {
			$related = self::related( $post_id );
			if ( $related > 0 && \PaydarWP\PaydarPress\Import\LinkSuggestionRepository::insert( $post_id, $related, $anchor, '', 'broken', 0, 'pending' ) > 0 ) {
				$msg .= ' پیشنهاد لینک از همین متن به «' . get_the_title( $related ) . '» به صف لینک داخلی رفت.';
			}
		}
		return array( 'ok' => true, 'message' => $msg );
	}

	/**
	 * Changes or removes every link (and image) to $url in the HTML.
	 *
	 * @param string $html    Content.
	 * @param string $url     Broken address.
	 * @param string $new_url New address ('' = remove).
	 * @param string $base    Post URL (for relative links).
	 * @return array{0: string, 1: int, 2: string} New HTML, changes, the first link text.
	 */
	public static function edit( string $html, string $url, string $new_url, string $base ): array {
		$urls    = self::urls();
		$key     = $urls->key( $url );
		$changed = 0;
		$anchor  = '';
		$match   = static function ( string $href ) use ( $urls, $key, $base ): bool {
			$abs = $urls->absolute( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ), $base );
			return null !== $abs && $urls->key( $abs ) === $key;
		};

		// Links: change the href, or unwrap the <a> keeping its text.
		$html = (string) preg_replace_callback(
			'#<a\b([^>]*?)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>(.*?)</a>#isu',
			static function ( $m ) use ( $match, $new_url, &$changed, &$anchor ) {
				if ( ! $match( $m[3] ) ) {
					return $m[0];
				}
				++$changed;
				if ( '' === $anchor ) {
					$anchor = trim( wp_strip_all_tags( $m[5] ) );
				}
				return '' === $new_url ? $m[5] : '<a' . $m[1] . 'href=' . $m[2] . esc_url( $new_url ) . $m[2] . $m[4] . '>' . $m[5] . '</a>';
			},
			$html
		);

		if ( '' === $new_url ) {
			// Image blocks whose file is gone, then bare <img> tags.
			$html = (string) preg_replace_callback(
				'#<!--\s*wp:image\b.*?-->.*?<!--\s*/wp:image\s*-->\s*#isu',
				static function ( $m ) use ( $match, &$changed ) {
					if ( preg_match( '#<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1#isu', $m[0], $img ) && $match( $img[2] ) ) {
						++$changed;
						return '';
					}
					return $m[0];
				},
				$html
			);
			$html = (string) preg_replace_callback(
				'#<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1[^>]*>#isu',
				static function ( $m ) use ( $match, &$changed ) {
					if ( $match( $m[2] ) ) {
						++$changed;
						return '';
					}
					return $m[0];
				},
				$html
			);
		}
		return array( $html, $changed, $anchor );
	}

	/**
	 * A published post on the same subject not yet linked from this one (cluster sibling,
	 * then the most similar title in the same category).
	 *
	 * @param int $post_id Source.
	 */
	public static function related( int $post_id ): int {
		global $wpdb;
		$cluster = \PaydarWP\PaydarPress\Strategy\Clusters::of_post( $post_id );
		$links   = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$linked = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT target_id FROM {$links} WHERE source_id = %d AND target_id IS NOT NULL", $post_id ) ) ) );
		if ( $cluster && ! empty( $cluster['pillar_id'] ) && (int) $cluster['pillar_id'] !== $post_id && ! isset( $linked[ (int) $cluster['pillar_id'] ] ) ) {
			return (int) $cluster['pillar_id'];
		}
		$inv   = Schema::table( 'inventory' );
		$title = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title_norm FROM {$inv} WHERE object_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$words = array_filter( explode( ' ', $title ), static fn( $w ) => mb_strlen( $w ) > 2 );
		$cats  = wp_get_post_categories( $post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_id, title_norm, primary_term_id FROM {$inv} WHERE status = 'publish' AND object_type = 'post' LIMIT 5000", ARRAY_A );
		$best = 0;
		$top  = 0;
		foreach ( (array) $rows as $r ) {
			$id = (int) $r['object_id'];
			if ( $id === $post_id || isset( $linked[ $id ] ) ) {
				continue;
			}
			$score = in_array( (int) $r['primary_term_id'], $cats, true ) ? 1 : 0;
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( ' ' . $r['title_norm'] . ' ', ' ' . $w . ' ' ) ) {
					$score += 2;
				}
			}
			if ( $score > $top ) {
				$top  = $score;
				$best = $id;
			}
		}
		return $top >= 3 ? $best : 0;
	}

	/**
	 * Rescans posts after a fix so their findings update.
	 *
	 * @param int[] $ids Posts.
	 */
	private static function rescan( array $ids ): void {
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			\PaydarWP\PaydarPress\Queue\Queue::schedule( \PaydarWP\PaydarPress\Scanner\ScanManager::ACTION_ITEM, array( (int) $id ), 5 );
		}
	}
}

