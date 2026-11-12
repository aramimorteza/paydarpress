<?php
/**
 * Detects time-relevant years in titles and text (Jalali and Gregorian).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * See spec section 7.2. Years in the title always count; years in body text count only when
 * a time word appears within three words, to avoid flagging historical dates.
 */
final class YearDetector {

	private const CONTEXT_WORDS = array( 'سال', 'امسال', 'نرخ', 'مبلغ', 'قیمت', 'هزینه', 'تعرفه', 'میزان', 'in', 'for', 'year' );

	/**
	 * A year right after these words is a law/regulation date, not a sign of outdated content
	 * (e.g. «قانون حمایت خانواده مصوب 1391»).
	 */
	private const EXCLUDE_BEFORE = array( 'مصوب', 'مصوبه', 'مصوبات', 'قانون', 'ماده', 'اصلاحیه', 'since', 'شماره', 'کد', 'پلاک', 'مدل', 'قطعه', 'نمره', 'تلفن', 'داخلی', 'no', 'model', 'code' );

	/** A number followed by a unit is a quantity, not a year (e.g. «1400 متر»). */
	private const EXCLUDE_AFTER = array( 'متر', 'مترمربع', 'متری', 'تومان', 'ریال', 'نفر', 'عدد', 'کیلو', 'کیلوگرم', 'گرم', 'لیتر', 'سانت', 'سانتی', 'میلیون', 'هزار', 'دلار', 'کیلومتر', 'واحد', 'مورد', 'm', 'kg' );

	private const MIN_JALALI    = 1390;
	private const MIN_GREGORIAN = 2010;

	/** @var int */
	private $jalali_now;

	/** @var int */
	private $gregorian_now;

	/**
	 * @param int|null $timestamp Reference time (defaults to now).
	 */
	public function __construct( ?int $timestamp = null ) {
		$timestamp           = $timestamp ?? time();
		$this->gregorian_now = (int) gmdate( 'Y', $timestamp );
		$this->jalali_now    = self::jalali_year( $timestamp );
	}

	/**
	 * Jalali (Solar Hijri) year for a timestamp. Nowruz is treated as March 21 (UTC);
	 * a one-day error around Nowruz does not matter for this purpose.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function jalali_year( int $timestamp ): int {
		$year  = (int) gmdate( 'Y', $timestamp );
		$month = (int) gmdate( 'n', $timestamp );
		$day   = (int) gmdate( 'j', $timestamp );

		return ( $month > 3 || ( 3 === $month && $day >= 21 ) ) ? $year - 621 : $year - 622;
	}

	/**
	 * Current Jalali year used by this detector.
	 */
	public function current_jalali(): int {
		return $this->jalali_now;
	}

	/**
	 * Current Gregorian year used by this detector.
	 */
	public function current_gregorian(): int {
		return $this->gregorian_now;
	}

	/**
	 * Returns time-relevant years (as integers, unique, sorted) found in title or text.
	 * Includes the current year; callers decide which ones are outdated.
	 *
	 * @param string $title Title.
	 * @param string $text  Plain text.
	 * @return int[]
	 */
	public function detect( string $title, string $text ): array {
		$years = array_merge(
			$this->find( PersianNormalizer::normalize( $title ), false ),
			$this->find( PersianNormalizer::normalize( $text ), true )
		);

		$years = array_values( array_unique( $years ) );
		sort( $years );

		return $years;
	}

	/**
	 * Years older than the current year (per calendar).
	 *
	 * @param int[] $years Years from detect().
	 * @return int[]
	 */
	public function outdated( array $years ): array {
		return array_values(
			array_filter(
				$years,
				function ( int $year ): bool {
					return $this->is_jalali( $year ) ? $year < $this->jalali_now : $year < $this->gregorian_now;
				}
			)
		);
	}

	/**
	 * Finds candidate years in normalized text.
	 *
	 * @param string $text        Normalized text.
	 * @param bool   $needs_context Whether a nearby time word is required.
	 * @return int[]
	 */
	private function find( string $text, bool $needs_context ): array {
		if ( '' === $text || ! preg_match_all( '/(?<![\d.,\/])(\d{4})(?![\d.,\/])/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$found = array();

		foreach ( $matches[1] as $match ) {
			$year = (int) $match[0];

			if ( ! $this->in_range( $year ) ) {
				continue;
			}

			if ( $this->is_law_date( $text, (int) $match[1] ) || $this->is_quantity( $text, (int) $match[1] ) ) {
				continue;
			}

			if ( $needs_context && ! $this->has_context( $text, (int) $match[1] ) ) {
				continue;
			}

			$found[] = $year;
		}

		return $found;
	}

	/**
	 * Whether the year is a plausible recent Jalali or Gregorian year.
	 *
	 * @param int $year Year.
	 */
	private function in_range( int $year ): bool {
		return ( $year >= self::MIN_JALALI && $year <= $this->jalali_now + 1 )
			|| ( $year >= self::MIN_GREGORIAN && $year <= $this->gregorian_now + 1 );
	}

	/**
	 * Jalali years are below 1500; Gregorian years in range are above 2000.
	 *
	 * @param int $year Year.
	 */
	private function is_jalali( int $year ): bool {
		return $year < 1500;
	}

	/**
	 * Whether one of the two words before the year marks a law or regulation date.
	 *
	 * @param string $text   Normalized text.
	 * @param int    $offset Byte offset of the year.
	 */
	private function is_law_date( string $text, int $offset ): bool {
		$before = substr( $text, max( 0, $offset - 80 ), min( 80, $offset ) );
		$words  = preg_split( '/[\s\x{200C}]+/u', trim( (string) $before ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( array_slice( is_array( $words ) ? $words : array(), -2 ) as $word ) {
			$word = (string) preg_replace( '/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $word );
			if ( in_array( $word, self::EXCLUDE_BEFORE, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the number is followed by a unit word.
	 *
	 * @param string $text   Normalized text.
	 * @param int    $offset Byte offset of the year.
	 */
	private function is_quantity( string $text, int $offset ): bool {
		$after = substr( $text, $offset + 4, 40 );
		$words = preg_split( '/[\s\x{200C}]+/u', trim( (string) $after ), -1, PREG_SPLIT_NO_EMPTY );
		$next  = is_array( $words ) && isset( $words[0] ) ? (string) preg_replace( '/[\p{P}\p{S}]+$/u', '', $words[0] ) : '';

		return in_array( $next, self::EXCLUDE_AFTER, true );
	}

	/**
	 * Whether a context word appears within three words before or after the year.
	 *
	 * @param string $text   Normalized text.
	 * @param int    $offset Byte offset of the year.
	 */
	private function has_context( string $text, int $offset ): bool {
		$before = substr( $text, max( 0, $offset - 120 ), min( 120, $offset ) );
		$after  = substr( $text, $offset + 4, 120 );

		$words_before = preg_split( '/[\s\x{200C}]+/u', trim( (string) $before ), -1, PREG_SPLIT_NO_EMPTY );
		$words_after  = preg_split( '/[\s\x{200C}]+/u', trim( (string) $after ), -1, PREG_SPLIT_NO_EMPTY );

		$window = array_merge(
			array_slice( is_array( $words_before ) ? $words_before : array(), -3 ),
			array_slice( is_array( $words_after ) ? $words_after : array(), 0, 3 )
		);

		foreach ( $window as $word ) {
			$word = (string) preg_replace( '/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $word );
			if ( in_array( $word, self::CONTEXT_WORDS, true ) ) {
				return true;
			}
		}

		return false;
	}
}
