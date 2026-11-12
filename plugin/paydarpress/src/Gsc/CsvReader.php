<?php
/**
 * Small CSV reader for uploaded files: BOM, delimiter detection, row limit. WordPress-independent.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Gsc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV reader.
 */
final class CsvReader {

	/**
	 * Parses CSV text into rows of cells.
	 *
	 * @param string $text     CSV content.
	 * @param int    $max_rows Maximum data rows (header excluded); extra rows are dropped.
	 * @return array{rows: array<int, string[]>, truncated: bool}
	 */
	public static function parse( string $text, int $max_rows = 50000 ): array {
		$text = self::to_utf8( $text );

		// Strip a UTF-8 BOM.
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}

		$first_line = strtok( $text, "\r\n" );
		$delimiter  = self::delimiter( false === $first_line ? '' : $first_line );

		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return array(
				'rows'      => array(),
				'truncated' => false,
			);
		}
		fwrite( $stream, $text );
		rewind( $stream );

		$rows      = array();
		$truncated = false;

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $cells = fgetcsv( $stream, 0, $delimiter, '"', '' ) ) ) {
			if ( array( null ) === $cells ) {
				continue; // Blank line.
			}
			if ( count( $rows ) > $max_rows ) {
				$truncated = true;
				break;
			}
			$rows[] = array_map( static fn( $c ) => trim( (string) $c ), $cells );
		}

		fclose( $stream );

		return array(
			'rows'      => $rows,
			'truncated' => $truncated,
		);
	}

	/**
	 * Most frequent of comma, semicolon and tab in the header line.
	 *
	 * @param string $line Header line.
	 */
	private static function delimiter( string $line ): string {
		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );

		return (string) array_key_first( $counts );
	}

	/**
	 * Converts UTF-16 (Excel "Unicode text") to UTF-8; leaves UTF-8 untouched.
	 *
	 * @param string $text Raw bytes.
	 */
	private static function to_utf8( string $text ): string {
		if ( str_starts_with( $text, "\xFF\xFE" ) || str_starts_with( $text, "\xFE\xFF" ) ) {
			$converted = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'UTF-16' ) : false;
			return is_string( $converted ) ? $converted : '';
		}

		return $text;
	}
}
