<?php
/**
 * Tag improvement: value score per tag, a weekly sweep for duplicates (same word written
 * differently, plurals, one-letter typos, tags on the same posts), and new tags people
 * actually search for. Duplicates and low-value tags become decision cards; nothing is
 * merged or deleted without the owner.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Improve;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Profile\TaxonomyMap;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Tag value, duplicates and search-suggested tags.
 */
final class TagProgram {

	public const OPTION_DUPS = 'paydarpress_tag_dups';
	public const LOW_SCORE   = 25;
	public const TAXES       = array( 'post_tag', 'product_tag' );

	/**
	 * Value 0–100: items (up to 40), coherence of its items (up to 20), search demand (up to 30),
	 * role in the taxonomy map (10 for «main»).
	 *
	 * @param WP_Term $t      Tag.
	 * @param array   $demand query impressions keyed by normalized tag name (optional cache).
	 */
	public static function score( WP_Term $t, array $demand = array() ): int {
		global $wpdb;
		$score = min( 40, (int) $t->count * 8 );
		if ( (int) $t->count >= 2 ) {
			$inv = Schema::table( 'inventory' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT i.primary_term_id FROM {$wpdb->term_relationships} r JOIN {$inv} i ON i.object_id = r.object_id WHERE r.term_taxonomy_id = %d", (int) $t->term_taxonomy_id ) );
			$by   = array_count_values( array_map( 'strval', array_filter( (array) $rows ) ) );
			if ( $by ) {
				$score += (int) round( 20 * max( $by ) / max( 1, count( (array) $rows ) ) );
			}
		}
		$key = PersianNormalizer::for_key( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) );
		$imp = $demand[ $key ] ?? self::demand( $key );
		$score += (int) min( 30, round( log10( $imp + 1 ) * 10 ) );
		if ( 'high' === TaxonomyMap::role( (int) $t->term_id ) ) {
			$score += 10;
		}
		return min( 100, $score );
	}

	/**
	 * Search Console impressions of queries containing a phrase.
	 *
	 * @param string $key Normalized phrase.
	 */
	public static function demand( string $key ): int {
		global $wpdb;
		if ( mb_strlen( $key ) < 2 ) {
			return 0;
		}
		$table = Schema::table( 'gsc' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(impressions) FROM {$table} WHERE kind = 'query' AND item LIKE %s", '%' . $wpdb->esc_like( $key ) . '%' ) );
	}

	/**
	 * Key for "same word written differently": no spaces / half-spaces, no plural ending.
	 *
	 * @param string $name Tag name.
	 */
	public static function dup_key( string $name ): string {
		$k = PersianNormalizer::for_key( $name );
		$k = (string) preg_replace( '/[\s\x{200C}\-_]+/u', '', $k );
		$k = (string) preg_replace( '/(های|ها)$/u', '', $k );
		return $k;
	}

	/**
	 * Finds duplicate groups in one tag taxonomy. Each group keeps the highest-value tag.
	 *
	 * @param string $tax post_tag|product_tag.
	 */
	public static function duplicates( string $tax ): array {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 400, 'orderby' => 'count', 'order' => 'DESC' ) );
		$terms = array_values( array_filter( is_array( $terms ) ? $terms : array(), static fn( $t ) => $t instanceof WP_Term ) );
		$n     = count( $terms );
		$keys  = array_map( static fn( $t ) => self::dup_key( html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ), $terms );

		// Post sets of tags with 3+ items (for "used on the same posts").
		$posts = array();
		foreach ( $terms as $i => $t ) {
			if ( (int) $t->count >= 3 ) {
				$posts[ $i ] = array_flip( array_map( 'intval', (array) get_objects_in_term( (int) $t->term_id, $tax ) ) );
			}
		}

