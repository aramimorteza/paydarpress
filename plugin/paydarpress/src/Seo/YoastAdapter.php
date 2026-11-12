<?php
/**
 * Yoast SEO adapter.
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
 * Reads Yoast SEO post meta and taxonomy settings.
 */
final class YoastAdapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 */
	public function post( int $post_id ): array {
		$data = $this->empty_post();

		$data['focus_keyword']    = $this->str( get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) );
		$data['seo_title']        = $this->str( get_post_meta( $post_id, '_yoast_wpseo_title', true ) );
		$data['meta_description'] = $this->str( get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) );
		$data['canonical']        = $this->str( get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) );
		$data['cornerstone']      = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true );
		$data['primary_term_id']  = $this->id( get_post_meta( $post_id, '_yoast_wpseo_primary_category', true ) );

		// Premium: additional keyphrases as JSON [{"keyword":"..."}].
		$extra = json_decode( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true ), true );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $item ) {
				$keyword = is_array( $item ) ? $this->str( $item['keyword'] ?? null ) : null;
				if ( null !== $keyword ) {
					$data['secondary_keywords'][] = $keyword;
				}
			}
		}

		// '1' = noindex, '2' = index, '' / '0' = post type default.
		$robots = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $robots ) {
			$data['noindex'] = true;
		} elseif ( '2' !== $robots ) {
			$titles          = get_option( 'wpseo_titles', array() );
			$type            = get_post_type( $post_id );
			$data['noindex'] = is_array( $titles ) && ! empty( $titles[ 'noindex-' . $type ] );
		}

		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Term $term Term.
	 */
	public function term_noindex( WP_Term $term ): bool {
		$meta    = get_option( 'wpseo_taxonomy_meta', array() );
		$setting = is_array( $meta ) ? ( $meta[ $term->taxonomy ][ $term->term_id ]['wpseo_noindex'] ?? 'default' ) : 'default';

		if ( 'noindex' === $setting ) {
			return true;
		}
		if ( 'index' === $setting ) {
			return false;
		}

		$titles = get_option( 'wpseo_titles', array() );

		return is_array( $titles ) && ! empty( $titles[ 'noindex-tax-' . $term->taxonomy ] );
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
				'title'            => '_yoast_wpseo_title',
				'meta_description' => '_yoast_wpseo_metadesc',
				'focus_keyword'    => '_yoast_wpseo_focuskw',
				'primary_term_id'  => '_yoast_wpseo_primary_category',
			),
			$seo
		);
	}
}
