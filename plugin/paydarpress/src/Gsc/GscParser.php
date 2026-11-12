<?php
/**
 * Parses Search Console Performance exports (CSV files from the export zip). WordPress-independent.
 *
 * Works with any Search Console interface language: columns are recognised by keywords
 * (English and Persian) and position, numbers may use Persian/Arabic digits and separators.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Gsc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console export parser.
 */
final class GscParser {

	/** Search Console exports at most this many rows per table from the web interface. */
	public const EXPORT_ROW_CAP = 1000;

	private const METRICS = array(
		'clicks'      => '/click|کلیک/iu',
		'impressions' => '/impression|نمایش|ایمپرشن/iu',
		'ctr'         => '/\bctr\b|نرخ\s*کلیک|click.?through/iu',
		'position'    => '/position|جایگاه|موقعیت|رتبه/iu',
	);

	/**
	 * Parses the files of one export.
	 *
	 * @param array<string, string> $files File name => CSV content.
	 * @return array{
	 *   pages: array<int, array>, queries: array<int, array>, compare: bool,
	 *   period: array{start:?string, end:?string, label:string, days:?int},
	 *   files: string[], warnings: string[], pages_capped: bool, queries_capped: bool
	 * }
	 */
	public static function parse( array $files ): array {
		$out = array(
			'pages'          => array(),
			'queries'        => array(),
			'compare'        => false,
			'period'         => array(
				'start' => null,
				'end'   => null,
				'label' => '',
				'days'  => null,
			),
			'files'          => array(),
			'warnings'       => array(),
			'pages_capped'   => false,
			'queries_capped' => false,
		);

		$filters = array();

		foreach ( $files as $name => $content ) {
			$parsed = CsvReader::parse( $content );
			$rows   = $parsed['rows'];
			if ( count( $rows ) < 1 ) {
				continue;
			}

			$type = self::detect_type( (string) $name, $rows );
			if ( null === $type ) {
				continue;
			}

			$out['files'][] = basename( (string) $name );

			if ( $parsed['truncated'] ) {
				$out['warnings'][] = 'truncated:' . basename( (string) $name );
			}

			if ( 'filters' === $type ) {
				$filters = $rows;
				continue;
			}

			$table = self::table( $rows );

			if ( 'dates' === $type ) {
				$dates = array();
				foreach ( $table['rows'] as $row ) {
					$date = self::date( $row['key'] );
					if ( null !== $date ) {
						$dates[] = $date;
					}
				}
				if ( $dates ) {
					sort( $dates );
					$out['period']['start'] = reset( $dates );
					$out['period']['end']   = end( $dates );
					$out['period']['days']  = (int) round( ( strtotime( $out['period']['end'] ) - strtotime( $out['period']['start'] ) ) / 86400 ) + 1;
				}
				continue;
			}

			$out['compare'] = $out['compare'] || $table['compare'];
			$key            = 'page' === $type ? 'pages' : 'queries';
			$out[ $key ]    = array_merge( $out[ $key ], $table['rows'] );

			if ( count( $table['rows'] ) >= self::EXPORT_ROW_CAP ) {
				$out[ $key . '_capped' ] = true;
			}
		}

		foreach ( $filters as $i => $row ) {
			if ( 0 === $i || count( $row ) < 2 ) {
				continue; // Header.
			}
			$label = mb_strtolower( $row[0], 'UTF-8' );
			if ( preg_match( '/date|تاریخ/u', $label ) ) {
				$out['period']['label'] = mb_substr( $row[1], 0, 100 );
			} elseif ( preg_match( '/^(page|query)$|^صفحه$|^پرسش$|^عبارت/u', $label ) ) {
				$out['warnings'][] = 'filtered:' . mb_substr( $row[0] . ' ' . $row[1], 0, 120 );
			}
		}

		if ( array() === $out['pages'] && array() === $out['queries'] ) {
			$out['warnings'][] = 'no_data';
		}

		return $out;
	}

	/**
	 * Parses a localised number: Persian/Arabic digits, thousands separators, decimal marks, percent.
	 *
	 * @param string $value Cell.
	 * @return float|null Null when empty or not a number.
	 */
	public static function number( string $value ): ?float {
		$value = strtr(
			trim( $value ),
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'٫' => '.', '٬' => '', '٪' => '', '%' => '', ' ' => '', "\u{00A0}" => '', "\u{202F}" => '',
			)
		);

		if ( '' === $value || '-' === $value ) {
			return null;
		}

