<?php
/**
 * Internal link approval queue: review, apply (insert into the source post), undo, and
 * PaydarPress's own suggestions for orphan content.
 *
 * Statuses: pending → approved (waiting for the target to be published) → applied;
 * pending → rejected; any apply attempt that cannot be done → failed (with a note).
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Links;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Database\Schema;
use PaydarWP\PaydarPress\Queue\Queue;
use PaydarWP\PaydarPress\Text\PersianNormalizer;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link queue.
 */
final class LinkQueue {

	public const STATUSES = array( 'pending', 'approved', 'applied', 'rejected', 'failed' );

	public const ACTION_TARGET_PUBLISHED = 'paydarpress/links/target_published';

	/** Orphan targets processed per "find suggestions" run. */
	private const GENERATE_TARGETS = 100;

	/**
	 * Hooks: approved suggestions are applied once their target gets published.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( self::ACTION_TARGET_PUBLISHED, array( $this, 'apply_for_target' ), 10, 1 );
	}

	/**
	 * Queues the apply job when a post is published (never runs inline in the save request).
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public function on_transition( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || ! $post instanceof WP_Post ) {
			return;
		}
		if ( $this->count_where( 'target_id = %d AND status = %s', array( (int) $post->ID, 'approved' ) ) > 0 ) {
			Queue::schedule( self::ACTION_TARGET_PUBLISHED, array( (int) $post->ID ), 10 );
		}
	}

	/**
	 * Applies approved suggestions pointing to a newly published target.
	 *
	 * @param int $target_id Target.
	 */
	public function apply_for_target( $target_id ): void {
		global $wpdb;

		$table = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE target_id = %d AND status = 'approved' LIMIT 50", (int) $target_id ) );
		foreach ( (array) $ids as $id ) {
			$this->apply( (int) $id, true );
		}
	}

	/**
	 * Approves a suggestion and inserts the link (or waits until the target is published).
	 *
	 * @param int  $id     Suggestion.
	 * @param bool $system Called from the queue (no user).
	 * @return array Updated item.
	 */
	public function apply( int $id, bool $system = false ): array {
		$row = $this->row( $id );
		if ( null === $row || ! in_array( $row['status'], array( 'pending', 'approved', 'failed' ), true ) ) {
			return $this->item( $row );
		}

		$source = get_post( (int) $row['source_id'] );
		$target = get_post( (int) $row['target_id'] );

		if ( ! $source || 'trash' === $source->post_status || ! $target || 'trash' === $target->post_status ) {
			return $this->set( $id, 'failed', 'نوشته مبدأ یا مقصد حذف شده است.' );
		}
		if ( ! $system && ! current_user_can( 'edit_post', $source->ID ) ) {
			return $this->set( $id, 'failed', 'اجازه ویرایش نوشته مبدأ را ندارید.' );
		}
		if ( 'publish' !== $target->post_status ) {
			return $this->set( $id, 'approved', 'تأیید شد؛ پس از انتشار «' . $this->title( $target->ID ) . '» خودکار درج می‌شود.' );
		}
		if ( 'builder' === get_post_meta( $source->ID, '_elementor_edit_mode', true ) ) {
			return $this->set( $id, 'failed', 'این نوشته با المنتور ساخته شده و درج خودکار ممکن نیست. متن «' . $row['anchor'] . '» را در المنتور پیدا کنید و به ' . rawurldecode( (string) get_permalink( $target->ID ) ) . ' لینک دهید، سپس این مورد را «انجام شد» بزنید.' );
		}
		if ( $this->load_post_lock() ) {
			$lock = wp_check_post_lock( $source->ID );
			if ( $lock && ( $system || (int) $lock !== get_current_user_id() ) ) {
				$user = get_userdata( (int) $lock );
				return $this->set( $id, 'failed', 'این نوشته هم‌اکنون در حال ویرایش' . ( $user ? ' توسط ' . $user->display_name : '' ) . ' است؛ پس از بسته شدن ویرایشگر دوباره تلاش کنید.' );
			}
		}
		if ( $this->links_to( (string) $source->post_content, $target->ID ) ) {
			return $this->set( $id, 'applied', 'این نوشته از قبل به مقصد لینک داده بود؛ تغییری لازم نبود.' );
		}

		// At most two different key pages per article: more looks like advertising to readers and Google.
		$key_pages = KeyPageLinks::pages();
		if ( isset( $key_pages[ $target->ID ] ) ) {
			$already = array_diff( KeyPageLinks::linked_in( (string) $source->post_content, array_keys( $key_pages ) ), array( $target->ID ) );
			if ( count( $already ) >= KeyPageLinks::MAX_PER_ARTICLE ) {
				return $this->set( $id, 'failed', sprintf( 'این نوشته از قبل به %s صفحه کلیدی (%s) لینک داده است؛ سقف ۲ صفحه کلیدی در هر مقاله است. اگر این لینک مهم‌تر است، یکی از آن لینک‌ها را بردارید و دوباره «درج» بزنید.', PersianNormalizer::fa_number( count( $already ) ), implode( '، ', array_map( fn( $k ) => $this->title( (int) $k ), $already ) ) ) );
			}
		}

		// Content policy: limits on how often and how densely old articles receive new links.
		$budget = $this->budget_block( $source, 'paydarpress' === $row['origin'] );
		if ( null !== $budget ) {
			return $this->set( $id, 'failed', $budget );
		}

		$url    = esc_url( (string) get_permalink( $target->ID ) );
		$result = LinkInserter::insert( (string) $source->post_content, (string) $row['anchor'], $url );
		if ( null === $result ) {
			// The suggested anchor is not in the text (assistants often propose wording):
			// fall back to a phrase of the target's title or keyword that the source does contain.
			$alt = $this->find_anchor( $source->ID, $target->ID );
			if ( null !== $alt ) {
				$result = LinkInserter::insert( (string) $source->post_content, $alt, $url );
			}
		}
		if ( null === $result ) {
			return $this->set( $id, 'failed', 'متن «' . $row['anchor'] . '» در متن نوشته مبدأ پیدا نشد (یا فقط داخل عنوان، لینک یا کد آمده است). متن لنگر را به عبارتی که در نوشته هست تغییر دهید.' );
		}

		// Background runs have no user; WordPress would then pass the whole post through kses
		// and could strip embeds the author was allowed to use. Only our link changes here.
		$kses = $system && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}

