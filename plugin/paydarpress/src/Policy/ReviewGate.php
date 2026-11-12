<?php
/**
 * Content policy: AI-written drafts on sensitive (YMYL) sites cannot be published until a
 * person confirms they reviewed them.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Policy;

use PaydarWP\PaydarPress\Core\Activity;
use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Import\ImportService;
use PaydarWP\PaydarPress\Profile\Industries;
use PaydarWP\PaydarPress\Profile\ProfileRepository;
use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the block editor (REST), classic editor and quick edit.
 */
final class ReviewGate {

	public const META = '_paydarpress_reviewed';

	private const TYPES = array( 'post', 'page', 'product' );

	/**
	 * Hooks (admin and REST only; nothing runs on public pages).
	 */
	public function register(): void {
		foreach ( self::TYPES as $type ) {
			add_filter( "rest_pre_insert_{$type}", array( $this, 'rest_guard' ), 10, 2 );
		}
		add_filter( 'wp_insert_post_data', array( $this, 'classic_guard' ), 10, 2 );
	}

	/**
	 * Whether the site is sensitive (YMYL by profile or industry).
	 */
	public static function sensitive_site(): bool {
		if ( 'YMYL' === (string) ProfileRepository::value( 'fact_sensitivity' ) ) {
			return true;
		}
		$industry = Industries::all()[ (string) ProfileRepository::value( 'industry_module' ) ] ?? null;
		return ! empty( $industry['ymyl'] );
	}

	/**
	 * Whether this post still needs a human review before publishing.
	 *
	 * @param int $post_id Post.
	 */
	public static function needs_review( int $post_id ): bool {
		return $post_id > 0
			&& (bool) Settings::get( 'policy.require_review', true )
			&& '' !== (string) get_post_meta( $post_id, ImportService::META_IMPORT, true )
			&& '' === (string) get_post_meta( $post_id, self::META, true )
			&& self::sensitive_site();
	}

	/**
	 * Stores the review confirmation.
	 *
	 * @param int $post_id Post.
	 */
	public static function confirm( int $post_id ): void {
		$user = wp_get_current_user();
		update_post_meta(
			$post_id,
			self::META,
			array(
				'user_id' => (int) $user->ID,
				'name'    => (string) $user->display_name,
				'at'      => gmdate( 'c' ),
			)
		);
		Activity::log( 'import.reviewed', 'بازبینی «' . html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) . '» تأیید شد.', 'post', $post_id );
	}

	/**
	 * Message shown when publishing is blocked.
	 */
	public static function message(): string {
		return 'سیاست محتوای پایدارپرس: این متن با هوش مصنوعی نوشته شده و سایت شما حساس (YMYL) است. پیش از انتشار، متن را دقیق بخوانید و در کادر «پایدارپرس» کنار ویرایشگر «تأیید بازبینی» را بزنید.';
	}

	/**
	 * Block editor: refuse publish/schedule while a review is missing.
	 *
	 * @param \stdClass|WP_Error $prepared Prepared post.
	 * @param WP_REST_Request    $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public function rest_guard( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || empty( $prepared->ID ) ) {
			return $prepared;
		}
		$status = (string) ( $request['status'] ?? '' );
		if ( in_array( $status, array( 'publish', 'future' ), true ) && 'publish' !== get_post_status( (int) $prepared->ID ) && self::needs_review( (int) $prepared->ID ) ) {
			return new WP_Error( 'paydarpress_review_required', self::message(), array( 'status' => 403 ) );
		}
		return $prepared;
	}

	/**
	 * Classic editor and quick edit: keep the post pending instead of publishing it.
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Raw post array.
	 */
	public function classic_guard( $data, $postarr ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id > 0 && in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) && in_array( $data['post_type'] ?? '', self::TYPES, true ) && 'publish' !== get_post_status( $id ) && self::needs_review( $id ) ) {
			$data['post_status'] = 'pending';
			set_transient( 'paydarpress_review_notice_' . get_current_user_id(), 1, 60 );
		}
		return $data;
	}

	/**
	 * Notice after the classic guard kept a post pending.
	 */
	public static function admin_notice(): void {
		$key = 'paydarpress_review_notice_' . get_current_user_id();
		if ( get_transient( $key ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning" dir="rtl"><p>' . esc_html( self::message() . ' نوشته در وضعیت «در انتظار بررسی» ذخیره شد.' ) . '</p></div>';
		}
	}
}
