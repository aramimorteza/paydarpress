<?php
/**
 * Content clusters (articles only): key page ← pillar article ← cluster articles.
 *
 * A cluster has a main keyword, a category, a pillar post and member posts, each covering one
 * subtopic. Planned subtopics are topics with `cluster_id`. Members link to the pillar (and 1–2
 * siblings), the pillar links to every member and to the key page; missing links go to the
 * link queue for the owner's approval.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Import\LinkSuggestionRepository;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use RuntimeException;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Only posts of type «post» can be pillars or members.
 */
final class Clusters {

	public const ACTION_LINKS = 'paydarpress_cluster_links';

	/**
	 * Hooks: when a member or pillar is published, its missing cluster links are queued.
	 */
	public function register(): void {
		add_action( self::ACTION_LINKS, array( self::class, 'queue_links' ) );
		add_action(
			'transition_post_status',
			static function ( $new, $old, $post ) {
				if ( 'publish' === $new && 'publish' !== $old && $post instanceof WP_Post && 'post' === $post->post_type ) {
					$cluster = self::of_post( $post->ID );
					if ( $cluster ) {
						Queue::schedule( self::ACTION_LINKS, array( (int) $cluster['id'] ), 30 );
					}
				}
			},
			10,
			3
		);
	}

	/**
	 * Creates or updates a cluster.
	 *
	 * @param array $data name, main_keyword, category_id, pillar_id, key_page_id, notes; id to update.
	 * @return int Cluster id.
	 * @throws RuntimeException On invalid input.
	 */
	public static function save( array $data ): int {
		global $wpdb;
		$table = Schema::table( 'clusters' );
		$name  = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 200 );
		$id    = (int) ( $data['id'] ?? 0 );
		$row   = $id > 0 ? self::row( $id ) : null;
		if ( $id > 0 && ! $row ) {
			throw new RuntimeException( 'خوشه پیدا نشد.' );
		}
		if ( '' === $name && ! $row ) {
			throw new RuntimeException( 'نام خوشه را بنویسید.' );
		}
		$fields = array( 'updated_at' => current_time( 'mysql', true ) );
		if ( '' !== $name ) {
			$fields['name'] = $name;
		}
		if ( array_key_exists( 'main_keyword', $data ) ) {
			$fields['main_keyword'] = mb_substr( sanitize_text_field( (string) $data['main_keyword'] ), 0, 255 );
		}
		if ( array_key_exists( 'category_id', $data ) ) {
			$cat                   = (int) $data['category_id'];
			$fields['category_id'] = $cat > 0 && term_exists( $cat, 'category' ) ? $cat : null;
		}
		if ( array_key_exists( 'key_page_id', $data ) ) {
			$kp                    = (int) $data['key_page_id'];
			$fields['key_page_id'] = $kp > 0 && get_post( $kp ) ? $kp : null;
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$fields['notes'] = mb_substr( sanitize_textarea_field( (string) $data['notes'] ), 0, 1000 );
		}
		if ( $row ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$fields['created_at'] = $fields['updated_at'];
			$wpdb->insert( $table, $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->insert_id;
			Activity::log( 'cluster.created', sprintf( 'خوشه «%s» ساخته شد.', $name ) );
		}
		if ( array_key_exists( 'pillar_id', $data ) ) {
			self::set_pillar( $id, (int) $data['pillar_id'] );
		}
		return $id;
	}

