<?php
/**
 * URL normalization for link-graph matching. WordPress-independent.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts hrefs to absolute URLs and comparable keys.
 *
 * Keys are `host/decoded-path` (lowercase host without www, no trailing slash, no fragment).
 * Query strings are dropped except `p`, `page_id` and `post_type`+`p` style IDs.
 */
final class UrlNormalizer {

	private const ASSET_EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|ico|pdf|zip|rar|7z|docx?|xlsx?|pptx?|mp3|mp4|webm|mov|wav|ogg|css|js|json|xml|txt|woff2?|ttf|eot';

	/** @var string */
	private $home_host;

	/** @var string */
	private $home_scheme;

	/** @var string Path of the home URL (for sites installed in a subfolder), without trailing slash. */
	private $home_path;

	/**
	 * @param string $home_url Site home URL.
	 */
	public function __construct( string $home_url ) {
		$parts             = parse_url( $home_url );
		$this->home_host   = self::host( (string) ( $parts['host'] ?? '' ) );
		$this->home_scheme = (string) ( $parts['scheme'] ?? 'https' );
		$this->home_path   = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	/**
	 * Resolves an href against the page it appears on.
	 *
	 * @param string $href     Raw href.
	 * @param string $base_url URL of the page containing the link.
	 * @return string|null Absolute URL, or null if it cannot be resolved.
	 */
	public function absolute( string $href, string $base_url ): ?string {
		$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( '' === $href ) {
			return null;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			return preg_match( '#^https?://#i', $href ) ? $href : null;
		}

		if ( str_starts_with( $href, '//' ) ) {
			return $this->home_scheme . ':' . $href;
		}

		$base = parse_url( $base_url );
		$root = ( $base['scheme'] ?? $this->home_scheme ) . '://' . ( $base['host'] ?? $this->home_host ) . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );

		if ( str_starts_with( $href, '/' ) ) {
			return $root . $href;
		}

		if ( str_starts_with( $href, '?' ) || str_starts_with( $href, '#' ) ) {
			return $root . ( $base['path'] ?? '/' ) . $href;
		}

		// Relative path: resolve against the directory of the base path.
		$dir = (string) ( $base['path'] ?? '/' );
		$dir = str_ends_with( $dir, '/' ) ? $dir : (string) preg_replace( '#/[^/]*$#', '/', $dir );

		return $root . $this->remove_dot_segments( $dir . $href );
	}

	/**
	 * Whether an absolute URL points to this site.
	 *
	 * @param string $url Absolute URL.
	 */
	public function is_internal( string $url ): bool {
		$host = self::host( (string) parse_url( $url, PHP_URL_HOST ) );

		return '' !== $host && $host === $this->home_host;
	}

	/**
	 * Whether the URL points to a file (image, PDF, upload…) rather than a page.
	 *
	 * @param string $url Absolute URL.
	 */
	public function is_asset( string $url ): bool {
		$path = (string) parse_url( $url, PHP_URL_PATH );

		return (bool) preg_match( '#/wp-content/|/wp-includes/|\.(' . self::ASSET_EXTENSIONS . ')$#i', $path );
	}

	/**
	 * Whether the URL is a WordPress route that is not a single content item and should not be
	 * reported as broken (feeds, pagination, search, admin, REST, date archives…).
	 *
	 * @param string $url Absolute URL.
	 */
	public function is_special_route( string $url ): bool {
		$path  = $this->relative_path( $url );
		$query = (string) parse_url( $url, PHP_URL_QUERY );

		if ( preg_match( '/(^|&)(s|replytocom|add-to-cart|orderby|filter_[a-z_]+)=/', $query ) ) {
			return true;
		}

		return (bool) preg_match( '#^/(wp-admin|wp-login\.php|wp-json|feed|comments/feed|xmlrpc\.php)(/|$)|/feed/?$|/page/\d+/?$|^/\d{4}(/\d{2}){0,2}/?$|^/?$#i', $path );
	}

	/**
	 * Comparable key of an absolute URL.
	 *
	 * @param string $url Absolute URL.
	 */
	public function key( string $url ): string {
		$parts = parse_url( $url );
		$host  = self::host( (string) ( $parts['host'] ?? '' ) );
		$path  = rawurldecode( (string) ( $parts['path'] ?? '/' ) );
		$path  = mb_strtolower( rtrim( $path, '/' ), 'UTF-8' );
		$key   = $host . ( '' === $path ? '/' : $path );

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( array( 'p', 'page_id' ) as $id_param ) {
				if ( isset( $query[ $id_param ] ) && is_scalar( $query[ $id_param ] ) && ctype_digit( (string) $query[ $id_param ] ) ) {
					return $key . '?' . $id_param . '=' . $query[ $id_param ];
				}
			}
		}

		return $key;
	}

	/**
	 * Path relative to the home path (for sites in a subfolder).
	 *
	 * @param string $url Absolute URL.
	 */
	private function relative_path( string $url ): string {
		$path = rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) );

		if ( '' !== $this->home_path && str_starts_with( $path, $this->home_path ) ) {
			$path = substr( $path, strlen( $this->home_path ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Lowercase host without leading www.
	 *
	 * @param string $host Host.
	 */
	private static function host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * Resolves ./ and ../ segments (RFC 3986 section 5.2.4, simplified).
	 *
	 * @param string $path Path.
	 */
	private function remove_dot_segments( string $path ): string {
		$query = '';
		if ( false !== ( $pos = strpos( $path, '?' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
			$query = substr( $path, $pos );
			$path  = substr( $path, 0, $pos );
		}

		$out = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $out );
			} elseif ( '.' !== $segment ) {
				$out[] = $segment;
			}
		}

		$result = implode( '/', $out );

		return ( str_starts_with( $result, '/' ) ? $result : '/' . $result ) . $query;
	}
}
