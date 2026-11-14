<?php
/**
 * Rewrite drafts: an imported or generated rewrite is a separate draft linked to the original.
 * "Replace" copies it into the original (WordPress keeps a revision) and trashes the draft.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Import;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Seo\SeoDetector;
use RuntimeException;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Admin action: admin-post.php?action=paydarpress_apply_rewrite&post=ID&_wpnonce=…
 */
final class Rewrites {

	public const META_OF   = '_paydarpress_rewrite_of';
	public const META_PREV = '_paydarpress_prev_content';
	public const ACTION    = 'paydarpress_apply_rewrite';
	public const META_PLAN = '_paydarpress_rewrite_plan';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * URL of the replace action for a draft.
	 *
	 * @param int $draft_id Draft.
	 */
	public static function apply_url( int $draft_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . $draft_id ), self::ACTION . '_' . $draft_id );
	}

	/**
	 * The original a draft rewrites, or null.
	 *
	 * @param int $draft_id Draft.
	 */
	public static function original_of( int $draft_id ): ?WP_Post {
		$id   = (int) get_post_meta( $draft_id, self::META_OF, true );
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof WP_Post && 'trash' !== $post->post_status ? $post : null;
	}

	/**
	 * Handles the replace action.
	 */
	public function handle(): void {
		$draft_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::ACTION . '_' . $draft_id );

		try {
			$original = self::apply( $draft_id );
		} catch ( RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( 'paydarpress_rewritten', 1, (string) get_edit_post_link( $original, 'raw' ) ) );
		exit;
	}

	/**
	 * Copies the draft into the original.
	 *
	 * @param int $draft_id Draft.
	 * @return int Original post ID.
	 * @throws RuntimeException On failure.
	 */
	public static function apply( int $draft_id ): int {
		$draft    = get_post( $draft_id );
		$original = self::original_of( $draft_id );
		if ( ! $draft instanceof WP_Post || null === $original || ! in_array( $draft->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			throw new RuntimeException( 'این پیش‌نویس بازنویسی نیست یا نوشته اصلی حذف شده است.' );
		}
		if ( ! current_user_can( 'edit_post', $draft->ID ) || ! current_user_can( 'edit_post', $original->ID ) ) {
			throw new RuntimeException( 'اجازه ویرایش این نوشته‌ها را ندارید.' );
		}
		if ( \PaydarWP\PaydarPress\Policy\ReviewGate::needs_review( $draft->ID ) ) {
			throw new RuntimeException( \PaydarWP\PaydarPress\Policy\ReviewGate::message() );
		}
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$lock = wp_check_post_lock( $original->ID );
		if ( $lock && (int) $lock !== get_current_user_id() ) {
			throw new RuntimeException( 'نوشته اصلی هم‌اکنون توسط کاربر دیگری ویرایش می‌شود. پس از بسته شدن ویرایشگر دوباره تلاش کنید.' );
		}

		update_post_meta(
			$original->ID,
			self::META_PREV,
			array(
				'title'      => $original->post_title,
				'content'    => $original->post_content,
				'excerpt'    => $original->post_excerpt,
				'replaced_at' => gmdate( 'c' ),
			)
		);

		$update = array(
			'ID'           => $original->ID,
			'post_title'   => $draft->post_title,
			'post_content' => $draft->post_content,
		);
		if ( '' !== trim( $draft->post_excerpt ) ) {
			$update['post_excerpt'] = $draft->post_excerpt;
		}
		// wp_update_post stores a revision of the old version (posts and pages).
		$r = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( 'ذخیره نوشته اصلی ناموفق بود: ' . $r->get_error_message() );
		}

		$adapter = SeoDetector::adapter();
		$seo     = $adapter->post( $draft->ID );
		$stored  = get_post_meta( $draft->ID, ImportService::META_SEO, true );
		$values  = array_filter(
			array(
				'title'            => (string) ( $seo['seo_title'] ?? ( is_array( $stored ) ? ( $stored['title'] ?? '' ) : '' ) ),
				'meta_description' => (string) ( $seo['meta_description'] ?? ( is_array( $stored ) ? ( $stored['meta_description'] ?? '' ) : '' ) ),
				'focus_keyword'    => (string) ( $seo['focus_keyword'] ?? ( is_array( $stored ) ? ( $stored['focus_keyword'] ?? '' ) : '' ) ),
			)
		);
		if ( $values ) {
			$adapter->write_post( $original->ID, $values );
		}

		$plan    = get_post_meta( $draft->ID, self::META_PLAN, true );
		$applied = is_array( $plan ) ? self::apply_plan( $original->ID, $plan ) : array();

		wp_trash_post( $draft->ID );
		\PaydarWP\PaydarPress\Strategy\ChangeLog::record( 'rewrite', $original->ID, 'متن بازنویسی جایگزین شد' . ( $draft->post_title !== $original->post_title ? '؛ عنوان: «' . $draft->post_title . '»' : '' ) . ( $applied ? '؛ ' . implode( '؛ ', $applied ) : '' ) );
		Activity::log( 'import.rewrite_applied', 'بازنویسی «' . html_entity_decode( get_the_title( $original ), ENT_QUOTES, 'UTF-8' ) . '» جایگزین متن قبلی شد (نسخه قبلی در «بازنگری‌ها» هست).', 'post', $original->ID );

		return $original->ID;
	}

	/**
	 * Human lines describing a chosen plan (preview, result and the editor box).
	 *
	 * @param array $plan Chosen plan.
	 * @return string[]
	 */
	public static function plan_lines( array $plan ): array {
		$lines = array();
		if ( ! empty( $plan['add'] ) || ! empty( $plan['new'] ) ) {
			$lines[] = 'افزودن برچسب: ' . implode( '، ', array_merge( (array) $plan['add'], array_map( static fn( $n ) => $n . ' (تازه)', (array) ( $plan['new'] ?? array() ) ) ) );
		}
		if ( ! empty( $plan['remove'] ) ) {
			$lines[] = 'حذف برچسب: ' . implode( '، ', (array) $plan['remove'] );
		}
		if ( ! empty( $plan['category'] ) ) {
			$lines[] = 'افزودن دسته: ' . $plan['category']['name'];
		}
		if ( ! empty( $plan['slug'] ) ) {
			$lines[] = 'آدرس: ' . $plan['slug']['from'] . ' ← ' . $plan['slug']['to'] . ' (با ریدایرکت ۳۰۱)';
		}
		return $lines;
	}

	/**
	 * Applies the ticked tag / category / address changes to the original.
	 *
	 * @param int   $post_id Original.
	 * @param array $plan    Chosen plan.
	 * @return string[] What was done.
	 */
	public static function apply_plan( int $post_id, array $plan ): array {
		$done = array();
		$tax  = (string) ( $plan['taxonomy'] ?? '' );
		if ( '' !== $tax && taxonomy_exists( $tax ) ) {
			$remove = array();
			foreach ( (array) ( $plan['remove'] ?? array() ) as $name ) {
				$t = get_term_by( 'name', (string) $name, $tax );
				if ( $t instanceof \WP_Term ) {
					$remove[] = (int) $t->term_id;
				}
			}
			if ( $remove ) {
				wp_remove_object_terms( $post_id, $remove, $tax );
				$done[] = 'حذف ' . count( $remove ) . ' برچسب';
			}
			$add = array();
			foreach ( (array) ( $plan['add'] ?? array() ) as $name ) {
				$t = get_term_by( 'name', (string) $name, $tax );
				if ( $t instanceof \WP_Term ) {
					$add[] = (int) $t->term_id;
				}
			}
			foreach ( (array) ( $plan['new'] ?? array() ) as $name ) {
				$t = term_exists( (string) $name, $tax );
				$t = $t ? $t : wp_insert_term( (string) $name, $tax );
				if ( is_array( $t ) && isset( $t['term_id'] ) ) {
					$add[] = (int) $t['term_id'];
				}
			}
			if ( $add ) {
				wp_set_object_terms( $post_id, $add, $tax, true );
				$done[] = 'افزودن ' . count( $add ) . ' برچسب';
			}
		}
		if ( ! empty( $plan['category'] ) ) {
			wp_set_post_categories( $post_id, array( (int) $plan['category']['id'] ), true );
			$done[] = 'افزودن دسته «' . $plan['category']['name'] . '»';
		}
		if ( ! empty( $plan['slug'] ) ) {
			$old = (string) get_permalink( $post_id );
			$r   = wp_update_post( array( 'ID' => $post_id, 'post_name' => (string) $plan['slug']['to'] ), true );
			if ( ! is_wp_error( $r ) && (string) get_permalink( $post_id ) !== $old ) {
				try {
					\PaydarWP\PaydarPress\Strategy\RedirectOwner::ensure( $old, $post_id, 'slug' );
					$done[] = 'آدرس تازه با ریدایرکت ۳۰۱';
				} catch ( \RuntimeException $e ) {
					// WordPress still redirects the old slug itself (_wp_old_slug).
					$done[] = 'آدرس تازه (ریدایرکت وردپرس)';
				}
			}
		}
		return $done;
	}

	/**
	 * Success notice after replacing.
	 */
	public function notice(): void {
		// On a rewrite draft: say plainly that this is not the live page.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $screen && 'post' === $screen->base && $id > 0 && empty( $_GET['paydarpress_rewritten'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$original = self::original_of( $id );
			$draft    = get_post( $id );
			if ( null !== $original && $draft instanceof WP_Post && in_array( $draft->post_status, array( 'draft', 'pending' ), true ) ) {
				printf(
					'<div class="notice notice-info" dir="rtl"><p><strong>%s</strong> %s <a href="%s" target="_blank" rel="noopener">%s</a>. %s</p></div>',
					esc_html( 'پیش‌نویس بازنویسی:' ),
					esc_html( 'این صفحه نسخه اصلی نیست. آدرس، دسته‌ها و برچسب‌های نهایی همان' ),
					esc_url( (string) get_permalink( $original ) ),
					esc_html( '«' . html_entity_decode( get_the_title( $original ), ENT_QUOTES, 'UTF-8' ) . '»' ),
					esc_html( 'است، مگر تغییری که در جعبه «پایدارپرس: بازنویسی» آمده. پس از بررسی، «جایگزینی نسخه اصلی» را بزنید.' )
				);
			}
		}
		if ( empty( $_GET['paydarpress_rewritten'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		echo '<div class="notice notice-success is-dismissible" dir="rtl"><p>' . esc_html( 'بازنویسی جایگزین شد. نسخه قبلی در «بازنگری‌ها»ی همین نوشته هست و پیش‌نویس بازنویسی به زباله‌دان رفت.' ) . '</p></div>';
	}
}
