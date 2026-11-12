<?php
/**
 * Shared helpers for SEO adapters.
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
 * Base adapter returning empty data.
 */
abstract class AbstractAdapter implements SeoAdapter {

	/**
	 * Empty post SEO data.
	 */
	protected function empty_post(): array {
		return array(
			'focus_keyword'      => null,
			'secondary_keywords' => array(),
			'seo_title'          => null,
			'meta_description'   => null,
			'noindex'            => false,
			'canonical'          => null,
			'cornerstone'        => false,
			'primary_term_id'    => null,
		);
	}

	/**
	 * Trimmed string or null.
	 *
	 * @param mixed $value Raw value.
	 */
	protected function str( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}

	/**
	 * Positive int or null.
	 *
	 * @param mixed $value Raw value.
	 */
	protected function id( $value ): ?int {
		$value = is_numeric( $value ) ? (int) $value : 0;

		return $value > 0 ? $value : null;
	}

	/**
	 * Default: term archives are indexable.
	 *
	 * @param WP_Term $term Term.
	 */
	public function term_noindex( WP_Term $term ): bool {
		return false;
	}

	/**
	 * Default: the active SEO plugin is not supported for writing.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     SEO fields.
	 */
	public function write_post( int $post_id, array $seo ): bool {
		return false;
	}

	/**
	 * Writes post meta for the non-empty values of a field map.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $map     SEO field => meta key.
	 * @param array                $seo     SEO fields.
	 */
	protected function write_meta( int $post_id, array $map, array $seo ): bool {
		foreach ( $map as $field => $meta_key ) {
			if ( isset( $seo[ $field ] ) && '' !== (string) $seo[ $field ] ) {
				update_post_meta( $post_id, $meta_key, 'primary_term_id' === $field ? (int) $seo[ $field ] : sanitize_text_field( (string) $seo[ $field ] ) );
			}
		}

		return true;
	}
}
