<?php
/**
 * What the Search Console queries say about the site: keyword patterns, audience questions,
 * locations, pages that deserve to be key pages, and searches no article answers yet.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Gsc;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Links\KeyPageLinks;
use PaydarWP\PaydarPress\Profile\ProfileInference;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Everything here is a suggestion: nothing is written to the profile until the owner adds it.
 */
final class GscInsights {

	/** Words that change what a query asks for; the rest of the query is the topic. */
	private const MODIFIERS = array(
		'قیمت', 'هزینه', 'تعرفه', 'خرید', 'فروش', 'ارزان', 'بهترین', 'لیست', 'انواع', 'مقایسه', 'تفاوت', 'فرق',
		'آموزش', 'نحوه', 'روش', 'مراحل', 'شرایط', 'مدارک', 'نمونه', 'فرم', 'مشاوره', 'آدرس', 'شماره', 'تلفن',
		'رایگان', 'آنلاین', 'نزدیک', 'معرفی', 'راهنمای', 'مزایا', 'معایب', 'عوارض', 'علت', 'علائم', 'درمان',
		'چیست', 'چیه', 'چگونه', 'چطور', 'کجا', 'دانلود', 'pdf', 'جدید', '۱۴۰۴', '۱۴۰۵', '1404', '1405',
	);

	/** A query that contains one of these is a question from the audience. */
	private const QUESTION_WORDS = array( 'چیست', 'چیه', 'چطور', 'چطوری', 'چگونه', 'چرا', 'آیا', 'کجا', 'چه', 'چقدر', 'چند', 'کدام', 'کی', 'نحوه', 'راه', 'میشه', 'می شود', 'میتوان' );

	/** Not counted when deciding whether an article covers a query. */
	private const STOP = array( 'از', 'به', 'با', 'در', 'و', 'که', 'را', 'این', 'آن', 'برای', 'است', 'هست', 'یک', 'می', 'شود', 'میشود', 'کرد', 'دارد', 'بر', 'تا', 'یا', 'هم', 'ها', 'های', 'چیست', 'چیه', 'چگونه', 'چطور', 'چه', 'چرا', 'آیا', 'کجا', 'چقدر', 'چند', 'کدام', 'بهترین', 'قیمت', 'هزینه', 'نحوه', 'روش', 'شرایط', 'مراحل' );

	/** Queries with fewer impressions are noise for suggestions. */
	public const MIN_IMPRESSIONS = 10;

	/** Share of a query's meaningful words an article title/keyword must contain to count as covering it. */
	private const COVER_SHARE = 0.75;

	/**
	 * All query rows (Search Console exports at most 1,000).
	 *
	 * @return array<int, array{query: string, norm: string, clicks: int, impressions: int, position: float}>
	 */
	public static function queries(): array {
		global $wpdb;

		$table = Schema::table( 'gsc' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT item, clicks, impressions, position FROM {$table} WHERE kind = 'query' ORDER BY impressions DESC LIMIT 2000", ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$norm = PersianNormalizer::for_key( (string) $row['item'] );
			if ( '' === $norm ) {
				continue;
			}
			$out[] = array(
				'query'       => (string) $row['item'],
				'norm'        => $norm,
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => round( (float) $row['position'], 1 ),
			);
		}
		return $out;
	}

	/**
	 * Profile suggestions from the queries.
	 *
	 * @return array{available: bool, queries: int, patterns: array, questions: array, places: array, key_pages: array, fields: array<string, string>}
	 */
	public static function profile(): array {
		$queries = array_values( array_filter( self::queries(), static fn( $q ) => $q['impressions'] >= self::MIN_IMPRESSIONS || $q['clicks'] > 0 ) );
		$queries = self::without_brand( $queries );

		$patterns  = self::patterns( $queries );
		$questions = self::questions( $queries );
		$places    = self::places( $queries );
		$key_pages = self::key_page_candidates();

		$fields = array();
		if ( $patterns ) {
			$fields['primary_keyword_patterns'] = implode( "\n", array_map( static fn( $p ) => $p['pattern'] . ' (مثل: ' . implode( '، ', array_slice( $p['examples'], 0, 2 ) ) . ')', $patterns ) );
		}
		if ( $questions ) {
			$fields['audience_problems'] = implode( "\n", array_map( static fn( $q ) => $q['query'], array_slice( $questions, 0, 8 ) ) );
		}
		if ( $places ) {
			$total = array_sum( array_column( $places, 'impressions' ) );
			$top   = $places[0];
			$fields['geographic_scope'] = $total > 0 && $top['impressions'] / $total >= 0.6 ? $top['place'] : implode( '، ', array_slice( array_column( $places, 'place' ), 0, 4 ) );
		}

		return array(
			'available' => GscRepository::has_data(),
			'queries'   => count( $queries ),
			'patterns'  => $patterns,
			'questions' => array_map( static fn( $q ) => array_diff_key( $q, array( 'norm' => 1 ) ), array_slice( $questions, 0, 15 ) ),
			'places'    => $places,
			'key_pages' => $key_pages,
			'fields'    => $fields,
		);
	}