		// Union-find over pairs.
		$parent = range( 0, max( 0, $n - 1 ) );
		$why    = array();
		$find   = static function ( $x ) use ( &$parent ) {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ];
				$x            = $parent[ $x ];
			}
			return $x;
		};
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$reason = '';
				if ( '' !== $keys[ $i ] && $keys[ $i ] === $keys[ $j ] ) {
					$reason = 'همان کلمه با نوشتار دیگر';
				} elseif ( mb_strlen( $keys[ $i ] ) >= 5 && mb_strlen( $keys[ $j ] ) >= 5 && self::one_edit( $keys[ $i ], $keys[ $j ] ) ) {
					$reason = 'یک حرف تفاوت';
				} elseif ( isset( $posts[ $i ], $posts[ $j ] ) ) {
					$common = count( array_intersect_key( $posts[ $i ], $posts[ $j ] ) );
					$union  = count( $posts[ $i ] ) + count( $posts[ $j ] ) - $common;
					if ( $union > 0 && $common / $union >= 0.6 ) {
						$reason = 'روی تقریباً همان مطالب';
					}
				}
				if ( '' !== $reason ) {
					$parent[ $find( $j ) ] = $find( $i );
					$why[ $j ]             = $why[ $j ] ?? $reason;
					$why[ $i ]             = $why[ $i ] ?? $reason;
				}
			}
		}

		$groups = array();
		for ( $i = 0; $i < $n; $i++ ) {
			if ( isset( $why[ $i ] ) ) {
				$groups[ $find( $i ) ][] = $i;
			}
		}
		$out = array();
		foreach ( $groups as $members ) {
			if ( count( $members ) < 2 ) {
				continue;
			}
			$scored = array();
			foreach ( $members as $i ) {
				// Tie-break: correct Persian spelling (half-space instead of a space).
				$scored[ $i ] = self::score( $terms[ $i ] ) * 10 + ( str_contains( $terms[ $i ]->name, "\u{200C}" ) ? 1 : 0 );
			}
			arsort( $scored );
			$keep  = (int) array_key_first( $scored );
			$merge = array();
			foreach ( $scored as $i => $sc ) {
				if ( $i !== $keep ) {
					$merge[] = array( 'id' => (int) $terms[ $i ]->term_id, 'name' => html_entity_decode( $terms[ $i ]->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $terms[ $i ]->count, 'why' => $why[ $i ] );
				}
			}
			$out[] = array(
				'taxonomy' => $tax,
				'keep'     => array( 'id' => (int) $terms[ $keep ]->term_id, 'name' => html_entity_decode( $terms[ $keep ]->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $terms[ $keep ]->count, 'score' => intdiv( $scored[ $keep ], 10 ) ),
				'merge'    => $merge,
			);
		}
		return $out;
	}

	/**
	 * Whether two strings differ by one insertion, deletion or substitution (multibyte-safe).
	 *
	 * @param string $a A.
	 * @param string $b B.
	 */
	public static function one_edit( string $a, string $b ): bool {
		$x = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
		$y = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
		$m = count( $x );
		$n = count( $y );
		if ( abs( $m - $n ) > 1 || $a === $b ) {
			return false;
		}
		$i    = 0;
		$j    = 0;
		$edit = 0;
		while ( $i < $m && $j < $n ) {
			if ( $x[ $i ] === $y[ $j ] ) {
				++$i;
				++$j;
				continue;
			}
			if ( ++$edit > 1 ) {
				return false;
			}
			if ( $m > $n ) {
				++$i;
			} elseif ( $n > $m ) {
				++$j;
			} else {
				++$i;
				++$j;
			}
		}
		return $edit + ( $m - $i ) + ( $n - $j ) <= 1;
	}

	/**
	 * Weekly sweep: stores duplicate groups for the decision cards.
	 */
	public static function sweep(): array {
		$all = array();
		foreach ( self::TAXES as $tax ) {
			$all = array_merge( $all, self::duplicates( $tax ) );
		}
		update_option( self::OPTION_DUPS, array( 'at' => time(), 'groups' => $all ), false );
		return $all;
	}

	/**
	 * Stored duplicate groups (sweeps when older than a week).
	 */
	public static function groups(): array {
		$d = get_option( self::OPTION_DUPS, array() );
		if ( ! is_array( $d ) || empty( $d['at'] ) || time() - (int) $d['at'] > WEEK_IN_SECONDS ) {
			return self::sweep();
		}
		return (array) ( $d['groups'] ?? array() );
	}

	/**
	 * Term id => merge target for every tag that should be merged.
	 */
	public static function merge_map(): array {
		$out = array();
		foreach ( self::groups() as $g ) {
			foreach ( $g['merge'] as $m ) {
				if ( term_exists( (int) $m['id'] ) && term_exists( (int) $g['keep']['id'] ) ) {
					$out[ (int) $m['id'] ] = array( 'into' => $g['keep'], 'why' => $m['why'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Searches that deserve a tag: frequent Search Console queries (1–3 words) that no tag or
	 * category covers yet.
	 *
	 * @param int $limit Suggestions.
	 */
	public static function suggested( int $limit = 8 ): array {
		$have = array();
		foreach ( array( 'post_tag', 'category', 'product_tag', 'product_cat' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			foreach ( (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 0, 'fields' => 'names' ) ) as $name ) {
				$have[ self::dup_key( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ) ] = true;
			}
		}
		$brand = self::dup_key( (string) get_bloginfo( 'name' ) );
		$out   = array();
		foreach ( \PaydarWP\PaydarPress\Gsc\GscRepository::queries( 400 )['items'] as $q ) {
			$text  = trim( (string) $q['item'] );
			$words = preg_split( '/\s+/u', $text );
			if ( count( $words ) > 2 || $q['impressions'] < 50 || preg_match( '/\d|چیست|چگونه|چطور|چقدر|کجا|قیمت|خرید|هزینه|رایگان|مدارک|شرایط|نحوه|بهترین|ارزان|نزدیک|آنلاین/u', $text ) ) {
				continue;
			}
			$key = self::dup_key( $text );
			if ( '' === $key || isset( $have[ $key ] ) || ( '' !== $brand && false !== mb_strpos( $key, $brand ) ) ) {
				continue;
			}
			$have[ $key ] = true;
			$out[]        = array(
				'name'        => $text,
				'impressions' => (int) $q['impressions'],
				'position'    => (float) $q['position'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Program state.
	 */
	public static function state(): array {
		$total = 0;
		$good  = 0;
		$low   = 0;
		foreach ( self::TAXES as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			foreach ( (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 2000 ) ) as $t ) {
				if ( ! $t instanceof WP_Term ) {
					continue;
				}
				++$total;
				if ( (int) $t->count >= 3 ) {
					++$good;
				} elseif ( (int) $t->count <= 1 ) {
					++$low;
				}
			}
		}
		$dups = 0;
		foreach ( self::groups() as $g ) {
			$dups += count( $g['merge'] );
		}
		return array(
			'total'  => $total,
			'good'   => $good,
			'low'    => $low,
			'dups'   => $dups,
			'over'   => self::over_cap(),
			'percent' => $total > 0 ? (int) round( 100 * $good / $total ) : 100,
		);
	}

	/**
	 * Items with more tags than the cap (all tag taxonomies).
	 */
	public static function over_cap(): int {
		global $wpdb;
		$n = 0;
		foreach ( self::TAXES as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$max = TaxonomyMap::max_tags( $tax );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT r.object_id FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE tt.taxonomy = %s GROUP BY r.object_id HAVING COUNT(*) > %d ) x", $tax, $max ) );
		}
		return $n;
	}
}
