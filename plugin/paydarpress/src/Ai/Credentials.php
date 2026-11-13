<?php
/**
 * AI connection settings. The API key is stored encrypted, never sent back to the browser
 * and never included in exports or the activity log.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in its own option (autoload off), separate from the exportable settings.
 */
final class Credentials {

	public const OPTION = 'paydarpress_ai';

	public const PROVIDERS = array(
		'openai'    => 'سازگار با OpenAI (OpenAI، OpenRouter، درگاه‌های ایرانی و…)',
		'anthropic' => 'Claude (Anthropic)',
	);

	public const DEFAULT_URLS = array(
		'openai'    => 'https://api.openai.com/v1',
		'anthropic' => 'https://api.anthropic.com/v1',
	);

	/**
	 * Ready-made connections. Choosing one fills the service, address and a model; the owner
	 * can still edit them. `iran` marks gateways reachable from Iranian hosts without a relay.
	 * Filter: paydarpress/ai_gateways.
	 */
	public const GATEWAYS = array(
		'avalai'     => array(
			'label'    => 'AvalAI (اول‌ای‌آی)',
			'provider' => 'openai',
			'base_url' => 'https://api.avalai.ir/v1',
			'mirror'   => 'https://api.avalapis.ir/v1',
			'model'    => 'gpt-4o',
			'site'     => 'https://avalai.ir',
			'iran'     => true,
		),
		'gapgpt'     => array(
			'label'    => 'GapGPT (گپ جی‌پی‌تی)',
			'provider' => 'openai',
			'base_url' => 'https://api.gapgpt.app/v1',
			'model'    => 'gpt-4o',
			'site'     => 'https://gapgpt.app',
			'iran'     => true,
		),
		'metis'      => array(
			'label'    => 'Metis (متیس)',
			'provider' => 'openai',
			'base_url' => 'https://api.metisai.ir/api/v1/wrapper/openai_chat_completion',
			'model'    => 'gpt-4o',
			'site'     => 'https://metisai.ir',
			'iran'     => true,
		),
		'openrouter' => array(
			'label'    => 'OpenRouter',
			'provider' => 'openai',
			'base_url' => 'https://openrouter.ai/api/v1',
			'model'    => 'openai/gpt-4o',
			'site'     => 'https://openrouter.ai/keys',
			'iran'     => false,
		),
		'openai'     => array(
			'label'    => 'OpenAI',
			'provider' => 'openai',
			'base_url' => '',
			'model'    => 'gpt-4o',
			'site'     => 'https://platform.openai.com/api-keys',
			'iran'     => false,
		),
		'anthropic'  => array(
			'label'    => 'Claude (Anthropic)',
			'provider' => 'anthropic',
			'base_url' => '',
			'model'    => '',
			'site'     => 'https://console.anthropic.com/settings/keys',
			'iran'     => false,
		),
	);

