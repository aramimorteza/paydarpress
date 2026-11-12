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
			printf( '<p><strong>%s</strong></p>', esc_html( sprintf( 'پرسش‌های متداول (%d)', count( $faq ) ) ) );
			echo '<p class="description">' . esc_html( 'برای اسکیما، این پرسش‌ها را با بلوک FAQ افزونه سئو اضافه کنید؛ متن آن‌ها باید با بخش پرسش‌های متداول مقاله یکی باشد.' ) . '</p><ol>';
			foreach ( $faq as $item ) {
				printf( '<li><strong>%s</strong><br>%s</li>', esc_html( (string) ( $item['q'] ?? '' ) ), esc_html( (string) ( $item['a'] ?? '' ) ) );
			}
			echo '</ol>';
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