		// wp_update_post stores a revision, so the change can also be undone from WordPress.
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $source->ID,
					'post_content' => $result['html'],
				)
			),
			true
		);
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $updated ) ) {
			return $this->set( $id, 'failed', 'ذخیره نوشته ناموفق بود: ' . $updated->get_error_message() );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'link_suggestions' ),
			array(
				'inserted'     => $result['inserted'],
				'matched_text' => mb_substr( $result['text'], 0, 255 ),
				'applied_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		Activity::log( 'links.applied', sprintf( 'لینک «%s» از «%s» به «%s» درج شد.', $result['text'], $this->title( $source->ID ), $this->title( $target->ID ) ), 'post', $source->ID );

		return $this->set( $id, 'applied', null );
	}

	/**
	 * Removes a link PaydarPress inserted.
	 *
	 * @param int $id Suggestion.
	 */
	public function undo( int $id ): array {
		$row = $this->row( $id );
		if ( null === $row || 'applied' !== $row['status'] || empty( $row['inserted'] ) ) {
			return $this->item( $row );
		}

		$source = get_post( (int) $row['source_id'] );
		if ( ! $source || ! current_user_can( 'edit_post', $source->ID ) ) {
			return $this->item( $row );
		}

		$html = LinkInserter::remove( (string) $source->post_content, (string) $row['inserted'], (string) $row['matched_text'] );
		if ( null === $html ) {
			return $this->set( $id, 'applied', 'لینک در نوشته تغییر کرده و بازگرداندن خودکار ممکن نیست؛ در صورت نیاز آن را دستی حذف کنید.' );
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $source->ID,
					'post_content' => $html,
				)
			)
		);
		Activity::log( 'links.undone', sprintf( 'لینک «%s» از «%s» برداشته شد.', $row['matched_text'], $this->title( $source->ID ) ), 'post', $source->ID );

		return $this->set( $id, 'rejected', 'لینک برداشته شد.' );
	}

	/**
	 * Rejects a suggestion, or marks a failed one as done by hand.
	 *
	 * @param int    $id     Suggestion.
	 * @param string $status rejected|applied|pending.
	 */
	public function set_status( int $id, string $status ): array {
		$notes = array(
			'rejected' => null,
			'applied'  => 'به‌صورت دستی انجام شد.',
			'pending'  => null,
		);

		return $this->set( $id, $status, $notes[ $status ] ?? null );
	}

	/**
	 * Changes the anchor text (must appear in the source post) and returns the item with context.
	 *
	 * @param int    $id     Suggestion.
	 * @param string $anchor New anchor text.
	 */
	public function update_anchor( int $id, string $anchor ): array {
		global $wpdb;

		$anchor = mb_substr( trim( wp_strip_all_tags( $anchor ) ), 0, 255 );
		if ( '' !== $anchor ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::table( 'link_suggestions' ),
				array(
					'anchor'     => $anchor,
					'status'     => 'pending',
					'note'       => null,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
		}

		return $this->item( $this->row( $id ), true );
	}

	/**
	 * Creates PaydarPress suggestions for orphan content: for each orphan target, the best
	 * related posts in which one of the target's phrases (focus keyword, title or a part of
	 * it) already appears as plain text.
	 *
	 * @return array{targets:int, created:int}
	 */
	public function generate_for_orphans(): array {
		global $wpdb;

		$findings = Schema::table( 'audit_findings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$targets = $wpdb->get_col( "SELECT object_id FROM {$findings} WHERE code IN ('ORPHAN_POST', 'ORPHAN_PAGE', 'ORPHAN_PRODUCT', 'WEAK_INBOUND') AND state IN ('open', 'reviewed') ORDER BY id LIMIT " . self::GENERATE_TARGETS );

		$suggester = new LinkSuggester();
		$created   = 0;

		foreach ( array_map( 'intval', (array) $targets ) as $target_id ) {
			$phrases = $this->phrases( $target_id );
			$added   = 0;

			foreach ( $suggester->for_target( $target_id, 8 ) as $candidate ) {
				if ( $added >= 3 ) {
					break;
				}
				$source = get_post( (int) $candidate['id'] );
				if ( ! $source || 'builder' === get_post_meta( $source->ID, '_elementor_edit_mode', true ) || null !== $this->budget_block( $source, false ) ) {
					continue;
				}
				if ( $this->count_where( 'source_id = %d AND target_id = %d', array( $source->ID, $target_id ) ) > 0 ) {
					continue; // Already suggested (in any status).
				}
				$own = PersianNormalizer::for_key( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT focus_keyword FROM ' . Schema::table( 'inventory' ) . ' WHERE object_id = %d', $source->ID ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $phrases as $phrase ) {
					if ( '' !== $own && PersianNormalizer::for_key( $phrase ) === $own ) {
						continue; // Linking away with the source's own main keyword would confuse search engines.
					}
					$found = LinkInserter::locate( (string) $source->post_content, $phrase );
					if ( null !== $found ) {
						$text = html_entity_decode( $found['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						if ( \PaydarWP\PaydarPress\Import\LinkSuggestionRepository::insert( $source->ID, $target_id, $text, '', 'paydarpress', 0 ) ) {
							++$created;
							++$added;
						}
						break;
					}
				}
			}
		}

		if ( $created > 0 ) {
			Activity::log( 'links.generated', sprintf( '%s پیشنهاد لینک برای محتوای یتیم ساخته شد.', PersianNormalizer::fa_number( $created ) ), 'links', 0 );
		}

		return array(
			'targets' => count( (array) $targets ),
			'created' => $created,
		);
	}

	/**
	 * Best anchor for linking source → target: the first target phrase found in the source.
	 *
	 * @param int $source_id Source.
	 * @param int $target_id Target.
	 * @return string|null Exact text from the source, or null.
	 */
	public function find_anchor( int $source_id, int $target_id ): ?string {
		$source = get_post( $source_id );
		if ( ! $source ) {
			return null;
		}
		foreach ( $this->phrases( $target_id ) as $phrase ) {
			$found = LinkInserter::locate( (string) $source->post_content, $phrase );
			if ( null !== $found ) {
				return html_entity_decode( $found['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return null;
	}

	/**
	 * Paged list with context for pending/failed items.
	 *
	 * @param string $status   Status ('' = all).
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 */
	public function list( string $status, int $page, int $per_page ): array {
		global $wpdb;

		$table = Schema::table( 'link_suggestions' );
		$where = in_array( $status, self::STATUSES, true ) ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable

		$by_status = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $counts as $c ) {
			$by_status[ $c['status'] ] = (int) $c['n'];
		}

		return array(
			'items'       => array_map( fn( $row ) => $this->item( $row, in_array( $row['status'], array( 'pending', 'failed' ), true ) ), (array) $rows ),
			'total'       => $total,
			'counts'      => $by_status,
			'page'        => $page,
			'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
		);
	}

	/**
	 * Phrases to look for in a source when linking to a target: focus keyword, title,
	 * then 4- to 2-word runs of the title that do not start or end with a stopword.
	 *
	 * @param int $target_id Target.
	 * @return string[]
	 */
	private function phrases( int $target_id ): array {
		global $wpdb;

		$table   = Schema::table( 'inventory' );
		$keyword = (string) $wpdb->get_var( $wpdb->prepare( "SELECT focus_keyword FROM {$table} WHERE object_id = %d", $target_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$title   = trim( (string) preg_replace( '/[؟?!.:،,«»"]+/u', ' ', $this->title( $target_id ) ) );

		$phrases = array_filter( array( $keyword, $title ) );
		$words   = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );
		$stop    = array( 'از', 'به', 'با', 'در', 'و', 'که', 'را', 'این', 'آن', 'برای', 'چگونه', 'چیست', 'کیست', 'است', 'هست', 'چه', 'یک', 'می', 'شود', 'میشود', 'می‌شود', 'میشه', 'کرد', 'کنیم', 'کنید', 'دارد', 'دارند', 'چطور', 'چقدر', 'چرا', 'کدام', 'کجا', 'بر', 'تا', 'یا', 'هم', 'نیز', 'ها', 'های' );

		for ( $size = min( 4, count( $words ) - 1 ); $size >= 2; $size-- ) {
			for ( $i = 0; $i + $size <= count( $words ); $i++ ) {
				$run = array_slice( $words, $i, $size );
				if ( in_array( $run[0], $stop, true ) || in_array( end( $run ), $stop, true ) ) {
					continue;
				}
				$phrases[] = implode( ' ', $run );
			}
		}

		// Single words ("وکیل", "نفقه") are too generic to link automatically: at least two words.
		return array_values(
			array_unique(
				array_filter(
					$phrases,
					static function ( $p ) use ( $stop ) {
						$words = preg_split( '/[\s\x{200C}]+/u', trim( $p ), -1, PREG_SPLIT_NO_EMPTY );
						return count( $words ) >= 2 && mb_strlen( $p ) >= 6 && ! in_array( end( $words ), $stop, true ) && ! in_array( $words[0], $stop, true );
					}
				)
			)
		);
	}

	/**
	 * Whether the HTML already links to the target (any URL that resolves to it).
	 *
	 * @param string $html      HTML.
	 * @param int    $target_id Target.
	 */
	private function links_to( string $html, int $target_id ): bool {
		if ( ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#isu', $html, $m ) ) {
			return false;
		}

		$urls  = new UrlNormalizer( home_url( '/' ) );
		$want  = $urls->key( (string) get_permalink( $target_id ) );
		$plain = $urls->key( home_url( '/?p=' . $target_id ) );
		foreach ( $m[2] as $href ) {
			$abs = $urls->absolute( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ), home_url( '/' ) );
			if ( null !== $abs && in_array( $urls->key( $abs ), array( $want, $plain ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * API representation.
	 *
	 * @param array|null $row          Row.
	 * @param bool       $with_context Include the sentence around the anchor.
	 */
	private function item( ?array $row, bool $with_context = false ): array {
		if ( null === $row ) {
			return array( 'missing' => true );
		}

		$source  = (int) $row['source_id'];
		$target  = (int) $row['target_id'];
		$context = null;
		if ( $with_context ) {
			$post    = get_post( $source );
			$context = $post ? LinkInserter::context( (string) $post->post_content, (string) $row['anchor'] ) : null;
		}

		return array(
			'id'         => (int) $row['id'],
			'status'     => $row['status'],
			'origin'     => $row['origin'],
			'anchor'     => $row['anchor'],
			'note'       => $row['note'],
			'sentence'   => (string) $row['sentence'],
			'context'    => $context,
			'found'      => null !== $context,
			'source'     => $this->post_ref( $source ),
			'target'     => $this->post_ref( $target ),
			'applied_at' => empty( $row['applied_at'] ) ? null : gmdate( 'c', (int) strtotime( $row['applied_at'] . ' UTC' ) ),
			'can_undo'   => 'applied' === $row['status'] && ! empty( $row['inserted'] ),
			'updated_at' => gmdate( 'c', (int) strtotime( $row['updated_at'] . ' UTC' ) ),
		);
	}

	/**
	 * Small post reference.
	 *
	 * @param int $id Post.
	 */
	private function post_ref( int $id ): array {
		$post = get_post( $id );

		return array(
			'id'       => $id,
			'title'    => $post ? $this->title( $id ) : 'حذف‌شده',
			'status'   => $post ? (string) $post->post_status : 'trash',
			'url'      => $post ? (string) get_permalink( $id ) : '',
			'edit_url' => $post ? (string) get_edit_post_link( $id, 'raw' ) : '',
			'builder'  => $post && 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ),
		);
	}

	/**
	 * Updates status and note; returns the fresh item.
	 *
	 * @param int         $id     Suggestion.
	 * @param string      $status Status.
	 * @param string|null $note   Note.
	 */
	private function set( int $id, string $status, ?string $note ): array {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'link_suggestions' ),
			array(
				'status'     => $status,
				'note'       => $note,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return $this->item( $this->row( $id ), in_array( $status, array( 'pending', 'failed' ), true ) );
	}

	/**
	 * Row by ID.
	 *
	 * @param int $id ID.
	 */
	private function row( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Row count for a condition.
	 *
	 * @param string $where Prepared-style condition.
	 * @param array  $args  Arguments.
	 */
	/**
	 * Why the policy forbids adding a link to this source now, or null.
	 *
	 * @param WP_Post $source       Source post.
	 * @param bool    $repair       Orphan-repair link (counts against the weekly site limit);
	 *                              links that come with a new article do not.
	 */
	public function budget_block( WP_Post $source, bool $repair = true ): ?string {
		$per_post = max( 1, (int) Settings::get( 'policy.links_per_post_month', 1 ) );
		$recent   = $this->count_where( "source_id = %d AND status = 'applied' AND applied_at >= %s", array( $source->ID, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		if ( $recent >= $per_post ) {
			return sprintf( 'سیاست محتوا: «%s» در ۳۰ روز گذشته %s لینک تازه گرفته است (سقف %s در ماه). تغییر پشت‌سرهم یک مقاله قدیمی ارزش آن را پایین می‌آورد؛ این لینک را بعداً درج کنید یا از مقاله دیگری لینک بدهید.', $this->title( $source->ID ), PersianNormalizer::fa_number( $recent ), PersianNormalizer::fa_number( $per_post ) );
		}

		if ( $repair ) {
			$week = max( 1, (int) Settings::get( 'policy.links_per_week', 15 ) );
			$done = $this->count_where( "status = 'applied' AND origin = 'paydarpress' AND applied_at >= %s", array( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
			if ( $done >= $week ) {
				return sprintf( 'سیاست محتوا: سقف هفتگی %s لینک ترمیمی پر شده است. هفته بعد دوباره «درج» بزنید.', PersianNormalizer::fa_number( $week ) );
			}
		}

		$html  = (string) $source->post_content;
		$words = PersianNormalizer::word_count( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) ) );
		$max   = max( 3, intdiv( $words, max( 50, (int) Settings::get( 'policy.words_per_link', 150 ) ) ) );
		$links = $this->internal_link_count( $html );
		if ( $links >= $max ) {
			return sprintf( 'سیاست محتوا: «%s» با %s کلمه از قبل %s لینک داخلی دارد (حداکثر %s). لینک بیشتر خواندن را سخت می‌کند؛ از مقاله دیگری لینک بدهید.', $this->title( $source->ID ), PersianNormalizer::fa_number( $words ), PersianNormalizer::fa_number( $links ), PersianNormalizer::fa_number( $max ) );
		}
		return null;
	}

	/**
	 * Number of links to this site in an HTML fragment.
	 *
	 * @param string $html HTML.
	 */
	private function internal_link_count( string $html ): int {
		if ( ! preg_match_all( '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#isu', $html, $m ) ) {
			return 0;
		}
		$urls = new UrlNormalizer( home_url( '/' ) );
		$n    = 0;
		foreach ( $m[2] as $href ) {
			$abs = $urls->absolute( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ), home_url( '/' ) );
			if ( null !== $abs && $urls->is_internal( $abs ) && ! $urls->is_asset( $abs ) ) {
				++$n;
			}
		}
		return $n;
	}

	private function count_where( string $where, array $args ): int {
		global $wpdb;

		$table = Schema::table( 'link_suggestions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) );
	}

	/**
	 * Loads wp_check_post_lock() outside wp-admin screens (REST, cron).
	 */
	private function load_post_lock(): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) && file_exists( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		return function_exists( 'wp_check_post_lock' );
	}

	/**
	 * Decoded post title.
	 *
	 * @param int $id Post.
	 */
	private function title( int $id ): string {
		return html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
	}
}
