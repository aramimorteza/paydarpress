<?php
/**
 * All in One SEO adapter.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	/**
	 * {@inheritDoc} AIOSEO keeps post SEO in its own table.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     SEO fields.
	 */
	public function write_post( int $post_id, array $seo ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return false;
		}

		$now  = current_time( 'mysql' );
		$data = array( 'updated' => $now );
		if ( ! empty( $seo['title'] ) ) {
			$data['title'] = sanitize_text_field( (string) $seo['title'] );
		}
		if ( ! empty( $seo['meta_description'] ) ) {
			$data['description'] = sanitize_text_field( (string) $seo['meta_description'] );
		}
		if ( ! empty( $seo['focus_keyword'] ) ) {
			$data['keyphrases'] = (string) wp_json_encode(
				array(
					'focus'      => array(
						'keyphrase' => sanitize_text_field( (string) $seo['focus_keyword'] ),
						'score'     => 0,
						'analysis'  => new \stdClass(),
					),
					'additional' => array(),
				),
				JSON_UNESCAPED_UNICODE
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) );
		if ( $exists ) {
			return false !== $wpdb->update( $table, $data, array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return false !== $wpdb->insert( $table, $data + array( 'post_id' => $post_id, 'created' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
