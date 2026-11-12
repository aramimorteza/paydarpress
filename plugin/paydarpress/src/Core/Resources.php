<?php
/**
 * Memory guard for background work on hosts with small PHP memory limits.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resources.
 */
final class Resources {

	/**
	 * Share of memory_limit in use (0..1). 0 when there is no limit.
	 */
	public static function memory_ratio(): float {
		$limit = self::memory_limit();

		return $limit > 0 ? memory_get_usage( true ) / $limit : 0.0;
	}

	/**
	 * Whether a request should stop taking new work (above the given share of memory_limit).
	 *
	 * @param float $threshold Share, e.g. 0.7.
	 */
	public static function memory_tight( float $threshold = 0.7 ): bool {
		return self::memory_ratio() > $threshold;
	}

	/**
	 * Frees WordPress's in-request object cache (posts, meta and terms loaded while scanning).
	 * Only for the built-in cache: a persistent object cache keeps its data.
	 */
	public static function release_cache(): void {
		if ( ! wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * memory_limit in bytes, -1 → 0 (unlimited).
	 */
	private static function memory_limit(): int {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $value );
	}
}
