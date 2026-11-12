<?php
/**
 * Adapter used when no supported SEO plugin is active.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Seo;

/**
 * Returns empty SEO data.
 */
final class NullAdapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 */
	public function post( int $post_id ): array {
		return $this->empty_post();
	}
}
