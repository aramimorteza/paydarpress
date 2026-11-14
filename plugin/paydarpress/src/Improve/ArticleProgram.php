<?php
/**
 * Article program: low-value articles are rewritten week by week, the ones Google already
 * shows first; articles competing for the same search are merged (owner approval).
 *
 * @package PaydarPress
 */

namespace PaydarWP\PaydarPress\Improve;

use PaydarWP\PaydarPress\Audit\AuditEngine;
use PaydarWP\PaydarPress\Audit\Catalog;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use PaydarWP\PaydarPress\Links\LinkSuggester;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite priority and competing articles.
 */
final class ArticleProgram {

	/** Refresh score from which an item needs work. */
	public const MIN_SCORE = 20;

	/** Pairs the owner chose to keep apart: "a-b" keys. */
	public const OPTION_SKIP = 'paydarpress_compete_skip';

	/** Days after a rewrite before the same item is offered again. */
	public const REST_DAYS = 90;

	/** Title similarity (shared words / all words) from which two articles compete. */
	private const SIMILAR = 0.6;

	/** Codes that explain a refresh score. */
	private const REASONS = array( 'OUTDATED_YEAR', 'THIN_CONTENT', 'DUPLICATE_FOCUS_KEYWORD', 'NO_DIRECT_ANSWER', 'NO_INTERNAL_LINKS', 'BROKEN_INTERNAL_LINK', 'NO_H2', 'GSC_CLICK_DROP', 'GSC_NEAR_PAGE_ONE', 'GSC_LOW_CTR' );

	/**
	 * Rewrites per week: the refresh share of the monthly content capacity.
	 */
	public static function quota(): int {
		$policy = \PaydarWP\PaydarPress\Ai\PromptBuilder::policy();
		$share  = (int) Settings::get( 'policy.refresh_share', 40 );
		return $share > 0 ? max( 1, (int) ceil( max( 1, (int) $policy['budget'] ) * $share / 100 / 4 ) ) : 0;
	}

	/**
	 * Items that need a rewrite, highest priority first. Priority = refresh score × demand
	 * (Search Console impressions): a weak page that Google already shows pays back first.
	 *
	 * @param int $limit Limit.
	 */
	public static function items( int $limit = 50 ): array {
		global $wpdb;
		$inv  = Schema::table( 'inventory' );
		$find = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_id, object_type, refresh_score, word_count, modified_at FROM {$inv} WHERE status = 'publish' AND refresh_score >= %d AND object_type IN ('post','page','product') ORDER BY refresh_score DESC LIMIT 400", self::MIN_SCORE ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		$ids = array_map( static fn( $r ) => (int) $r['object_id'], $rows );
		$in  = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$codes = (array) $wpdb->get_results( "SELECT object_id, code FROM {$find} WHERE object_kind = 'content' AND state IN ('open','reviewed') AND object_id IN ({$in})", ARRAY_A );
		$by    = array();
		foreach ( $codes as $c ) {
			$by[ (int) $c['object_id'] ][] = (string) $c['code'];
		}
		$rested  = self::recently_rewritten();
		$metrics = GscRepository::page_metrics();
		$out     = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['object_id'];
			if ( isset( $rested[ $id ] ) ) {
				continue;
			}
			$c       = $by[ $id ] ?? array();
			$m       = $metrics[ $id ] ?? null;
			$imp     = (int) ( $m['impressions'] ?? 0 );
			$score   = (int) $r['refresh_score'];
			$reasons = array();
			foreach ( self::REASONS as $code ) {
				if ( in_array( $code, $c, true ) ) {
					$rule      = Catalog::get( $code );
					$reasons[] = $rule ? $rule['title'] : $code;
				}
			}
			$out[] = array(
				'id'          => $id,
				'type'        => (string) $r['object_type'],
				'title'       => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'         => (string) get_permalink( $id ),
				'edit_url'    => (string) get_edit_post_link( $id, 'raw' ),
				'score'       => $score,
				'priority'    => self::priority( $score, $imp ),
				'action'      => AuditEngine::refresh_action( $score, $c ),
				'reasons'     => $reasons,
				'words'       => (int) $r['word_count'],
				'updated'     => $r['modified_at'] ? substr( (string) $r['modified_at'], 0, 10 ) : '',
				'clicks'      => (int) ( $m['clicks'] ?? 0 ),
				'impressions' => $imp,
				'position'    => (float) ( $m['position'] ?? 0 ),
			);
		}
		usort( $out, static fn( $a, $b ) => $b['priority'] <=> $a['priority'] ?: $b['score'] <=> $a['score'] );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Rewrite priority: refresh score × demand. log10 keeps a page with 10,000 impressions
	 * from burying every other page (1 + log10(1 + impressions): 0 → ×1, 100 → ×3, 10,000 → ×5).
	 *
	 * @param int $score       Refresh score 0–100.
	 * @param int $impressions Search Console impressions (3 months).
	 */
	public static function priority( int $score, int $impressions ): int {
		return (int) round( max( 0, $score ) * ( 1 + log10( 1 + max( 0, $impressions ) ) ) );
	}

