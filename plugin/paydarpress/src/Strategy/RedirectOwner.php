<?php
/**
 * One owner per redirect: when Rank Math (Redirections module), Yoast Premium or the
 * Redirection plugin manages the site's redirects, PaydarPress does not duplicate their work.
 * After a change it checks whether the old address already redirects to the new one, and
 * only creates its own redirect (which runs on 404 only) when it does not.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

defined( 'ABSPATH' ) || exit;

/**
 * Detection is cheap (class/constant/option checks) and cached per request.
 */
final class RedirectOwner {

	/** @var array|null|false Cached detection (false = not detected yet). */
	private static $owner = false;

	/**
	 * Active redirect manager other than PaydarPress.
	 *
	 * @return array{key: string, name: string, url: string, auto: bool}|null
	 */
	public static function detect(): ?array {
		if ( false !== self::$owner ) {
			return self::$owner;
		}
		self::$owner = null;

		// Rank Math with the Redirections module.
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' ) && \RankMath\Helper::is_module_active( 'redirections' ) ) {
			$auto        = method_exists( '\RankMath\Helper', 'get_settings' ) && 'on' === (string) \RankMath\Helper::get_settings( 'general.redirections_post_redirect' );
			self::$owner = array(
				'key'  => 'rankmath',
				'name' => 'Rank Math (ماژول ریدایرکت‌ها)',
				'url'  => admin_url( 'admin.php?page=rank-math-redirections' ),
				'auto' => $auto,
			);
		} elseif ( defined( 'WPSEO_PREMIUM_VERSION' ) || class_exists( '\WPSEO_Premium' ) ) {
			self::$owner = array(
				'key'  => 'yoast',
				'name' => 'Yoast SEO Premium (مدیر ریدایرکت)',
				'url'  => admin_url( 'admin.php?page=wpseo_redirects' ),
				'auto' => true,
			);
		} elseif ( defined( 'REDIRECTION_VERSION' ) || class_exists( '\Red_Item' ) ) {
			self::$owner = array(
				'key'  => 'redirection',
				'name' => 'افزونه Redirection',
				'url'  => admin_url( 'tools.php?page=redirection.php' ),
				'auto' => false,
			);
		}

		/**
		 * Filters the detected redirect manager (null = none; PaydarPress manages redirects).
		 *
		 * @param array|null $owner Owner.
		 */
		self::$owner = apply_filters( 'paydarpress/redirect_owner', self::$owner );
		return self::$owner;
	}

	/**
	 * Makes sure an old address leads to a post: verify first when another plugin manages
	 * redirects, otherwise (or when nothing redirects) create PaydarPress's redirect.
	 *
	 * @param string $old_url   Old URL or path.
	 * @param int    $target_id Post the address should lead to.
	 * @param string $reason    merge|gsc404|manual|category.
	 * @return array{created: bool, owner: ?string, verified: bool, message: string}
	 */
	public static function ensure( string $old_url, int $target_id, string $reason ): array {
		$owner  = self::detect();
		$target = (string) get_permalink( $target_id );

		if ( null !== $owner ) {
			$check = self::check( $old_url, $target );
			if ( 'ok' === $check ) {
				return array(
					'created'  => false,
					'owner'    => $owner['name'],
					'verified' => true,
					'message'  => 'آدرس قبلی از قبل به آدرس جدید ریدایرکت می‌شود (' . $owner['name'] . ' یا خود وردپرس)؛ ریدایرکت تکراری ساخته نشد.',
				);
			}
		}

		Redirects::add( $old_url, $target, $target_id, $reason );
		return array(
			'created'  => true,
			'owner'    => $owner['name'] ?? null,
			'verified' => false,
			'message'  => null === $owner
				? 'ریدایرکت ۳۰۱ پایدارپرس ساخته شد.'
				: 'در ' . $owner['name'] . ' ریدایرکتی برای این آدرس نبود؛ پایدارپرس ریدایرکت ۳۰۱ ساخت (فقط وقتی اجرا می‌شود که آن افزونه کاری نکند).',
		);
	}

	/**
	 * Requests the old address (no redirects followed) and checks where it goes.
	 *
	 * @param string $old_url  Old URL or path.
	 * @param string $expected Expected destination URL.
	 * @return string ok|missing|unknown (loopback request failed).
	 */
	public static function check( string $old_url, string $expected ): string {
		$url      = preg_match( '#^https?://#i', $old_url ) ? $old_url : home_url( '/' . ltrim( $old_url, '/' ) );
		$response = wp_remote_head(
			$url,
			array(
				'redirection' => 0,
				'timeout'     => 8,
				'sslverify'   => false,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) && '' !== $location && Redirects::path( $location ) === Redirects::path( $expected ) ) {
			return 'ok';
		}
		return 'missing';
	}

	/**
	 * Resets the cached detection (tests).
	 */
	public static function reset(): void {
		self::$owner = false;
	}
}
