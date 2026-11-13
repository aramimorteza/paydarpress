<?php
/**
 * Plugin updates from the PaydarWP server, shown in the normal WordPress update screens.
 *
 * Runs only when the license server is set. The answer is cached for 12 hours; WordPress
 * downloads and installs the package itself, so nothing here touches files.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * update_plugins / plugins_api integration.
 */
final class Updater {

	public const CACHE = 'paydarpress_update';
	public const TTL   = 12 * HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
	}

	/**
	 * Plugin basename (paydarpress/paydarpress.php).
	 */
	public static function basename(): string {
		return plugin_basename( PAYDARPRESS_FILE );
	}

	/**
	 * Last known update info (no request), or null.
	 */
	public static function cached(): ?array {
		$c = get_site_transient( self::CACHE );
		return is_array( $c ) ? $c : null;
	}

	/**
	 * Asks the server for the latest version (cached).
	 *
	 * @param bool $force Ignore the cache.
	 */
	public static function fetch( bool $force = false ): ?array {
		if ( '' === License::server() ) {
			return null;
		}
		$cached = self::cached();
		if ( ! $force && null !== $cached ) {
			return $cached;
		}
		try {
			$r = License::call( 'update', License::key(), 8 );
		} catch ( \RuntimeException $e ) {
			$out = array( 'error' => $e->getMessage(), 'checked_at' => gmdate( 'c' ), 'version' => '', 'newer' => false );
			// Short cache on failure so the update screen does not wait on every load.
			set_site_transient( self::CACHE, $out, HOUR_IN_SECONDS );
			return $out;
		}
		$version = preg_match( '/^\d+\.\d+(\.\d+)?$/', (string) ( $r['version'] ?? '' ) ) ? (string) $r['version'] : '';
		$package = (string) ( $r['package'] ?? '' );
		$out     = array(
			'version'      => $version,
			'newer'        => '' !== $version && version_compare( $version, PAYDARPRESS_VERSION, '>' ),
			// Only https packages (http on localhost for testing).
			'package'      => preg_match( '#^https://#', $package ) || preg_match( '#^http://(localhost|127\.0\.0\.1)[:/]#', $package ) ? esc_url_raw( $package ) : '',
			'requires'     => sanitize_text_field( (string) ( $r['requires'] ?? '' ) ),
			'tested'       => sanitize_text_field( (string) ( $r['tested'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $r['requires_php'] ?? '' ) ),
			'changelog'    => wp_kses_post( (string) ( $r['changelog'] ?? '' ) ),
			'message'      => sanitize_text_field( (string) ( $r['message'] ?? '' ) ),
			'checked_at'   => gmdate( 'c' ),
			'error'        => '',
		);
		set_site_transient( self::CACHE, $out, self::TTL );
		return $out;
	}

	/**
	 * Adds PaydarPress to the update list when a newer version exists.
	 *
	 * @param mixed $transient update_plugins transient.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$u = self::fetch();
		if ( null === $u || empty( $u['newer'] ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => License::SLUG,
			'slug'         => License::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $u['version'],
			// Without a valid license the server returns no package: WordPress shows the update with a notice instead of installing.
			'package'      => $u['package'],
			'url'          => Brand::SITE,
			'requires'     => $u['requires'],
			'tested'       => $u['tested'],
			'requires_php' => $u['requires_php'],
			'upgrade_notice' => '' === $u['package'] ? 'برای نصب خودکار، لایسنس پایدارپرس را در «سایت من ← لایسنس و به‌روزرسانی» فعال کنید.' : '',
		);
		$transient->response[ self::basename() ] = $item;
		return $transient;
	}

	/**
	 * "View details" popup.
	 *
	 * @param mixed  $result Default.
	 * @param string $action plugins_api action.
	 * @param object $args   Arguments.
	 * @return mixed
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || License::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$u = self::fetch();
		if ( null === $u || '' === $u['version'] ) {
			return $result;
		}
		return (object) array(
			'name'          => 'پایدارپرس',
			'slug'          => License::SLUG,
			'version'       => $u['version'],
			'author'        => '<a href="' . esc_url( Brand::SITE ) . '">پایدار وردپرس</a>',
			'homepage'      => Brand::SITE,
			'requires'      => $u['requires'],
			'tested'        => $u['tested'],
			'requires_php'  => $u['requires_php'],
			'download_link' => $u['package'],
			'sections'      => array( 'changelog' => '' !== $u['changelog'] ? $u['changelog'] : '<p>جزئیات در سایت پایدار وردپرس.</p>' ),
		);
	}
}