	/**
	 * Deletes a cluster (posts stay; planned topics lose the link to it).
	 *
	 * @param int $id Cluster.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'clusters' ), array( 'id' => $id ) );
		$wpdb->delete( Schema::table( 'cluster_posts' ), array( 'cluster_id' => $id ) );
		$wpdb->update( Schema::table( 'topics' ), array( 'cluster_id' => null ), array( 'cluster_id' => $id ) );
		// phpcs:enable
	}

	/**
	 * Sets (or clears with 0) the pillar; the pillar is also a member.
	 *
	 * @param int $id      Cluster.
	 * @param int $post_id Post.
	 * @throws RuntimeException When the post cannot be a pillar.
	 */
	public static function set_pillar( int $id, int $post_id ): void {
		global $wpdb;
		if ( $post_id > 0 && 'post' !== get_post_type( $post_id ) ) {
			throw new RuntimeException( 'ستون خوشه باید یک مقاله (نوشته) باشد.' );
		}
		$wpdb->update( Schema::table( 'clusters' ), array( 'pillar_id' => $post_id > 0 ? $post_id : null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = $post_id > 0 ? self::of_post( $post_id ) : null;
		if ( $post_id > 0 && ( ! $current || (int) $current['id'] !== $id ) ) {
			self::add_post( $id, $post_id, 'راهنمای جامع' );
		}
	}

	/**
	 * Adds a post to a cluster (a post belongs to one cluster; it moves if needed).
	 *
	 * @param int    $id       Cluster.
	 * @param int    $post_id  Post.
	 * @param string $subtopic Subtopic it covers.
	 * @throws RuntimeException When the post is not an article.
	 */
	public static function add_post( int $id, int $post_id, string $subtopic = '' ): void {
		global $wpdb;
		if ( 'post' !== get_post_type( $post_id ) ) {
			throw new RuntimeException( 'فقط مقاله‌ها (نوشته‌ها) عضو خوشه می‌شوند.' );
		}
		$table = Schema::table( 'cluster_posts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'cluster_id' => $id,
				'post_id'    => $post_id,
				'subtopic'   => mb_substr( sanitize_text_field( $subtopic ), 0, 255 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		Queue::schedule( self::ACTION_LINKS, array( $id ), 30 );
		$c = self::get( $id );
		ChangeLog::record( 'cluster', $post_id, 'عضو خوشه «' . ( $c['name'] ?? '' ) . '»' . ( '' !== $subtopic ? ' (زیرموضوع: ' . $subtopic . ')' : '' ) );
	}

	/**
	 * Removes a post from its cluster (and as pillar).
	 *
	 * @param int $post_id Post.
	 */
	public static function remove_post( int $post_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'cluster_posts' ), array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Schema::table( 'clusters' ), array( 'pillar_id' => null ), array( 'pillar_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Cluster of a post.
	 *
	 * @param int $post_id Post.
	 */
	public static function of_post( int $post_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT cluster_id FROM ' . Schema::table( 'cluster_posts' ) . ' WHERE post_id = %d', $post_id ) );
		return $cid > 0 ? self::row( $cid ) : null;
	}

	/**
	 * All clusters with their state.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'clusters' ) . ' ORDER BY name ASC', ARRAY_A );
		return array_map( array( self::class, 'view' ), (array) $rows );
	}

	/**
	 * One cluster with members, planned subtopics, link gaps and completion.
	 *
	 * @param int $id Cluster.
	 */
	public static function get( int $id ): ?array {
		$row = self::row( $id );
		return $row ? self::view( $row ) : null;
	}

	/**
	 * Cluster view.
	 *
	 * @param array $row Row.
	 */
	private static function view( array $row ): array {
		global $wpdb;
		$id     = (int) $row['id'];
		$pillar = (int) $row['pillar_id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts   = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, subtopic FROM ' . Schema::table( 'cluster_posts' ) . ' WHERE cluster_id = %d ORDER BY id ASC', $id ), ARRAY_A );
		$links   = self::links_between( array_map( static fn( $p ) => (int) $p['post_id'], (array) $posts ) );
		$members = array();
		$pub     = 0;
		foreach ( (array) $posts as $p ) {
			$pid    = (int) $p['post_id'];
			$status = (string) get_post_status( $pid );
			if ( '' === $status || 'trash' === $status ) {
				continue;
			}
			if ( 'publish' === $status ) {
				++$pub;
			}
			$members[] = array(
				'id'             => $pid,
				'title'          => html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ),
				'url'            => (string) get_permalink( $pid ),
				'edit_url'       => (string) get_edit_post_link( $pid, 'raw' ),
				'status'         => $status,
				'subtopic'       => (string) $p['subtopic'],
				'pillar'         => $pid === $pillar,
				'to_pillar'      => $pid === $pillar || ( $pillar > 0 && isset( $links[ $pid ][ $pillar ] ) ),
				'from_pillar'    => $pid === $pillar || ( $pillar > 0 && isset( $links[ $pillar ][ $pid ] ) ),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$planned = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, focus_keyword, status FROM ' . Schema::table( 'topics' ) . " WHERE cluster_id = %d AND status IN ('new','accepted') ORDER BY id ASC", $id ), ARRAY_A );
		$cat     = $row['category_id'] ? get_term( (int) $row['category_id'], 'category' ) : null;
		$total   = count( $members ) + count( (array) $planned );
		$missing = count( array_filter( $members, static fn( $m ) => 'publish' === $m['status'] && ( ! $m['to_pillar'] || ! $m['from_pillar'] ) ) );
		return array(
			'id'           => $id,
			'name'         => $row['name'],
			'main_keyword' => $row['main_keyword'],
			'category_id'  => $row['category_id'] ? (int) $row['category_id'] : null,
			'category'     => $cat instanceof \WP_Term ? html_entity_decode( $cat->name, ENT_QUOTES, 'UTF-8' ) : '',
			'pillar_id'    => $pillar ?: null,
			'pillar_ok'    => $pillar > 0 && 'publish' === get_post_status( $pillar ),
			'key_page_id'  => $row['key_page_id'] ? (int) $row['key_page_id'] : null,
			'key_page'     => $row['key_page_id'] ? html_entity_decode( get_the_title( (int) $row['key_page_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
			'notes'        => (string) $row['notes'],
			'members'      => $members,
			'planned'      => array_map( static fn( $t ) => array( 'id' => (int) $t['id'], 'title' => $t['title'], 'focus_keyword' => (string) $t['focus_keyword'], 'status' => $t['status'] ), (array) $planned ),
			'published'    => $pub,
			'total'        => $total,
			'completion'   => $total > 0 ? (int) round( $pub * 100 / $total ) : 0,
			'link_gaps'    => $missing,
		);
	}

	/**
	 * Published internal links among a set of posts: [source][target] = true.
	 *
	 * @param int[] $ids Posts.
	 */
	private static function links_between( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT DISTINCT source_id, target_id FROM ' . Schema::table( 'links' ) . " WHERE source_type = 'content' AND source_id IN ({$in}) AND target_id IN ({$in})", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['source_id'] ][ (int) $r['target_id'] ] = true;
		}
		return $out;
	}

	/**
	 * Queues the missing cluster links for approval: member → pillar and pillar → member.
	 *
	 * @param int $id Cluster.
	 * @return int Suggestions queued.
	 */
	public static function queue_links( $id ): int {
		$c = self::get( (int) $id );
		if ( ! $c || ! $c['pillar_ok'] ) {
			return 0;
		}
		$n      = 0;
		$pillar = (int) $c['pillar_id'];
		foreach ( $c['members'] as $m ) {
			if ( 'publish' !== $m['status'] || $m['pillar'] ) {
				continue;
			}
			if ( ! $m['to_pillar'] ) {
				$n += LinkSuggestionRepository::insert( $m['id'], $pillar, self::anchor( $pillar, $c['main_keyword'] ), '', 'cluster', 0 ) > 0 ? 1 : 0;
			}
			if ( ! $m['from_pillar'] ) {
				$n += LinkSuggestionRepository::insert( $pillar, $m['id'], self::anchor( $m['id'], $m['subtopic'] ), '', 'cluster', 0 ) > 0 ? 1 : 0;
			}
		}
		return $n;
	}

	/**
	 * Anchor for a link to a post: its focus keyword, else the given text, else its title.
	 *
	 * @param int    $post_id  Target.
	 * @param string $fallback Fallback.
	 */
	private static function anchor( int $post_id, string $fallback ): string {
		$seo = \PaydarWP\PaydarPress\Seo\SeoDetector::adapter()->post( $post_id );
		$kw  = trim( (string) ( $seo['focus_keyword'] ?? '' ) );
		if ( '' !== $kw ) {
			return $kw;
		}
		return '' !== trim( $fallback ) ? trim( $fallback ) : html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Cluster section for prompts.
	 *
	 * @param int $id       Cluster.
	 * @param int $exclude  Post being written or rewritten.
	 * @param int $topic_id Planned topic being written.
	 */
	public static function prompt_text( int $id, int $exclude = 0, int $topic_id = 0 ): string {
		$c = self::get( $id );
		if ( ! $c ) {
			return '';
		}
		$lines = array( "## CONTENT CLUSTER «{$c['name']}»" . ( '' !== $c['main_keyword'] ? " — main keyword: {$c['main_keyword']}" : '' ) );
		if ( $c['pillar_ok'] && (int) $c['pillar_id'] !== $exclude ) {
			$lines[] = 'PILLAR (the cluster\'s complete guide): ' . get_the_title( (int) $c['pillar_id'] ) . ' | ' . get_permalink( (int) $c['pillar_id'] );
		}
		if ( $c['key_page_id'] ) {
			$lines[] = 'KEY PAGE the pillar supports: ' . $c['key_page'] . ' | ' . get_permalink( (int) $c['key_page_id'] );
		}
		$pub = array_filter( $c['members'], static fn( $m ) => 'publish' === $m['status'] && ! $m['pillar'] && $m['id'] !== $exclude );
		if ( $pub ) {
			$lines[] = 'PUBLISHED SIBLINGS (one subtopic each — do not repeat them, link 1–2 where relevant):';
			foreach ( $pub as $m ) {
				$lines[] = '- ' . $m['title'] . ' | ' . $m['url'] . ( '' !== $m['subtopic'] ? ' | subtopic: ' . $m['subtopic'] : '' );
			}
		}
		$planned = array_filter( $c['planned'], static fn( $t ) => $t['id'] !== $topic_id );
		if ( $planned ) {
			$lines[] = 'PLANNED SIBLINGS (will be written later — leave their subtopics to them): ' . implode( '؛ ', array_map( static fn( $t ) => $t['title'], $planned ) );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Clusters list for the topics prompt (where new topics are most needed).
	 */
	public static function overview_text(): string {
		$lines = array();
		foreach ( self::all() as $c ) {
			$lines[] = '- ' . $c['name'] . ( '' !== $c['main_keyword'] ? ' (main keyword: ' . $c['main_keyword'] . ')' : '' ) . ': ' . $c['published'] . ' published, ' . count( $c['planned'] ) . ' planned' . ( $c['pillar_ok'] ? '' : ', NO PILLAR yet' ) . ( $c['members'] ? ' — covered: ' . implode( '، ', array_slice( array_map( static fn( $m ) => '' !== $m['subtopic'] ? $m['subtopic'] : $m['title'], $c['members'] ), 0, 15 ) ) : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Cluster suggestions from the existing site: one per main keyword of the profile with at
	 * least three matching articles not yet in a cluster.
	 *
	 * @return array[] name, main_keyword, category_id, pillar_id, posts[].
	 */
	public static function suggestions(): array {
		global $wpdb;
		$keywords = array_filter( array_map( 'trim', preg_split( '/[\n،,]+/u', (string) ProfileRepository::value( 'main_keywords' ) ) ) );
		if ( ! $keywords ) {
			return array();
		}
		$inv = Schema::table( 'inventory' );
		$cp  = Schema::table( 'cluster_posts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT i.object_id, i.title_norm, i.focus_keyword_norm, i.primary_term_id, i.word_count, i.internal_in FROM {$inv} i LEFT JOIN {$cp} c ON c.post_id = i.object_id WHERE i.object_type = 'post' AND i.status = 'publish' AND c.id IS NULL LIMIT 20000", ARRAY_A );
		$taken = array();
		$out   = array();
		foreach ( $keywords as $kw ) {
			$tokens = \PaydarWP\PaydarPress\Gsc\NotFound::tokens( $kw );
			$tokens = array_values( array_filter( $tokens, static fn( $t ) => mb_strlen( $t ) > 2 ) );
			if ( ! $tokens ) {
				continue;
			}
			$need  = max( 1, (int) ceil( count( $tokens ) * 0.6 ) );
			$posts = array();
			foreach ( (array) $rows as $r ) {
				$id = (int) $r['object_id'];
				if ( isset( $taken[ $id ] ) ) {
					continue;
				}
				$hay = ' ' . $r['title_norm'] . ' ' . $r['focus_keyword_norm'] . ' ';
				$hit = 0;
				foreach ( $tokens as $t ) {
					$hit += false !== mb_strpos( $hay, $t ) ? 1 : 0;
				}
				if ( $hit >= $need ) {
					$posts[ $id ] = $r;
				}
			}
			if ( count( $posts ) < 3 ) {
				continue;
			}
			// Pillar candidate: the most linked-to, then the longest.
			uasort( $posts, static fn( $a, $b ) => array( (int) $b['internal_in'], (int) $b['word_count'] ) <=> array( (int) $a['internal_in'], (int) $a['word_count'] ) );
			$ids  = array_map( 'intval', array_keys( $posts ) );
			$cats = array_count_values( array_filter( array_map( static fn( $p ) => (int) $p['primary_term_id'], $posts ) ) );
			arsort( $cats );
			foreach ( $ids as $id ) {
				$taken[ $id ] = true;
			}
			$out[] = array(
				'name'         => $kw,
				'main_keyword' => $kw,
				'category_id'  => $cats ? (int) array_key_first( $cats ) : null,
				'pillar_id'    => $ids[0],
				'posts'        => array_map( static fn( $id ) => array( 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ), array_slice( $ids, 0, 40 ) ),
				'count'        => count( $ids ),
			);
		}
		return $out;
	}

	/**
	 * Creates a cluster from a suggestion.
	 *
	 * @param array $s Suggestion (name, main_keyword, category_id, pillar_id, post ids).
	 * @return int Cluster id.
	 */
	public static function accept( array $s ): int {
		$id = self::save(
			array(
				'name'         => (string) $s['name'],
				'main_keyword' => (string) ( $s['main_keyword'] ?? '' ),
				'category_id'  => (int) ( $s['category_id'] ?? 0 ),
			)
		);
		foreach ( array_map( 'intval', (array) ( $s['post_ids'] ?? array() ) ) as $pid ) {
			if ( 'post' === get_post_type( $pid ) ) {
				self::add_post( $id, $pid );
			}
		}
		if ( ! empty( $s['pillar_id'] ) ) {
			self::set_pillar( $id, (int) $s['pillar_id'] );
		}
		return $id;
	}

	/**
	 * Cluster by name (case/space insensitive).
	 *
	 * @param string $name Name.
	 */
	public static function find_by_name( string $name ): ?array {
		$key = PersianNormalizer::for_key( $name );
		foreach ( self::all() as $c ) {
			if ( PersianNormalizer::for_key( $c['name'] ) === $key || ( '' !== $c['main_keyword'] && PersianNormalizer::for_key( $c['main_keyword'] ) === $key ) ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id Cluster.
	 */
	private static function row( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'clusters' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