	/**
	 * Recurring "[modifier] [X]" / "[X] [modifier]" / "[X] در [city]" shapes.
	 *
	 * @param array $queries Queries.
	 * @return array<int, array{pattern: string, queries: int, impressions: int, examples: string[]}>
	 */
	public static function patterns( array $queries ): array {
		$found = array();
		$cities = array_map( array( PersianNormalizer::class, 'for_key' ), ProfileInference::CITIES );

		foreach ( $queries as $q ) {
			$words = explode( ' ', $q['norm'] );
			if ( count( $words ) < 2 ) {
				continue;
			}
			$shapes = array();
			$first  = $words[0];
			$last   = end( $words );
			if ( in_array( $first, self::MODIFIERS, true ) ) {
				$shapes[] = $first . ' [موضوع]';
			}
			if ( in_array( $last, self::MODIFIERS, true ) && $last !== $first ) {
				$shapes[] = '[موضوع] ' . $last;
			}
			foreach ( $cities as $city ) {
				if ( '' !== $city && false !== mb_strpos( ' ' . $q['norm'] . ' ', ' ' . $city . ' ' ) ) {
					$shapes[] = false !== mb_strpos( $q['norm'], 'در ' . $city ) ? '[موضوع] در [شهر]' : '[موضوع] [شهر]';
					break;
				}
			}
			foreach ( array_unique( $shapes ) as $shape ) {
				if ( ! isset( $found[ $shape ] ) ) {
					$found[ $shape ] = array( 'pattern' => $shape, 'queries' => 0, 'impressions' => 0, 'examples' => array() );
				}
				++$found[ $shape ]['queries'];
				$found[ $shape ]['impressions'] += $q['impressions'];
				if ( count( $found[ $shape ]['examples'] ) < 4 ) {
					$found[ $shape ]['examples'][] = $q['query'];
				}
			}
		}

		$found = array_values( array_filter( $found, static fn( $p ) => $p['queries'] >= 2 ) );
		usort( $found, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		return array_slice( $found, 0, 8 );
	}

	/**
	 * Question-like queries: what the audience wants to know.
	 *
	 * @param array $queries Queries.
	 */
	public static function questions( array $queries ): array {
		$out = array();
		foreach ( $queries as $q ) {
			$padded = ' ' . $q['norm'] . ' ';
			foreach ( self::QUESTION_WORDS as $word ) {
				if ( false !== mb_strpos( $padded, ' ' . $word . ' ' ) ) {
					$out[] = $q;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Cities named in queries.
	 *
	 * @param array $queries Queries.
	 * @return array<int, array{place: string, queries: int, impressions: int}>
	 */
	public static function places( array $queries ): array {
		$found = array();
		foreach ( ProfileInference::CITIES as $city ) {
			$key = PersianNormalizer::for_key( $city );
			foreach ( $queries as $q ) {
				if ( false !== mb_strpos( ' ' . $q['norm'] . ' ', ' ' . $key . ' ' ) ) {
					if ( ! isset( $found[ $city ] ) ) {
						$found[ $city ] = array( 'place' => $city, 'queries' => 0, 'impressions' => 0 );
					}
					++$found[ $city ]['queries'];
					$found[ $city ]['impressions'] += $q['impressions'];
				}
			}
		}
		$found = array_values( $found );
		usort( $found, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		return array_slice( $found, 0, 6 );
	}

	/**
	 * Pages (not posts) with many clicks that are not key pages yet.
	 *
	 * @return array<int, array{id: int, title: string, clicks: int, impressions: int}>
	 */
	public static function key_page_candidates(): array {
		$current = KeyPageLinks::pages();
		$system  = array_flip( AuditEngine::system_page_ids() );
		$out     = array();

		foreach ( GscRepository::page_metrics() as $id => $m ) {
			if ( isset( $current[ $id ] ) || isset( $system[ $id ] ) || $m['clicks'] < 5 || 'page' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || (int) get_option( 'page_on_front' ) === $id ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) $id,
				'title'       => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'clicks'      => $m['clicks'],
				'impressions' => $m['impressions'],
			);
		}
		usort( $out, static fn( $a, $b ) => $b['clicks'] <=> $a['clicks'] );
		return array_slice( $out, 0, 5 );
	}

	/**
	 * Searches the site appears for, but no article title, focus keyword or suggested topic covers.
	 *
	 * @param int $min_impressions Minimum impressions.
	 * @return array<int, array{query: string, clicks: int, impressions: int, position: float, status: string, closest: ?array}>
	 */
	public static function gaps( int $min_impressions = self::MIN_IMPRESSIONS ): array {
		global $wpdb;

		$queries = array_values( array_filter( self::without_brand( self::queries() ), static fn( $q ) => $q['impressions'] >= $min_impressions ) );
		if ( ! $queries ) {
			return array();
		}

		// Inverted index: meaningful word → items whose title or focus keyword contains it.
		$inventory = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT object_id, title_norm, focus_keyword_norm FROM {$inventory} WHERE status IN ('publish','future','draft','pending') LIMIT 20000", ARRAY_A );
		$items = array();
		$index = array();
		foreach ( (array) $rows as $row ) {
			$id           = (int) $row['object_id'];
			$words        = self::words( PersianNormalizer::for_key( (string) $row['title_norm'] ) . ' ' . (string) $row['focus_keyword_norm'] );
			$items[ $id ] = 'post';
			foreach ( $words as $w ) {
				$index[ $w ][ $id ] = true;
			}
		}

		$topics_table = Schema::table( 'topics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$topics = $wpdb->get_results( "SELECT id, title_norm, focus_keyword, status FROM {$topics_table} WHERE status IN ('new','accepted')", ARRAY_A );
		foreach ( (array) $topics as $topic ) {
			$tid           = 't' . (int) $topic['id'];
			$items[ $tid ] = 'topic';
			foreach ( self::words( (string) $topic['title_norm'] . ' ' . PersianNormalizer::for_key( (string) $topic['focus_keyword'] ) ) as $w ) {
				$index[ $w ][ $tid ] = true;
			}
		}

		$out = array();
		foreach ( $queries as $q ) {
			$words = self::words( $q['norm'] );
			if ( ! $words ) {
				continue;
			}
			$hits = array();
			foreach ( $words as $w ) {
				foreach ( array_keys( $index[ $w ] ?? array() ) as $id ) {
					$hits[ $id ] = ( $hits[ $id ] ?? 0 ) + 1;
				}
			}
			arsort( $hits );
			$best_id = key( $hits );
			$best    = null === $best_id ? 0 : $hits[ $best_id ] / count( $words );

			if ( $best >= self::COVER_SHARE ) {
				if ( 'topic' === $items[ $best_id ] ) {
					$out[] = $q + array( 'status' => 'planned', 'closest' => null );
				}
				continue;
			}

			$closest = null;
			if ( null !== $best_id && 'post' === $items[ $best_id ] && $best >= 0.34 ) {
				$closest = array(
					'id'    => (int) $best_id,
					'title' => html_entity_decode( get_the_title( (int) $best_id ), ENT_QUOTES, 'UTF-8' ),
					'url'   => rawurldecode( (string) get_permalink( (int) $best_id ) ),
				);
			}
			$out[] = $q + array( 'status' => null === $closest ? 'gap' : 'partial', 'closest' => $closest );
		}

		foreach ( $out as &$row ) {
			unset( $row['norm'] );
		}
		unset( $row );

		return $out;
	}

	/**
	 * Meaningful words of a normalized string.
	 *
	 * @param string $norm Normalized text.
	 * @return string[]
	 */
	private static function words( string $norm ): array {
		$words = array_filter( explode( ' ', $norm ), static fn( $w ) => mb_strlen( $w ) > 1 && ! in_array( $w, self::STOP, true ) && ! preg_match( '/^\d+$/u', $w ) );
		return array_values( array_unique( $words ) );
	}

	/**
	 * Drops navigational searches for the brand itself (they say nothing about topics).
	 *
	 * @param array $queries Queries.
	 */
	private static function without_brand( array $queries ): array {
		$brands = array_filter(
			array(
				PersianNormalizer::for_key( KeyPageLinks::brand() ),
				PersianNormalizer::for_key( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ),
			),
			static fn( $b ) => mb_strlen( $b ) >= 3
		);
		if ( ! $brands ) {
			return $queries;
		}
		return array_values(
			array_filter(
				$queries,
				static function ( $q ) use ( $brands ) {
					foreach ( $brands as $b ) {
						if ( false !== mb_strpos( $q['norm'], $b ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Adds a query as a suggested topic (once).
	 *
	 * @param string $query Query text.
	 * @return array{id: int, created: bool}
	 */
	public static function add_topic( string $query, int $impressions, float $position ): array {
		global $wpdb;

		$query = mb_substr( sanitize_text_field( $query ), 0, 200 );
		$norm  = PersianNormalizer::for_key( $query );
		$table = Schema::table( 'topics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE title_norm = %s LIMIT 1", $norm ) );
		if ( $existing > 0 ) {
			return array( 'id' => $existing, 'created' => false );
		}

		$id = \PaydarWP\PaydarPress\Import\TopicRepository::insert(
			array(
				'title'         => $query,
				'focus_keyword' => $query,
				'intent'        => '',
				'notes'         => sprintf( 'از سرچ کنسول: %s نمایش، جایگاه میانگین %s. عنوان را ContentMaster یا خودتان بازنویسی کنید.', PersianNormalizer::fa_number( $impressions ), PersianNormalizer::fa_number( $position ) ),
			)
		);
		return array( 'id' => $id, 'created' => true );
	}
}
