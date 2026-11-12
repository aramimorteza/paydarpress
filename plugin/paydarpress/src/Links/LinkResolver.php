<?php
/**
 * Resolves link targets and recounts inbound links.
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
 * Turns `pending` internal links into ok / unpublished / redirect / missing / unknown,
 * then updates internal_in and menu_in in the inventory. See spec sections 4.2 and 5.1.
 */
final class LinkResolver {

	/** @var UrlNormalizer */
	private $urls;

	/** @var array<string, array{0:int, 1:string}>|null URL key → [object_id, status]. */
	private $content_map = null;

	/** @var array<string, true>|null */
	private $archive_keys = null;

	/** @var array<string, true>|null */
	private $redirect_keys = null;

	/** @var array<string, int>|null Old slug → post ID (WordPress redirects old slugs). */
	private $old_slugs = null;

	/** @var string[]|null Archive path prefixes such as /category/. */
	private $archive_prefixes = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->urls = new UrlNormalizer( home_url( '/' ) );
	}

	/**
	 * Resolves up to $limit pending links.
	 *
	 * @param int $limit Max links.
	 * @return int Number of links resolved (0 when none are pending).
	 */
	public function resolve_pending( int $limit = 500 ): int {
		global $wpdb;

		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, target_url FROM {$table} WHERE target_state = 'pending' LIMIT %d", $limit ), ARRAY_A );

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			list( $target_id, $state ) = $this->resolve( (string) $row['target_url'] );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'target_id'    => $target_id,
					'target_state' => $state,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		return count( $rows );
	}

	/**
	 * Resolves one absolute internal URL.
	 *
	 * @param string $url Absolute URL.
	 * @return array{0:?int, 1:string} [target_id, state]
	 */
	public function resolve( string $url ): array {
		$key = $this->urls->key( $url );
		$map = $this->content_map();

		if ( isset( $map[ $key ] ) ) {
			list( $id, $status ) = $map[ $key ];
			return array( $id, 'publish' === $status ? 'ok' : 'unpublished' );
		}

		if ( isset( $this->redirect_keys()[ $key ] ) ) {
			return array( null, 'redirect' );
		}

		if ( isset( $this->archive_keys()[ $key ] ) || $this->urls->is_special_route( $url ) ) {
			return array( null, 'ok' );
		}

		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			$status = (string) get_post_status( $post_id );
			return array( $post_id, 'publish' === $status ? 'ok' : 'unpublished' );
		}

		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		// WordPress redirects a post's old slugs to its current URL.
		$slug = mb_strtolower( basename( rtrim( $path, '/' ) ), 'UTF-8' );
		if ( '' !== $slug && isset( $this->old_slugs()[ $slug ] ) ) {
			return array( $this->old_slugs()[ $slug ], 'redirect' );
		}

		foreach ( $this->archive_prefixes() as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return array( null, 'unknown' );
			}
		}

		return array( null, 'missing' );
	}

	/**
	 * Recomputes internal_in (distinct published content sources) and menu_in for all inventory rows.
	 */
	public function recount_inbound(): void {
		global $wpdb;

		$inventory = Schema::table( 'inventory' );
		$links     = Schema::table( 'links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$content_counts = $wpdb->get_results(
			"SELECT l.target_id AS id, COUNT(DISTINCT l.source_id) AS n
			FROM {$links} l
			INNER JOIN {$inventory} s ON s.object_id = l.source_id
			WHERE l.source_type = 'content' AND l.link_type = 'internal' AND l.target_id IS NOT NULL
				AND l.target_id <> l.source_id AND s.status = 'publish'
			GROUP BY l.target_id",
			ARRAY_A
		);

		$menu_counts = $wpdb->get_results(
			"SELECT target_id AS id, COUNT(*) AS n FROM {$links}
			WHERE source_type = 'menu' AND target_id IS NOT NULL GROUP BY target_id",
			ARRAY_A
		);

		$wpdb->query( "UPDATE {$inventory} SET internal_in = 0, menu_in = 0" );
		// phpcs:enable

		$this->apply_counts( 'internal_in', (array) $content_counts );
		$this->apply_counts( 'menu_in', (array) $menu_counts );
	}

	/**
	 * Marks links pointing to a post as pending (after its permalink or status changed).
	 *
	 * @param int $post_id Target post ID.
	 */
	public static function invalidate_target( int $post_id ): void {
		global $wpdb;

		$table = Schema::table( 'links' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET target_state = 'pending', target_id = NULL WHERE target_id = %d", $post_id ) );
	}

	/**
	 * Writes counts in chunks.
	 *
	 * @param string $column Column.
	 * @param array  $counts Rows with id and n.
	 */
	private function apply_counts( string $column, array $counts ): void {
		global $wpdb;

		$table = Schema::table( 'inventory' );

		foreach ( array_chunk( $counts, 200 ) as $chunk ) {
			$cases = '';
			$ids   = array();
			foreach ( $chunk as $row ) {
				$id     = (int) $row['id'];
				$cases .= sprintf( ' WHEN %d THEN %d', $id, min( 65535, (int) $row['n'] ) );
				$ids[]  = $id;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "UPDATE {$table} SET {$column} = CASE object_id{$cases} ELSE {$column} END WHERE object_id IN (" . implode( ',', $ids ) . ')' );
		}
	}

	/**
	 * URL key → [object_id, status] for all scanned content.
	 *
	 * @return array<string, array{0:int, 1:string}>
	 */
	private function content_map(): array {
		if ( null !== $this->content_map ) {
			return $this->content_map;
		}

		global $wpdb;

		$table = Schema::table( 'inventory' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT object_id, url, status FROM {$table}", ARRAY_A );

		$this->content_map = array();
		foreach ( (array) $rows as $row ) {
			$id     = (int) $row['object_id'];
			$status = (string) $row['status'];

			$this->content_map[ $this->urls->key( (string) $row['url'] ) ] = array( $id, $status );
			// Plain links such as /?p=123 and /?page_id=123.
			$this->content_map[ $this->urls->key( home_url( '/?p=' . $id ) ) ]       = array( $id, $status );
			$this->content_map[ $this->urls->key( home_url( '/?page_id=' . $id ) ) ] = array( $id, $status );
		}

		return $this->content_map;
	}

	/**
	 * Keys of term archives and author archives.
	 *
	 * @return array<string, true>
	 */
	private function archive_keys(): array {
		if ( null !== $this->archive_keys ) {
			return $this->archive_keys;
		}

		$this->archive_keys = array();

		$taxonomies = get_taxonomies( array( 'public' => true ) );
		$terms      = get_terms(
			array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => false,
				'number'     => 5000,
			)
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$this->archive_keys[ $this->urls->key( $link ) ] = true;
				}
			}
		}

		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $post_type ) {
			$link = get_post_type_archive_link( $post_type );
			if ( is_string( $link ) ) {
				$this->archive_keys[ $this->urls->key( $link ) ] = true;
			}
		}

		return $this->archive_keys;
	}

	/**
	 * Decoded old slugs from `_wp_old_slug` post meta.
	 *
	 * @return array<string, int>
	 */
	private function old_slugs(): array {
		if ( null !== $this->old_slugs ) {
			return $this->old_slugs;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug'", ARRAY_A );

		$this->old_slugs = array();
		foreach ( (array) $rows as $row ) {
			$this->old_slugs[ mb_strtolower( rawurldecode( (string) $row['meta_value'] ), 'UTF-8' ) ] = (int) $row['post_id'];
		}

		return $this->old_slugs;
	}

	/**
	 * Keys of redirect sources.
	 *
	 * @return array<string, true>
	 */
	private function redirect_keys(): array {
		if ( null === $this->redirect_keys ) {
			$this->redirect_keys = RedirectSources::keys( $this->urls, home_url( '/' ) );
		}

		return $this->redirect_keys;
	}

	/**
	 * Path prefixes of taxonomy and author archives (e.g. /category/, /tag/, /author/).
	 *
	 * @return string[]
	 */
	private function archive_prefixes(): array {
		if ( null !== $this->archive_prefixes ) {
			return $this->archive_prefixes;
		}

		global $wp_rewrite;

		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$prefixes  = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$slug = is_array( $taxonomy->rewrite ) && ! empty( $taxonomy->rewrite['slug'] ) ? $taxonomy->rewrite['slug'] : '';
			if ( '' !== $slug ) {
				$prefixes[] = $home_path . '/' . trim( $slug, '/' ) . '/';
			}
		}

		$author_base = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->author_base ) ? $wp_rewrite->author_base : 'author';
		$prefixes[]  = $home_path . '/' . $author_base . '/';

		$this->archive_prefixes = $prefixes;

		return $this->archive_prefixes;
	}
}
