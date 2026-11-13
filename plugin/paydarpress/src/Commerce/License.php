<?php
/**
 * License activation against the PaydarWP license server.
 *
 * Until the server address is set (constant PAYDARPRESS_LICENSE_SERVER or the
 * paydarpress/license_server filter) nothing is sent anywhere and every feature works.
 * A missing, invalid or unreachable license never stops local work or deletes data.
 *
 * Sent to the server: the license key, product slug and version, the site's domain (an
 * activation is bound to one site), WordPress and PHP versions. Never emails, users, content
 * or the AI key. The license key is stored encrypted and only its last 4 characters reach
 * the browser.
 *
 * Server contract (JSON over HTTPS POST):
 *   {server}/activate   { license, slug, version, site, wp, php } → { ok, status, plan, expires, message }
 *   {server}/check      same body                                  → same reply
 *   {server}/deactivate same body                                  → { ok, message }
 *   {server}/update     same body                                  → { version, package, requires, tested, requires_php, changelog }
 * status: active | expired | invalid | disabled.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Commerce;

use PaydarWP\PaydarPress\Ai\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * License state and server calls.
 */
final class License {

	public const OPTION = 'paydarpress_license';
	public const SLUG   = 'paydarpress';
	public const CRON   = 'paydarpress_license_check';

	public const STATUS_LABELS = array(
		'none'        => 'ثبت نشده',
		'active'      => 'فعال',
		'expired'     => 'منقضی شده',
		'invalid'     => 'نامعتبر',
		'disabled'    => 'غیرفعال‌شده توسط پایدار وردپرس',
	);

	/**
	 * Hooks: daily background check.
	 */
	public function register(): void {
		add_action( self::CRON, array( self::class, 'check' ) );
		add_action(
			'admin_init',
			static function () {
				if ( '' !== self::server() && ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
				}
			}
		);
	}

	/**
	 * License server base URL ('' = not available yet).
	 */
	public static function server(): string {
		$url = defined( 'PAYDARPRESS_LICENSE_SERVER' ) ? (string) PAYDARPRESS_LICENSE_SERVER : '';
		$url = (string) apply_filters( 'paydarpress/license_server', $url );
		$url = rtrim( trim( $url ), '/' );
		if ( '' === $url ) {
			return '';
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ok   = str_starts_with( $url, 'https://' ) || ( str_starts_with( $url, 'http://' ) && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) );
		return $ok ? $url : '';
	}

