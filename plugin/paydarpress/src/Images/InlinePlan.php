<?php
/**
 * How many inline images an article should have for its length.
 *
 * One image per `images.words_per_inline` words, minus one (the featured image is the first
 * picture the reader sees), capped at `images.inline_max`. Too few makes long text tiring,
 * too many makes the page heavy and breaks the reading flow.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Images;

use PaydarWP\PaydarPress\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pure arithmetic on top of two settings (unit tested with explicit arguments).
 */
final class InlinePlan {

	/**
	 * Recommended number of inline images.
	 *
	 * @param int      $words Article words.
	 * @param int|null $per   Words per image (null: setting).
	 * @param int|null $max   Maximum (null: setting).
	 */
	public static function expected( int $words, ?int $per = null, ?int $max = null ): int {
		$per = max( 100, $per ?? (int) Settings::get( 'images.words_per_inline', 400 ) );
		$max = max( 0, $max ?? (int) Settings::get( 'images.inline_max', 5 ) );
		return max( 0, min( $max, intdiv( max( 0, $words ), $per ) - 1 ) );
	}

	/**
	 * Acceptable range around the recommendation.
	 *
	 * @param int      $words Words.
	 * @param int|null $per   Words per image.
	 * @param int|null $max   Maximum.
	 * @return array{0: int, 1: int}
	 */
	public static function range( int $words, ?int $per = null, ?int $max = null ): array {
		$expected = self::expected( $words, $per, $max );
		$max      = max( 0, $max ?? (int) Settings::get( 'images.inline_max', 5 ) );
		if ( 0 === $expected ) {
			return array( 0, min( 1, $max ) );
		}
		return array( max( 1, $expected - 1 ), min( $max, $expected + 1 ) );
	}

	/**
	 * Verdict for an article: ok | none | few | many.
	 *
	 * @param int      $words  Words.
	 * @param int      $images Inline images.
	 * @param int|null $per    Words per image.
	 * @param int|null $max    Maximum.
	 */
	public static function verdict( int $words, int $images, ?int $per = null, ?int $max = null ): string {
		list( $low, $high ) = self::range( $words, $per, $max );
		if ( $images > $high ) {
			return 'many';
		}
		if ( $images < $low ) {
			return 0 === $images ? 'none' : 'few';
		}
		return 'ok';
	}

	/**
	 * Rule text for prompts (with a small table so the model does not have to calculate).
	 */
	public static function prompt_rule(): string {
		$per = max( 100, (int) Settings::get( 'images.words_per_inline', 400 ) );
		$max = (int) Settings::get( 'images.inline_max', 5 );
		if ( 0 === $max ) {
			return 'inline_images: none (the site does not use inline images); return an empty list.';
		}
		$rows = array();
		foreach ( array( 600, 800, 1200, 1600, 2000, 2500, 3000 ) as $w ) {
			$rows[] = $w . ' words → ' . self::expected( $w, $per, $max );
		}
		return "inline_images: the number depends on the FINAL article length — one image per {$per} words minus one (the featured image is the first), at most {$max}: " . implode( ', ', $rows ) . '. Spread them evenly through the article: at most one image per H2 section, never two in a row, not inside the first 150 words and not in the conclusion. Put each image where a picture really helps the reader at that point (a process, a comparison, a document, a place, an example); if a section has nothing worth showing, skip it rather than adding decoration.';
	}
}