	/**
	 * Two titles compete when they share at least 2 words and most of their words.
	 *
	 * @param string[] $a Title words.
	 * @param string[] $b Title words.
	 */
	public static function similar( array $a, array $b ): bool {
		$a = array_values( array_unique( $a ) );
		$b = array_values( array_unique( $b ) );
		if ( count( $a ) < 2 || count( $b ) < 2 ) {
			return false;
		}
		$shared = count( array_intersect( $a, $b ) );
		$union  = count( array_unique( array_merge( $a, $b ) ) );
		return $shared >= 2 && $shared / $union >= self::SIMILAR;
	}

	/**
	 * Items rewritten (or replaced from an import) in the last REST_DAYS days: id => true.
	 */
	private static function recently_rewritten(): array {
		global $wpdb;
		$t = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$t} WHERE kind IN ('rewrite','merge') AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - self::REST_DAYS * DAY_IN_SECONDS ) ) );
		return array_fill_keys( array_map( 'intval', (array) $ids ), true );
	}

	/**
	 * Rewrites done in the last 7 days.
	 */
	public static function done_this_week(): int {
		global $wpdb;
		$t = Schema::table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT object_id) FROM {$t} WHERE kind = 'rewrite' AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) ) );
	}

	/**
	 * Groups of articles that compete for the same search: the same focus keyword, or titles
	 * that share most of their words. The one that stays is the strongest in Google (clicks,
	 * impressions), then the one with more inbound links and words.
	 *
	 * @param int $limit Limit.
	 * @return array<int, array{keep:array, merge:array<int, array>, why:string, key:string}>
	 */
	public static function competing( int $limit = 20 ): array {
		global $wpdb;
		$inv = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT object_id, title_norm, focus_keyword_norm, word_count, internal_in FROM {$inv} WHERE status = 'publish' AND object_type = 'post' ORDER BY object_id LIMIT 5000", ARRAY_A );
		$n    = count( $rows );
		if ( $n < 2 ) {
			return array();
		}
		$parent = range( 0, $n - 1 );
		$why    = array();
		$find   = static function ( int $i ) use ( &$parent ): int {
			while ( $parent[ $i ] !== $i ) {
				$parent[ $i ] = $parent[ $parent[ $i ] ];
				$i            = $parent[ $i ];
			}
			return $i;
		};
		$join = static function ( int $a, int $b, string $reason ) use ( &$parent, &$why, $find ): void {
			$ra = $find( $a );
			$rb = $find( $b );
			if ( $ra !== $rb ) {
				$parent[ $rb ] = $ra;
			}
			$why[ $a ] = $why[ $a ] ?? $reason;
			$why[ $b ] = $why[ $b ] ?? $reason;
		};

		// Same focus keyword.
		$by_focus = array();
		foreach ( $rows as $i => $r ) {
			$f = trim( (string) $r['focus_keyword_norm'] );
			if ( '' !== $f ) {
				$by_focus[ $f ][] = $i;
			}
		}
		foreach ( $by_focus as $f => $list ) {
			for ( $k = 1; $k < count( $list ); $k++ ) {
				$join( $list[0], $list[ $k ], 'کلمه کلیدی یکسان «' . $f . '»' );
			}
		}

		// Similar titles: compare only pairs that share a word (inverted index), skipping very common words.
		$tokens = array();
		$index  = array();
		foreach ( $rows as $i => $r ) {
			$tokens[ $i ] = LinkSuggester::tokens( (string) $r['title_norm'] );
			foreach ( $tokens[ $i ] as $w ) {
				$index[ $w ][] = $i;
			}
		}
		$common = max( 8, (int) ( $n / 10 ) );
		$seen   = array();
		foreach ( $index as $list ) {
			if ( count( $list ) < 2 || count( $list ) > $common ) {
				continue;
			}
			foreach ( $list as $x => $i ) {
				foreach ( array_slice( $list, $x + 1 ) as $j ) {
					$key = $i . '-' . $j;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					if ( self::similar( $tokens[ $i ], $tokens[ $j ] ) ) {
						$join( $i, $j, 'عنوان تقریباً یکسان' );
					}
				}
			}
		}

		$groups = array();
		foreach ( array_keys( $rows ) as $i ) {
			$groups[ $find( $i ) ][] = $i;
		}
		$skip    = self::skipped();
		$metrics = GscRepository::page_metrics();
		$out     = array();
		foreach ( $groups as $members ) {
			if ( count( $members ) < 2 ) {
				continue;
			}
			$items = array();
			foreach ( $members as $i ) {
				$id      = (int) $rows[ $i ]['object_id'];
				$m       = $metrics[ $id ] ?? array();
				$items[] = array(
					'id'          => $id,
					'title'       => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					'url'         => (string) get_permalink( $id ),
					'clicks'      => (int) ( $m['clicks'] ?? 0 ),
					'impressions' => (int) ( $m['impressions'] ?? 0 ),
					'position'    => (float) ( $m['position'] ?? 0 ),
					'inbound'     => (int) $rows[ $i ]['internal_in'],
					'words'       => (int) $rows[ $i ]['word_count'],
					'why'         => $why[ $i ] ?? '',
				);
			}
			usort( $items, static fn( $a, $b ) => array( $b['clicks'], $b['impressions'], $b['inbound'], $b['words'] ) <=> array( $a['clicks'], $a['impressions'], $a['inbound'], $a['words'] ) );
			$keep  = array_shift( $items );
			$items = array_values( array_filter( $items, static fn( $o ) => ! isset( $skip[ self::pair( $keep['id'], $o['id'] ) ] ) ) );
			if ( ! $items ) {
				continue;
			}
			$out[] = array(
				'key'   => (string) $keep['id'],
				'keep'  => $keep,
				'merge' => $items,
				'why'   => $items[0]['why'] ?: $keep['why'],
			);
		}
		usort( $out, static fn( $a, $b ) => ( $b['keep']['impressions'] + array_sum( array_column( $b['merge'], 'impressions' ) ) ) <=> ( $a['keep']['impressions'] + array_sum( array_column( $a['merge'], 'impressions' ) ) ) );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Owner keeps two articles apart (different angles): they are not offered again.
	 *
	 * @param int $a One article.
	 * @param int $b The other.
	 */
	public static function keep_apart( int $a, int $b ): void {
		$skip                       = self::skipped();
		$skip[ self::pair( $a, $b ) ] = time();
		update_option( self::OPTION_SKIP, array_slice( $skip, -500, null, true ), false );
		\PaydarWP\PaydarPress\Strategy\ChangeLog::record( 'decision', $a, sprintf( 'دو مقاله جدا ماندند: «%s» و «%s» (زاویه متفاوت)', html_entity_decode( get_the_title( $a ), ENT_QUOTES, 'UTF-8' ), html_entity_decode( get_the_title( $b ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Stored "keep apart" pairs.
	 */
	private static function skipped(): array {
		$s = get_option( self::OPTION_SKIP, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Order-independent key of a pair.
	 *
	 * @param int $a One.
	 * @param int $b Other.
	 */
	private static function pair( int $a, int $b ): string {
		return min( $a, $b ) . '-' . max( $a, $b );
	}

	/**
	 * Program state for the progress page.
	 */
	public static function state(): array {
		global $wpdb;
		$inv   = Schema::table( 'inventory' );
		$items = self::items( 200 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv} WHERE status = 'publish' AND object_type IN ('post','page','product')" );
		$quota = self::quota();
		$done  = self::done_this_week();
		$comp  = self::competing();
		return array(
			'needs'     => count( $items ),
			'total'     => $total,
			'percent'   => $total > 0 ? (int) round( 100 * ( $total - count( $items ) ) / $total ) : 100,
			'quota'     => $quota,
			'done_week' => $done,
			'week'      => array_slice( $items, 0, max( 0, $quota - $done ) ),
			'next'      => array_slice( $items, max( 0, $quota - $done ), 5 ),
			'competing' => $comp,
			'rest_days' => self::REST_DAYS,
		);
	}
}