	/**
	 * Stored state.
	 */
	public static function stored(): array {
		$v = get_option( self::OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		return array(
			'key'         => (string) ( $v['key'] ?? '' ),
			'status'      => isset( self::STATUS_LABELS[ $v['status'] ?? '' ] ) ? $v['status'] : 'none',
			'plan'        => (string) ( $v['plan'] ?? '' ),
			'expires'     => (string) ( $v['expires'] ?? '' ),
			'message'     => (string) ( $v['message'] ?? '' ),
			'checked_at'  => (string) ( $v['checked_at'] ?? '' ),
			'unreachable' => (string) ( $v['unreachable'] ?? '' ),
			'relay'       => (string) ( $v['relay'] ?? '' ),
		);
	}

	/**
	 * Saves state (autoload off).
	 *
	 * @param array $s State.
	 */
	private static function store( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	/**
	 * Decrypted license key ('' when none).
	 */
	public static function key(): string {
		$s = self::stored();
		return '' === $s['key'] ? '' : Credentials::decrypt( $s['key'] );
	}

	/**
	 * Safe view for the browser.
	 */
	public static function view(): array {
		$s   = self::stored();
		$key = self::key();
		return array(
			'server_ready' => '' !== self::server(),
			'site'         => self::site(),
			'has_key'      => '' !== $key,
			'key_hint'     => '' !== $key ? '••••' . substr( $key, -4 ) : '',
			'status'       => $s['status'],
			'status_label' => self::STATUS_LABELS[ $s['status'] ],
			'plan'         => $s['plan'],
			'expires'      => $s['expires'],
			'message'      => $s['message'],
			'checked_at'   => $s['checked_at'],
			'unreachable'  => $s['unreachable'],
			'relay'        => $s['relay'],
			'version'      => PAYDARPRESS_VERSION,
			'update'       => Updater::cached(),
		);
	}

	/**
	 * Site identity sent with every call: the domain only.
	 */
	public static function site(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Activates a key on this site.
	 *
	 * @param string $key License key.
	 * @throws \RuntimeException When the server is not set or unreachable.
	 */
	public static function activate( string $key ): array {
		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > 200 || preg_match( '/\s/', $key ) ) {
			throw new \RuntimeException( 'کلید لایسنس را کامل و بدون فاصله بچسبانید.' );
		}
		$reply = self::call( 'activate', $key );
		$s     = self::stored();
		$s     = array_merge( $s, self::from_reply( $reply ) );
		// The key is kept even when the server rejects it, so the owner can see what was entered.
		$s['key'] = Credentials::encrypt( $key );
		self::store( $s );
		return self::view();
	}

	/**
	 * Releases this site's activation and forgets the key.
	 */
	public static function deactivate(): array {
		$key = self::key();
		if ( '' !== $key && '' !== self::server() ) {
			try {
				self::call( 'deactivate', $key );
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Forgetting the key locally must work offline too.
			}
		}
		$s = self::stored();
		self::store( array( 'relay' => $s['relay'] ) );
		return self::view();
	}

	/**
	 * Re-checks the stored key (daily cron and the "check now" button).
	 * Unreachable server: the last known status stays; only the date is recorded.
	 */
	public static function check(): array {
		$key = self::key();
		if ( '' === $key || '' === self::server() ) {
			return self::view();
		}
		$s = self::stored();
		try {
			$s = array_merge( $s, self::from_reply( self::call( 'check', $key ) ) );
		} catch ( \RuntimeException $e ) {
			$s['unreachable'] = '' !== $s['unreachable'] ? $s['unreachable'] : gmdate( 'c' );
			$s['message']     = $e->getMessage();
		}
		self::store( $s );
		return self::view();
	}

	/**
	 * Saves the relay address (for hosts that cannot reach paydarwp.ir directly).
	 *
	 * @param string $relay Relay URL ('' = direct).
	 */
	public static function set_relay( string $relay ): array {
		$relay = trim( $relay );
		if ( '' !== $relay ) {
			$relay = Credentials::clean_url( $relay );
		}
		$s          = self::stored();
		$s['relay'] = $relay;
		self::store( $s );
		return self::view();
	}

	/**
	 * Normalises a server reply into stored fields.
	 *
	 * @param array $reply Reply.
	 */
	private static function from_reply( array $reply ): array {
		$status = (string) ( $reply['status'] ?? '' );
		return array(
			'status'      => isset( self::STATUS_LABELS[ $status ] ) && 'none' !== $status ? $status : 'invalid',
			'plan'        => sanitize_text_field( (string) ( $reply['plan'] ?? '' ) ),
			'expires'     => preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) ( $reply['expires'] ?? '' ) ) ? substr( (string) $reply['expires'], 0, 10 ) : '',
			'message'     => mb_substr( sanitize_text_field( (string) ( $reply['message'] ?? '' ) ), 0, 300 ),
			'checked_at'  => gmdate( 'c' ),
			'unreachable' => '',
		);
	}

	/**
	 * POSTs to the license server (through the relay when set).
	 *
	 * @param string $action  activate|check|deactivate|update.
	 * @param string $key     License key ('' allowed for update without a license).
	 * @param int    $timeout Seconds.
	 * @throws \RuntimeException When not set, unreachable or the reply is not JSON.
	 */
	public static function call( string $action, string $key, int $timeout = 12 ): array {
		$server = self::server();
		if ( '' === $server ) {
			throw new \RuntimeException( 'سرور لایسنس پایدار وردپرس هنوز راه‌اندازی نشده است.' );
		}
		$relay = self::stored()['relay'];
		$url   = ( '' !== $relay ? $relay : $server ) . '/' . $action;
		$body  = array(
			'license' => $key,
			'slug'    => self::SLUG,
			'version' => PAYDARPRESS_VERSION,
			'site'    => self::site(),
			'wp'      => get_bloginfo( 'version' ),
			'php'     => PHP_VERSION,
		);
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'ارتباط با سرور لایسنس برقرار نشد. اگر هاست شما در ایران است و سایت‌های خارجی را باز نمی‌کند، مشکل از آن نیست؛ دوباره امتحان کنید یا آدرس واسط بگذارید.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( sprintf( 'پاسخ سرور لایسنس قابل خواندن نبود (کد %d).', $code ) );
		}
		if ( $code >= 500 ) {
			throw new \RuntimeException( sprintf( 'سرور لایسنس موقتاً در دسترس نیست (کد %d).', $code ) );
		}
		return $json;
	}
}