	/**
	 * Gateways after the filter (invalid entries dropped).
	 */
	public static function gateways(): array {
		$list = apply_filters( 'paydarpress/ai_gateways', self::GATEWAYS );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $key => $g ) {
			if ( is_array( $g ) && isset( $g['label'], $g['provider'] ) && isset( self::PROVIDERS[ $g['provider'] ] ) ) {
				$out[ sanitize_key( (string) $key ) ] = array(
					'label'    => (string) $g['label'],
					'provider' => (string) $g['provider'],
					'base_url' => (string) ( $g['base_url'] ?? '' ),
					'mirror'   => (string) ( $g['mirror'] ?? '' ),
					'model'    => (string) ( $g['model'] ?? '' ),
					'site'     => (string) ( $g['site'] ?? '' ),
					'iran'     => ! empty( $g['iran'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Which ready-made gateway the stored settings match ('' = custom).
	 *
	 * @param string $provider Provider.
	 * @param string $base_url Stored base URL ('' = provider default).
	 */
	public static function gateway_of( string $provider, string $base_url ): string {
		$url = rtrim( strtolower( $base_url ), '/' );
		foreach ( self::gateways() as $key => $g ) {
			if ( $g['provider'] !== $provider ) {
				continue;
			}
			$urls = array_filter( array( $g['base_url'], $g['mirror'] ) );
			if ( ( '' === $url && '' === $g['base_url'] ) || in_array( $url, array_map( static fn( $u ) => rtrim( strtolower( $u ), '/' ), $urls ), true ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Raw stored settings.
	 *
	 * @return array{provider: string, base_url: string, model: string, key: string, updated_at: string}
	 */
	private static function stored(): array {
		$v = get_option( self::OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		return array(
			'provider'   => isset( self::PROVIDERS[ $v['provider'] ?? '' ] ) ? $v['provider'] : 'openai',
			'base_url'   => (string) ( $v['base_url'] ?? '' ),
			'model'      => (string) ( $v['model'] ?? '' ),
			'key'        => (string) ( $v['key'] ?? '' ),
			'updated_at' => (string) ( $v['updated_at'] ?? '' ),
		);
	}

	/**
	 * Settings for making requests (with the decrypted key), or null when not configured.
	 *
	 * @return array{provider: string, base_url: string, model: string, key: string}|null
	 */
	public static function connection(): ?array {
		$s   = self::stored();
		$key = '' === $s['key'] ? '' : self::decrypt( $s['key'] );
		if ( '' === $key || '' === $s['model'] ) {
			return null;
		}
		return array(
			'provider' => $s['provider'],
			'base_url' => '' !== $s['base_url'] ? $s['base_url'] : self::DEFAULT_URLS[ $s['provider'] ],
			'model'    => $s['model'],
			'key'      => $key,
		);
	}

	/**
	 * Safe view for the browser: key masked.
	 */
	public static function public_view(): array {
		$s       = self::stored();
		$key     = '' === $s['key'] ? '' : self::decrypt( $s['key'] );
		$broken  = '' !== $s['key'] && '' === $key;
		return array(
			'provider'     => $s['provider'],
			'base_url'     => $s['base_url'],
			'default_urls' => self::DEFAULT_URLS,
			'model'        => $s['model'],
			'has_key'      => '' !== $key,
			'key_hint'     => '' !== $key ? '••••' . substr( $key, -4 ) : '',
			// Salts in wp-config.php changed (e.g. after migration): the key cannot be read any more.
			'key_unreadable' => $broken,
			'configured'   => null !== self::connection(),
			'providers'    => self::PROVIDERS,
			'gateways'     => self::gateways(),
			'gateway'      => self::gateway_of( $s['provider'], $s['base_url'] ),
			'updated_at'   => $s['updated_at'],
		);
	}

	/**
	 * Saves the connection. An empty key keeps the stored one.
	 *
	 * @param array $input provider, base_url, model, key.
	 * @throws \InvalidArgumentException On an invalid URL.
	 */
	public static function save( array $input ): void {
		$s = self::stored();

		if ( isset( $input['provider'] ) && isset( self::PROVIDERS[ $input['provider'] ] ) ) {
			$s['provider'] = (string) $input['provider'];
		}
		if ( array_key_exists( 'base_url', $input ) ) {
			$s['base_url'] = self::clean_url( (string) $input['base_url'] );
		}
		if ( array_key_exists( 'model', $input ) ) {
			$s['model'] = mb_substr( trim( sanitize_text_field( (string) $input['model'] ) ), 0, 100 );
		}
		$key = trim( (string) ( $input['key'] ?? '' ) );
		if ( '' !== $key ) {
			if ( strlen( $key ) > 400 || preg_match( '/\s/', $key ) ) {
				throw new \InvalidArgumentException( 'کلید API معتبر نیست؛ آن را بدون فاصله و کامل بچسبانید.' );
			}
			$s['key'] = self::encrypt( $key );
		}
		$s['updated_at'] = gmdate( 'c' );

		update_option( self::OPTION, $s, false );
	}

	/**
	 * Deletes the stored key (keeps provider and model).
	 */
	public static function clear_key(): void {
		$s        = self::stored();
		$s['key'] = '';
		update_option( self::OPTION, $s, false );
	}

	/**
	 * Validates a base URL: https only (http allowed for localhost, for self-hosted gateways).
	 *
	 * @param string $url URL.
	 * @throws \InvalidArgumentException When invalid.
	 */
	public static function clean_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$url   = rtrim( $url, '/' );
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$local = in_array( $host, array( 'localhost', '127.0.0.1' ), true );
		if ( '' === $host || ! in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) || ( 'http' === $parts['scheme'] && ! $local ) || isset( $parts['user'] ) || isset( $parts['query'] ) ) {
			throw new \InvalidArgumentException( 'آدرس سرویس باید با https:// شروع شود، مثل https://api.openai.com/v1' );
		}
		return esc_url_raw( $url, array( 'https', 'http' ) );
	}

	/**
	 * Encrypts with a key derived from this site's salts.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv = random_bytes( 16 );
		$ct = openssl_encrypt( $plain, 'aes-256-cbc', self::secret(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $ct, self::secret(), true );
		return 'o1:' . base64_encode( $iv . $mac . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts; returns '' when the data cannot be read (e.g. salts changed).
	 *
	 * @param string $stored Stored value.
	 */
	public static function decrypt( string $stored ): string {
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		if ( str_starts_with( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::secret() );
			return false === $plain ? '' : $plain;
		}
		if ( str_starts_with( $stored, 'o1:' ) && strlen( $raw ) > 48 ) {
			$iv  = substr( $raw, 0, 16 );
			$mac = substr( $raw, 16, 32 );
			$ct  = substr( $raw, 48 );
			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $ct, self::secret(), true ), $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $ct, 'aes-256-cbc', self::secret(), OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	/**
	 * 32-byte key from the site's auth salts.
	 */
	private static function secret(): string {
		return hash( 'sha256', 'paydarpress-ai|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}
}