		// "1,234" or "1,234.5" → thousands commas; a lone comma with 1–2 decimals ("3,5") → decimal comma.
		if ( preg_match( '/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value ) ) {
			$value = str_replace( ',', '', $value );
		} elseif ( preg_match( '/^\d+,\d{1,2}$/', $value ) ) {
			$value = str_replace( ',', '.', $value );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Detects which table a file holds, by name first and then by header.
	 *
	 * @param string $name File name.
	 * @param array  $rows Rows.
	 * @return string|null page|query|dates|filters
	 */
	private static function detect_type( string $name, array $rows ): ?string {
		$base = mb_strtolower( basename( $name ), 'UTF-8' );

		if ( preg_match( '/^pages?\b|صفحه/u', $base ) ) {
			return 'page';
		}
		if ( preg_match( '/^quer|پرسش|عبارت/u', $base ) ) {
			return 'query';
		}
		if ( preg_match( '/^dates?\b|تاریخ/u', $base ) ) {
			return 'dates';
		}
		if ( preg_match( '/^filters?\b|فیلتر/u', $base ) ) {
			return 'filters';
		}
		if ( preg_match( '/^(countr|device|search.?appear)|کشور|دستگاه|ظاهر/u', $base ) ) {
			return null;
		}

		// Unknown name (e.g. renamed file): look at the header and first values.
		$header = mb_strtolower( implode( ' ', $rows[0] ), 'UTF-8' );
		if ( ! preg_match( self::METRICS['clicks'], $header ) ) {
			return null;
		}
		$first = $rows[1][0] ?? '';
		if ( preg_match( '#^https?://#i', $first ) ) {
			return 'page';
		}
		if ( null !== self::date( $first ) ) {
			return 'dates';
		}

		return preg_match( '/page|صفحه/u', $header ) ? 'page' : 'query';
	}

	/**
	 * Maps a table with a key column and metric columns (one or two per metric).
	 *
	 * @param array $rows Rows including header.
	 * @return array{rows: array<int, array>, compare: bool}
	 */
	private static function table( array $rows ): array {
		$header  = array_shift( $rows );
		$columns = array();

		foreach ( $header as $index => $label ) {
			if ( 0 === $index ) {
				continue; // The first column is always the key.
			}
			foreach ( self::METRICS as $metric => $pattern ) {
				// "Click" also appears in "Click-through rate"; CTR is checked separately.
				if ( 'clicks' === $metric && preg_match( self::METRICS['ctr'], $label ) ) {
					continue;
				}
				if ( preg_match( $pattern, $label ) ) {
					$columns[ $metric ][] = $index;
					break;
				}
			}
		}

		$compare = isset( $columns['clicks'][1] ) || isset( $columns['impressions'][1] );
		$out     = array();

		foreach ( $rows as $row ) {
			$key = (string) ( $row[0] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			$item = array( 'key' => mb_substr( $key, 0, 2000 ) );
			foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $metric ) {
				$current = isset( $columns[ $metric ][0] ) ? self::number( (string) ( $row[ $columns[ $metric ][0] ] ?? '' ) ) : null;
				$prev    = isset( $columns[ $metric ][1] ) ? self::number( (string) ( $row[ $columns[ $metric ][1] ] ?? '' ) ) : null;

				if ( 'ctr' === $metric ) {
					// Stored as a fraction; Search Console writes percentages ("3.5%").
					$current = self::fraction( $current, (string) ( $row[ $columns[ $metric ][0] ?? -1 ] ?? '' ) );
					$prev    = self::fraction( $prev, (string) ( $row[ $columns[ $metric ][1] ?? -1 ] ?? '' ) );
				}

				$item[ $metric ]           = $current;
				$item[ 'prev_' . $metric ] = $compare ? $prev : null;
			}

			// CTR missing but clicks and impressions present: derive it.
			if ( null === $item['ctr'] && ! empty( $item['impressions'] ) ) {
				$item['ctr'] = (float) $item['clicks'] / (float) $item['impressions'];
			}

			$out[] = $item;
		}

		return array(
			'rows'    => $out,
			'compare' => $compare,
		);
	}

	/**
	 * CTR as a fraction: values written with a percent sign (or above 1) are percentages.
	 *
	 * @param float|null $value Parsed number.
	 * @param string     $raw   Original cell.
	 */
	private static function fraction( ?float $value, string $raw ): ?float {
		if ( null === $value ) {
			return null;
		}

		return ( str_contains( $raw, '%' ) || str_contains( $raw, '٪' ) || $value > 1 ) ? $value / 100 : $value;
	}

	/**
	 * Normalises a date cell (YYYY-MM-DD, with Persian digits) or returns null.
	 *
	 * @param string $value Cell.
	 */
	private static function date( string $value ): ?string {
		$value = strtr( $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );

		if ( preg_match( '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', trim( $value ), $m ) && (int) $m[1] > 1900 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		}

		return null;
	}
}
