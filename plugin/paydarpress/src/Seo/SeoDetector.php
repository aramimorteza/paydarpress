<?php
/**
 * Picks the SEO adapter for the active SEO plugin.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detection order: Rank Math, Yoast, AIOSEO.
 */
final class SeoDetector {

	/** @var SeoAdapter|null */
	private static $adapter = null;

	/**
	 * The adapter for the active SEO plugin (cached per request).
	 */
	public static function adapter(): SeoAdapter {
		if ( null !== self::$adapter ) {
			return self::$adapter;
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			$adapter = new RankMathAdapter();
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$adapter = new YoastAdapter();
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$adapter = new AioseoAdapter();
		} else {
			$adapter = new NullAdapter();
		}

		/**
		 * Filters the SEO adapter (e.g. to add support for another SEO plugin).
		 *
		 * @param SeoAdapter $adapter Detected adapter.
		 */
		$filtered = apply_filters( 'paydarpress/seo_adapter', $adapter );

		self::$adapter = $filtered instanceof SeoAdapter ? $filtered : $adapter;

		return self::$adapter;
	}

	/**
	 * Clears the cached adapter (used in tests).
	 */
	public static function reset(): void {
		self::$adapter = null;
	}
}
