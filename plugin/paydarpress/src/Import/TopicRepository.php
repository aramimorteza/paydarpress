<?php
/**
 * Suggested topics (from ContentMaster imports).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Import;

use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Topic repository.
 */
final class TopicRepository {

	public const STATUSES = array( 'new', 'accepted', 'rejected', 'written' );

	public const STATUS_LABELS = array(
		'new'      => 'جدید',
		'accepted' => 'پذیرفته',
		'rejected' => 'ردشده',
		'written'  => 'نوشته شد',
	);

	/**
	 * Inserts a topic.
	 *
	 * @param array $topic title, category_id, focus_keyword, intent, notes, import_id.
	 */
	public static function insert( array $topic ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'topics' ),
			array(
				'title'         => mb_substr( (string) $topic['title'], 0, 255 ),
				'title_norm'    => mb_substr( PersianNormalizer::for_key( (string) $topic['title'] ), 0, 255 ),
				'category_id'   => $topic['category_id'] ?? null,
				'focus_keyword' => '' === (string) ( $topic['focus_keyword'] ?? '' ) ? null : mb_substr( (string) $topic['focus_keyword'], 0, 255 ),
				'intent'        => (string) ( $topic['intent'] ?? '' ),
				'notes'         => (string) ( $topic['notes'] ?? '' ),
				'status'        => 'new',
				'import_id'     => (int) ( $topic['import_id'] ?? 0 ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Titles and statuses of all topics (for duplicate checks).
	 *
	 * @return array<int, array{id:int, title:string, status:string}>
	 */
	public static function all_titles(): array {
		global $wpdb;

		$table = Schema::table( 'topics' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, title, status FROM {$table} ORDER BY id DESC LIMIT 5000", ARRAY_A );

		return array_map(
			static fn( $r ) => array(
				'id'     => (int) $r['id'],
				'title'  => (string) $r['title'],
				'status' => (string) $r['status'],
			),
			(array) $rows
		);
	}

	/**
	 * Marks the open topic with the same (or nearly the same) title as written.
	 *
	 * @param string $title   Article title.
	 * @param int    $post_id Draft.
	 */
	public static function mark_written( string $title, int $post_id ): void {
		foreach ( self::all_titles() as $topic ) {
			if ( in_array( $topic['status'], array( 'new', 'accepted' ), true ) && ImportParser::similar_titles( $title, $topic['title'] ) ) {
				self::update( $topic['id'], array( 'status' => 'written', 'post_id' => $post_id ) );
				return;
			}
		}
	}

	/**
	 * Updates fields of a topic.
	 *
	 * @param int   $id     Topic.
	 * @param array $fields status and/or post_id.
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		$data = array( 'updated_at' => current_time( 'mysql', true ) );
		if ( isset( $fields['status'] ) && in_array( $fields['status'], self::STATUSES, true ) ) {
			$data['status'] = $fields['status'];
		}
		if ( array_key_exists( 'post_id', $fields ) ) {
			$data['post_id'] = null === $fields['post_id'] ? null : (int) $fields['post_id'];
		}

		return false !== $wpdb->update( Schema::table( 'topics' ), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes a topic.
	 *
	 * @param int $id Topic.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'topics' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Paged list.
	 *
	 * @param string $status   Status filter ('' = all).
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @return array{items: array, total: int, counts: array<string, int>}
	 */
	public static function list( string $status, int $page, int $per_page ): array {
		global $wpdb;

		$table = Schema::table( 'topics' );
		$where = in_array( $status, self::STATUSES, true ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY CASE status WHEN 'accepted' THEN 0 WHEN 'new' THEN 1 WHEN 'written' THEN 2 ELSE 3 END, id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable

		$by_status = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $counts as $c ) {
			$by_status[ $c['status'] ] = (int) $c['n'];
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$category = null === $row['category_id'] ? null : get_term( (int) $row['category_id'], 'category' );
			$post_id  = null === $row['post_id'] ? null : (int) $row['post_id'];
			$items[]  = array(
				'id'            => (int) $row['id'],
				'title'         => $row['title'],
				'category'      => $category instanceof \WP_Term ? html_entity_decode( $category->name, ENT_QUOTES, 'UTF-8' ) : '',
				'focus_keyword' => (string) $row['focus_keyword'],
				'intent'        => $row['intent'],
				'notes'         => (string) $row['notes'],
				'status'        => $row['status'],
				'status_label'  => self::STATUS_LABELS[ $row['status'] ] ?? $row['status'],
				'post'          => null === $post_id || ! get_post( $post_id ) ? null : array(
					'id'       => $post_id,
					'status'   => (string) get_post_status( $post_id ),
					'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
				),
				'created_at'    => gmdate( 'c', (int) strtotime( $row['created_at'] . ' UTC' ) ),
			);
		}

		return array(
			'items'  => $items,
			'total'  => $total,
			'counts' => $by_status,
		);
	}
}
