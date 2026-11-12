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
}
