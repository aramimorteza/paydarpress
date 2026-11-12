<?php
/**
 * Suggests existing content that should link to a given item.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores candidates by shared primary category, shared tags and shared title words.
 * Phase 1 is rule-based; semantic similarity (embeddings) arrives in phase 2.
 */
final class LinkSuggester {

	private const STOPWORDS = array( 'از', 'به', 'با', 'در', 'و', 'که', 'را', 'این', 'آن', 'برای', 'چگونه', 'چیست', 'است', 'چه', 'یک', 'های', 'ها', 'می', 'شود', 'کرد', 'کنیم', 'چطور', 'چرا', 'کدام', 'بر', 'تا', 'یا', 'هم', 'نیز', 'the', 'and', 'for', 'how', 'what' );

	/** @var array<int, array{title:string, type:string, term:int, tokens:string[]}>|null */
	private $candidates = null;

	/**
	 * Top suggestions for a target.
	 *
	 * @param int $target_id Target content ID.
	 * @param int $limit     Max suggestions.
	 * @return array<int, array{id:int, title:string, url:string, edit_url:string, score:int, reasons:string[]}>
	 */
	public function for_target( int $target_id, int $limit = 5 ): array {
		$candidates = $this->candidates();
		if ( ! isset( $candidates[ $target_id ] ) ) {
			return array();
		}

		$target       = $candidates[ $target_id ];
		$already      = $this->sources_linking_to( $target_id );
		$tag_overlap  = $this->tag_overlap( $target_id );
		$target_words = array_flip( $target['tokens'] );
		$scored       = array();

		foreach ( $candidates as $id => $candidate ) {
			if ( $id === $target_id || isset( $already[ $id ] ) || 'post' !== $candidate['type'] ) {
				continue;
			}

			$score   = 0;
			$reasons = array();

			if ( $target['term'] > 0 && $candidate['term'] === $target['term'] ) {
				$score    += 3;
				$reasons[] = 'هم‌دسته';
			}

			if ( ! empty( $tag_overlap[ $id ] ) ) {
				$score    += min( 3, $tag_overlap[ $id ] );
				$reasons[] = 'برچسب مشترک';
			}

			$shared = count( array_intersect_key( $target_words, array_flip( $candidate['tokens'] ) ) );
			if ( $shared > 0 ) {
				$score    += 2 * min( 3, $shared );
				$reasons[] = 'موضوع مشابه';
			}

			if ( $score > 0 ) {
				$scored[ $id ] = array( $score, $reasons );
			}
		}

		uasort( $scored, static fn( $a, $b ) => $b[0] <=> $a[0] );

		$result = array();
		foreach ( array_slice( $scored, 0, $limit, true ) as $id => list( $score, $reasons ) ) {
			$result[] = array(
				'id'       => $id,
				'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'      => (string) get_permalink( $id ),
				'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
				'score'    => $score,
				'reasons'  => $reasons,
			);
		}

		return $result;
	}

	/**
	 * Suggested anchor text for linking to a target: its focus keyword, else its title.
	 *
	 * @param int $target_id Target content ID.
	 */
	public function anchor_for( int $target_id ): string {
		global $wpdb;

		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keyword = $wpdb->get_var( $wpdb->prepare( "SELECT focus_keyword FROM {$table} WHERE object_id = %d", $target_id ) );

		return null !== $keyword && '' !== $keyword ? (string) $keyword : html_entity_decode( get_the_title( $target_id ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Published posts and pages with tokens (loaded once).
	 *
	 * @return array<int, array{title:string, type:string, term:int, tokens:string[]}>
	 */
	private function candidates(): array {
		if ( null !== $this->candidates ) {
			return $this->candidates;
		}

		global $wpdb;

		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_id, object_type, title_norm, focus_keyword_norm, primary_term_id FROM {$table} WHERE status = 'publish' AND object_type IN ('post', 'page', 'product')", ARRAY_A );

		$this->candidates = array();
		foreach ( (array) $rows as $row ) {
			$this->candidates[ (int) $row['object_id'] ] = array(
				'title'  => (string) $row['title_norm'],
				'type'   => (string) $row['object_type'],
				'term'   => (int) $row['primary_term_id'],
				'tokens' => self::tokens( $row['title_norm'] . ' ' . $row['focus_keyword_norm'] ),
			);
		}

		return $this->candidates;
	}

	/**
	 * Meaningful words (3+ letters, not stopwords).
	 *
	 * @param string $text Normalized text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();

		return array_values(
			array_unique(
				array_filter(
					$words,
					static fn( $w ) => mb_strlen( $w ) >= 3 && ! in_array( $w, self::STOPWORDS, true ) && ! ctype_digit( $w )
				)
			)
		);
	}

	/**
	 * IDs of content already linking to the target.
	 *
	 * @param int $target_id Target ID.
	 * @return array<int, true>
	 */
	private function sources_linking_to( int $target_id ): array {
		global $wpdb;

		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_id FROM {$table} WHERE source_type = 'content' AND target_id = %d", $target_id ) );

		return array_fill_keys( array_map( 'intval', (array) $ids ), true );
	}

	/**
	 * Number of shared tags per post.
	 *
	 * @param int $target_id Target ID.
	 * @return array<int, int>
	 */
	private function tag_overlap( int $target_id ): array {
		$tags = wp_get_post_terms( $target_id, 'post_tag', array( 'fields' => 'ids' ) );
		if ( ! is_array( $tags ) || array() === $tags ) {
			return array();
		}

		$overlap = array();
		foreach ( $tags as $tag_id ) {
			$objects = get_objects_in_term( (int) $tag_id, 'post_tag' );
			foreach ( is_array( $objects ) ? $objects : array() as $object_id ) {
				$overlap[ (int) $object_id ] = ( $overlap[ (int) $object_id ] ?? 0 ) + 1;
			}
		}

		return $overlap;
	}
}
