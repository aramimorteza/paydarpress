<?php
/**
 * Persian text normalization for comparisons and indexing.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes Persian/Arabic text so equivalent strings compare equal.
 * The original text is never modified; results are only used for matching. See spec section 8.
 */
final class PersianNormalizer {

	private const ZWNJ = "\u{200C}";

	private const CHAR_MAP = array(
		'ي' => 'ی',
		'ى' => 'ی',
		'ئ' => 'ی',
		'ك' => 'ک',
		'ة' => 'ه',
		'ۀ' => 'ه',
		'ؤ' => 'و',
		'إ' => 'ا',
		'أ' => 'ا',
		'ٱ' => 'ا',
	);

	private const DIGIT_MAP = array(
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
	);

	/**
	 * Full normalization (keeps ZWNJ as the canonical half-space).
	 *
	 * @param string $text Input text.
	 */
	public static function normalize( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtr( $text, self::CHAR_MAP );
		$text = strtr( $text, self::DIGIT_MAP );

		// Diacritics (fathatan..sukun, superscript alef) and tatweel.
		$text = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );

		// Half-space variants → ZWNJ; remove spaces around ZWNJ; collapse repeated ZWNJ.
		$text = (string) preg_replace( '/[\x{200B}\x{00AC}\x{200D}]/u', self::ZWNJ, $text );
		$text = (string) preg_replace( '/\s*\x{200C}+\s*/u', self::ZWNJ, $text );

		// Non-breaking and other unicode spaces → normal space; collapse whitespace.
		$text = (string) preg_replace( '/[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text );

		return trim( mb_strtolower( $text, 'UTF-8' ) );
	}

	/**
	 * Normalization for keys and duplicate detection: half-spaces become spaces,
	 * punctuation is removed, so "می‌شود", "میشود" and "می شود" are treated alike
	 * as far as possible ("میشود" without a space still differs; see compact()).
	 *
	 * @param string $text Input text.
	 */
	public static function for_key( string $text ): string {
		$text = self::normalize( $text );
		$text = str_replace( self::ZWNJ, ' ', $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Key without any spaces, for the strictest equality checks (e.g. near-duplicate tags).
	 *
	 * @param string $text Input text.
	 */
	public static function compact( string $text ): string {
		return str_replace( ' ', '', self::for_key( $text ) );
	}

	/**
	 * Converts Persian/Arabic digits to Latin digits only.
	 *
	 * @param string $text Input text.
	 */
	public static function latin_digits( string $text ): string {
		return strtr( $text, self::DIGIT_MAP );
	}

	/**
	 * Formats a number with Persian digits and Persian thousands separator, independent of the
	 * site locale (the PaydarPress UI is always Persian).
	 *
	 * @param int|float $number Number.
	 */
	public static function fa_number( $number ): string {
		$formatted = number_format( (float) $number, ( (float) $number === floor( (float) $number ) ) ? 0 : 1, '٫', '٬' );

		return strtr( $formatted, array_flip( array_slice( self::DIGIT_MAP, 0, 10, true ) ) );
	}

	/**
	 * Counts words. ZWNJ joins parts of one word.
	 *
	 * @param string $plain_text Plain text (no HTML).
	 */
	public static function word_count( string $plain_text ): int {
		$text = self::normalize( $plain_text );
		if ( '' === $text ) {
			return 0;
		}

		$tokens = preg_split( '/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $tokens ) ? count( $tokens ) : 0;
	}
}
