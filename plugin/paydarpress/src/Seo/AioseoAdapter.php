<?php
/**
 * All in One SEO adapter.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Seo;

/**
 * Reads AIOSEO data from its own `aioseo_posts` table.
 */
final class AioseoAdapter extends AbstractAdapter {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'aioseo';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post ID.
	 */
	public function post( int $post_id ): array {
		global $wpdb;

		$data  = $this->empty_post();
		$table = $wpdb->prefix . 'aioseo_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return $data;
		}

		$keyphrases = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
		if ( is_array( $keyphrases ) ) {
			$data['focus_keyword'] = $this->str( $keyphrases['focus']['keyphrase'] ?? null );
			foreach ( (array) ( $keyphrases['additional'] ?? array() ) as $item ) {
				$keyword = is_array( $item ) ? $this->str( $item['keyphrase'] ?? null ) : null;
				if ( null !== $keyword ) {
					$data['secondary_keywords'][] = $keyword;
				}
			}
		}

		$data['seo_title']        = $this->str( $row['title'] ?? null );
		$data['meta_description'] = $this->str( $row['description'] ?? null );
		$data['canonical']        = $this->str( $row['canonical_url'] ?? null );
		$data['cornerstone']      = ! empty( $row['pillar_content'] );
		$data['noindex']          = empty( $row['robots_default'] ) && ! empty( $row['robots_noindex'] );

		return $data;
	}
}
