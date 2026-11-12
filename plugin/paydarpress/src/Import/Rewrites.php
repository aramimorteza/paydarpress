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

		wp_trash_post( $draft->ID );
		Activity::log( 'import.rewrite_applied', 'بازنویسی «' . html_entity_decode( get_the_title( $original ), ENT_QUOTES, 'UTF-8' ) . '» جایگزین متن قبلی شد (نسخه قبلی در «بازنگری‌ها» هست).', 'post', $original->ID );

		return $original->ID;
	}

	/**
	 * Success notice after replacing.
	 */
	public function notice(): void {
		if ( empty( $_GET['paydarpress_rewritten'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		echo '<div class="notice notice-success is-dismissible" dir="rtl"><p>' . esc_html( 'بازنویسی جایگزین شد. نسخه قبلی در «بازنگری‌ها»ی همین نوشته هست و پیش‌نویس بازنویسی به زباله‌دان رفت.' ) . '</p></div>';
	}
}
