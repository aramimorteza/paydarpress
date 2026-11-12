<?php
/**
 * SEO plugin adapter contract.
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
 * Read-only access to SEO plugin data.
 */
interface SeoAdapter {

	/**
	 * Adapter key: `yoast`, `rankmath`, `aioseo` or `none`.
	 */
	public function key(): string;

	/**
	 * SEO data of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{focus_keyword:?string, secondary_keywords:string[], seo_title:?string, meta_description:?string, noindex:bool, canonical:?string, cornerstone:bool, primary_term_id:?int}
	 */
	public function post( int $post_id ): array;

	/**
	 * Whether a term archive is set to noindex.
	 *
	 * @param WP_Term $term Term.
	 */
	public function term_noindex( WP_Term $term ): bool;

	/**
	 * Writes SEO fields of a post into the SEO plugin's own storage.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     title, meta_description, focus_keyword, primary_term_id (any may be missing).
	 * @return bool Whether the active SEO plugin stored the data.
	 */
	public function write_post( int $post_id, array $seo ): bool;
}
