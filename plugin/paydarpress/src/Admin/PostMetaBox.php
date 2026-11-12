<?php
/**
 * "PaydarPress" box on the post edit screen for drafts imported from ContentMaster:
 * featured image prompt and alt text (with copy buttons), FAQ, and stored SEO fields.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Admin;

use PaydarWP\PaydarPress\Import\ImportService;
use PaydarWP\PaydarPress\Import\Rewrites;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta box (read-only; nothing is saved from it).
 */
final class PostMetaBox {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_any' ), 10, 2 );
	}

	/**
	 * Adds the box only to posts created by a ContentMaster import.
	 *
	 * @param WP_Post $post Post.
	 */
	public function add( $post ): void {
		$this->add_any( 'post', $post );
	}

	/**
	 * Adds the box to posts, pages and products created by an import (rewrites included).
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 */
	public function add_any( $post_type, $post ): void {
		if ( ! $post instanceof WP_Post || ! in_array( $post_type, array( 'post', 'page', 'product' ), true ) || '' === (string) get_post_meta( $post->ID, ImportService::META_IMPORT, true ) ) {
			return;
		}
		$rewrite = null !== Rewrites::original_of( $post->ID );
		add_meta_box( 'paydarpress-import', $rewrite ? 'پایدارپرس: بازنویسی' : 'پایدارپرس: از ContentMaster', array( $this, 'render' ), $post_type, 'side', $rewrite ? 'high' : 'default' );
	}

	/**
	 * Box content.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ): void {
		$prompt = (string) get_post_meta( $post->ID, ImportService::META_PROMPT, true );
		$alt    = (string) get_post_meta( $post->ID, ImportService::META_ALT, true );
		$faq    = get_post_meta( $post->ID, ImportService::META_FAQ, true );
		$seo    = get_post_meta( $post->ID, ImportService::META_SEO, true );

		echo '<div dir="rtl" class="paydarpress-box">';

		$this->policy_section( $post );

		$original = Rewrites::original_of( $post->ID );
		if ( null !== $original && in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			printf(
				'<p>%s <a href="%s" target="_blank" rel="noopener">%s</a>.</p><p class="description">%s</p><p><a class="button button-primary" href="%s" onclick="return confirm(this.dataset.confirm);" data-confirm="%s">%s</a> <a class="button" href="%s" target="_blank" rel="noopener">%s</a></p><hr>',
				esc_html( 'این پیش‌نویس، بازنویسی' ),
				esc_url( (string) get_permalink( $original ) ),
				esc_html( '«' . html_entity_decode( get_the_title( $original ), ENT_QUOTES, 'UTF-8' ) . '»' ),
				esc_html( 'متن را بخوانید و ویرایش کنید و ذخیره کنید. با «جایگزینی»، عنوان، متن، چکیده و سئوی این پیش‌نویس روی نوشته اصلی می‌نشیند (آدرس عوض نمی‌شود، نسخه قبلی در بازنگری‌ها می‌ماند) و این پیش‌نویس به زباله‌دان می‌رود.' ),
				esc_url( Rewrites::apply_url( $post->ID ) ),
				esc_attr( 'تغییرات ذخیره‌نشده این صفحه از بین می‌رود. اول «ذخیره پیش‌نویس» را زده‌اید؟ جایگزین شود؟' ),
				esc_html( 'جایگزینی نوشته اصلی' ),
				esc_url( (string) get_permalink( $original ) ),
				esc_html( 'دیدن نسخه فعلی' )
			);
		}

		if ( '' !== $prompt ) {
			$this->copy_field( 'پرامپت تصویر شاخص', $prompt, 'ltr', 5 );
		}
		if ( '' !== $alt ) {
			$this->copy_field( 'متن جایگزین (Alt) تصویر شاخص', $alt, 'rtl', 2 );
			echo '<p class="description">' . esc_html( 'پس از ساخت تصویر، این متن را در قسمت «متن جایگزین» تصویر شاخص بچسبانید.' ) . '</p>';
		}

		if ( is_array( $seo ) && array() !== $seo ) {
			echo '<p><strong>' . esc_html( 'سئو (افزونه سئوی پشتیبانی‌شده فعال نبود)' ) . '</strong></p><ul>';
			$labels = array(
				'title'            => 'عنوان سئو',
				'meta_description' => 'توضیحات متا',
				'focus_keyword'    => 'کلمه کلیدی',
			);
			foreach ( $labels as $key => $label ) {
				if ( ! empty( $seo[ $key ] ) ) {
					printf( '<li><strong>%s:</strong> %s</li>', esc_html( $label ), esc_html( (string) $seo[ $key ] ) );
				}
			}
			echo '</ul>';
		}

		if ( is_array( $faq ) && array() !== $faq ) {
			$in_content = false !== strpos( (string) $post->post_content, \PaydarWP\PaydarPress\Content\Faq::CSS_CLASS );
			printf(
				'<p><strong>%s</strong><br><span class="description">%s</span></p>',
				esc_html( 'پرسش‌های متداول (' . \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( count( $faq ) ) . ')' ),
				esc_html(
					$in_content
						? 'در انتهای متن به‌صورت آکاردئون (بلوک «جزئیات») قرار گرفته‌اند. داده ساختاریافته FAQ را پایدارپرس از همین بخش متن می‌سازد؛ هر تغییری در ویرایشگر خودکار لحاظ می‌شود.'
						: 'بخش پرسش‌های متداول در متن پیدا نشد، پس داده ساختاریافته FAQ چاپ نمی‌شود.'
				)
			);
		}

		echo '</div>';
		?>
		<script>
		document.querySelectorAll( '.paydarpress-copy' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var field = document.getElementById( btn.getAttribute( 'data-target' ) );
				field.select();
				if ( navigator.clipboard ) { navigator.clipboard.writeText( field.value ); } else { document.execCommand( 'copy' ); }
				btn.textContent = 'کپی شد';
				setTimeout( function () { btn.textContent = 'کپی'; }, 1500 );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Review confirmation, inbound links waiting for publication and the daily publishing limit.
	 *
	 * @param WP_Post $post Post.
	 */
	private function policy_section( WP_Post $post ): void {
		global $wpdb;
		$gate = \PaydarWP\PaydarPress\Policy\ReviewGate::class;

		if ( 'publish' !== $post->post_status && $gate::sensitive_site() && (bool) \PaydarWP\PaydarPress\Core\Settings::get( 'policy.require_review', true ) ) {
			$done = get_post_meta( $post->ID, $gate::META, true );
			if ( is_array( $done ) && ! empty( $done['at'] ) ) {
				printf( '<p class="pp-ok">✓ %s</p>', esc_html( 'بازبینی تأیید شد' . ( ! empty( $done['name'] ) ? ' توسط ' . $done['name'] : '' ) . '.' ) );
			} else {
				printf(
					'<div class="pp-review"><p><strong>%s</strong></p><p class="description">%s</p><p><button type="button" class="button button-primary" id="pp-review-btn" data-url="%s" data-nonce="%s">%s</button></p></div>',
					esc_html( 'پیش از انتشار: بازبینی انسانی' ),
					esc_html( 'سایت شما حساس (YMYL) است. این متن را با هوش مصنوعی نوشته‌اید؛ ادعاها، قوانین، عددها و توصیه‌ها را با منبع معتبر بررسی کنید. تا تأیید نکنید، انتشار و زمان‌بندی ممکن نیست.' ),
					esc_url( rest_url( 'paydarpress/v1/posts/' . $post->ID . '/reviewed' ) ),
					esc_attr( wp_create_nonce( 'wp_rest' ) ),
					esc_html( 'خواندم و بررسی کردم؛ تأیید بازبینی' )
				);
			}
		}

		if ( 'publish' !== $post->post_status && null === Rewrites::original_of( $post->ID ) ) {
			$table   = \PaydarWP\PaydarPress\Database\Schema::table( 'link_suggestions' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$waiting = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE target_id = %d AND status = 'approved'", $post->ID ) );
			printf(
				'<p><strong>%s</strong><br><span class="description">%s</span></p>',
				esc_html( 'لینک‌های ورودی' ),
				esc_html(
					$waiting > 0
						? \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( $waiting ) . ' لینک از مقالات موجود به این مقاله، هم‌زمان با انتشار خودکار درج می‌شود (اگر متن لنگر در مقاله مبدأ پیدا نشود، در «صف لینک داخلی» با توضیح می‌ماند).'
						: 'هیچ لینک ورودی آماده نیست. پیش از انتشار از «صف لینک داخلی» یا دستی از ۲ تا ۳ مقاله مرتبط به این مقاله لینک بدهید.'
				)
			);
		}

		if ( 'publish' !== $post->post_status ) {
			$limit = max( 1, (int) \PaydarWP\PaydarPress\Core\Settings::get( 'policy.publish_per_day', 2 ) );
			$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page','product') AND post_date >= %s", current_time( 'Y-m-d 00:00:00' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $today >= $limit ) {
				printf( '<p class="pp-warn">%s</p>', esc_html( 'امروز ' . \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( $today ) . ' مطلب منتشر شده است (سیاست محتوا: ' . \PaydarWP\PaydarPress\Text\PersianNormalizer::fa_number( $limit ) . ' در روز). بهتر است این مطلب را برای روزهای بعد زمان‌بندی کنید؛ انتشار یکنواخت طبیعی‌تر از انتشار انبوه است.' ) );
			}
		}
		?>
		<script>
		( function () {
			var b = document.getElementById( 'pp-review-btn' );
			if ( ! b ) { return; }
			b.addEventListener( 'click', function () {
				b.disabled = true;
				fetch( b.dataset.url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': b.dataset.nonce } } )
					.then( function ( r ) { if ( ! r.ok ) { throw new Error(); } b.closest( '.pp-review' ).innerHTML = '<p class="pp-ok">✓ بازبینی تأیید شد. اکنون می‌توانید منتشر کنید.</p>'; } )
					.catch( function () { b.disabled = false; b.textContent = 'خطا؛ دوباره تلاش کنید'; } );
			} );
		} )();
		</script>
		<style>.paydarpress-box .pp-ok{color:#1e7e34;font-weight:600}.paydarpress-box .pp-warn{color:#8a5300}.paydarpress-box .pp-review{background:#fff7e6;border:1px solid #f0c36d;padding:8px;margin-bottom:8px}.paydarpress-box .pp-review .button{white-space:normal;height:auto;line-height:1.6;padding:4px 10px;max-width:100%}</style>
		<?php
	}

	/**
	 * Read-only textarea with a copy button.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $dir   Text direction.
	 * @param int    $rows  Rows.
	 */
	private function copy_field( string $label, string $value, string $dir, int $rows ): void {
		$id = 'paydarpress-' . md5( $label );
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label></p><textarea id="%1$s" class="widefat" rows="%3$d" readonly dir="%4$s">%5$s</textarea><p><button type="button" class="button paydarpress-copy" data-target="%1$s">%6$s</button></p>',
			esc_attr( $id ),
			esc_html( $label ),
			(int) $rows,
			esc_attr( $dir ),
			esc_textarea( $value ),
			esc_html( 'کپی' )
		);
	}
}
