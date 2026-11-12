<?php
/**
 * Collects redirect source URLs from known redirect plugins.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supports Rank Math Redirections and the Redirection plugin (exact matches only).
 */
final class RedirectSources {

	/**
	 * Returns URL keys (see UrlNormalizer::key) of active redirect sources.
	 *
	 * @param UrlNormalizer $urls     Normalizer.
	 * @param string        $home_url Site home URL.
	 * @return array<string, true>
	 */
	public static function keys( UrlNormalizer $urls, string $home_url ): array {
		$keys = array();

		foreach ( array_merge( self::rank_math(), self::redirection() ) as $source ) {
			$absolute = $urls->absolute( '/' . ltrim( $source, '/' ), $home_url );
			if ( null !== $absolute ) {
				$keys[ $urls->key( $absolute ) ] = true;
			}
		}

		return $keys;
	}

	/**
	 * Rank Math: `sources` is a serialized array of [pattern, comparison].
	 *
	 * @return string[]
	 */
	private static function rank_math(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_col( "SELECT sources FROM {$table} WHERE status = 'active'" );
		$sources = array();

		foreach ( (array) $rows as $raw ) {
			$list = maybe_unserialize( $raw );
			foreach ( (array) $list as $item ) {
				if ( is_array( $item ) && ( $item['comparison'] ?? '' ) === 'exact' && ! empty( $item['pattern'] ) ) {
					$sources[] = (string) $item['pattern'];
				}
			}
		}

		return $sources;
	}

	/**
	 * Redirection plugin: enabled, non-regex items.
	 *
	 * @return string[]
	 */
	private static function redirection(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'redirection_items';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT url FROM {$table} WHERE status = 'enabled' AND regex = 0" ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
