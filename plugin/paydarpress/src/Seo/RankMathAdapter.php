<?php
/**
 * Rank Math adapter.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Seo;

use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads Rank Math post and term meta.
 */
final class RankMathAdapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'rankmath';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 */
	public function post( int $post_id ): array {
		$data = $this->empty_post();

		// Comma-separated: first is the focus keyword, the rest are secondary.
		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ) ) ) );
		if ( array() !== $keywords ) {
			$data['focus_keyword']      = array_shift( $keywords );
			$data['secondary_keywords'] = $keywords;
		}

		$data['seo_title']        = $this->str( get_post_meta( $post_id, 'rank_math_title', true ) );
		$data['meta_description'] = $this->str( get_post_meta( $post_id, 'rank_math_description', true ) );
		$data['canonical']        = $this->str( get_post_meta( $post_id, 'rank_math_canonical_url', true ) );
		$data['cornerstone']      = 'on' === (string) get_post_meta( $post_id, 'rank_math_pillar_content', true );
		$data['primary_term_id']  = $this->id( get_post_meta( $post_id, 'rank_math_primary_category', true ) );

		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $robots ) && array() !== $robots ) {
			$data['noindex'] = in_array( 'noindex', $robots, true );
		} else {
			$data['noindex'] = $this->default_noindex( 'pt_' . get_post_type( $post_id ) );
		}

		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Term $term Term.
	 */
	public function term_noindex( WP_Term $term ): bool {
		$robots = get_term_meta( $term->term_id, 'rank_math_robots', true );

		if ( is_array( $robots ) && array() !== $robots ) {
			return in_array( 'noindex', $robots, true );
		}

		return $this->default_noindex( 'tax_' . $term->taxonomy );
	}

	/**
	 * Default robots setting for a post type (`pt_post`) or taxonomy (`tax_category`).
	 *
	 * @param string $prefix Option key prefix.
	 */
	private function default_noindex( string $prefix ): bool {
		$titles = get_option( 'rank-math-options-titles', array() );

		if ( ! is_array( $titles ) || empty( $titles[ $prefix . '_custom_robots' ] ) || 'off' === $titles[ $prefix . '_custom_robots' ] ) {
			$robots = is_array( $titles ) && isset( $titles['robots_global'] ) ? (array) $titles['robots_global'] : array();
		} else {
			$robots = (array) ( $titles[ $prefix . '_robots' ] ?? array() );
		}

		return in_array( 'noindex', $robots, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     SEO fields.
	 */
	public function write_post( int $post_id, array $seo ): bool {
		return $this->write_meta(
			$post_id,
			array(
				'title'            => 'rank_math_title',
				'meta_description' => 'rank_math_description',
				'focus_keyword'    => 'rank_math_focus_keyword',
				'primary_term_id'  => 'rank_math_primary_category',
			),
			$seo
		);
	}
}
