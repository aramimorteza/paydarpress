<?php
/**
 * WooCommerce: whether the shop should invest first in product pages or in product category
 * pages, from its size and (when available) where Google traffic lands.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Strategy;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Gsc\GscRepository;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * product: few products → each product page deserves a full description.
 * category: many products per category → category pages carry the main searches.
 * mixed: both, ordered by traffic.
 */
final class ShopStrategy {

	public const FOCUS = array(
		'product'  => 'تمرکز بر صفحه محصول',
		'category' => 'تمرکز بر دسته محصول',
		'mixed'    => 'ترکیبی (به ترتیب ترافیک)',
	);

	/**
	 * Whether WooCommerce products exist.
	 */
	public static function active(): bool {
		return post_type_exists( 'product' ) && taxonomy_exists( 'product_cat' );
	}

	/**
	 * Shop size.
	 *
	 * @return array{products: int, categories: int, avg: float, max: int}
	 */
	public static function size(): array {
		$products = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
		$counts   = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 500 ) ) as $t ) {
			if ( $t instanceof \WP_Term && 'uncategorized' !== $t->slug ) {
				$counts[] = (int) $t->count;
			}
		}
		return array(
			'products'   => $products,
			'categories' => count( $counts ),
			'avg'        => $counts ? round( array_sum( $counts ) / count( $counts ), 1 ) : 0.0,
			'max'        => $counts ? max( $counts ) : 0,
		);
	}

	/**
	 * Detected focus.
	 */
	public static function detect(): string {
		$s = self::size();
		if ( $s['products'] <= 60 || $s['avg'] < 20 ) {
			return 'product';
		}
		if ( $s['categories'] >= 8 && ( $s['avg'] >= 40 || $s['products'] > 400 ) ) {
			return 'category';
		}
		return 'mixed';
	}

	/**
	 * Focus in use (owner's choice or detected).
	 */
	public static function current(): string {
		$chosen = (string) Settings::get( 'policy.shop_focus', 'auto' );
		return isset( self::FOCUS[ $chosen ] ) ? $chosen : self::detect();
	}

	/**
	 * Payload for the UI and prompts.
	 */
	public static function info(): array {
		if ( ! self::active() ) {
			return array( 'active' => false );
		}
		$s     = self::size();
		$focus = self::current();
		$why   = array(
			'product'  => 'تعداد محصولات کم است و هر صفحه محصول فرصت جداگانه‌ای در گوگل دارد: توضیح کامل، پرسش‌های متداول و تصویر خوب برای هر محصول بیشترین اثر را دارد. متن معرفی دسته‌ها هم لازم است، اما کوتاه.',
			'category' => 'هر دسته محصولات زیادی دارد و جست‌وجوهای پرتکرار (مثل «خرید …») به صفحه دسته می‌رسند: متن معرفی کامل دسته، پرسش‌های متداول دسته و لینک به زیردسته‌ها مهم‌ترند. بازنویسی کامل فقط برای محصولات پرفروش یا پربازدید.',
			'mixed'    => 'اندازه فروشگاه متوسط است: هم دسته‌های اصلی و هم محصولات پرفروش، به ترتیب ترافیک سرچ کنسول.',
		);
		$traffic = self::traffic_split();
		return array(
			'active'   => true,
			'focus'    => $focus,
			'label'    => self::FOCUS[ $focus ],
			'detected' => self::detect(),
			'chosen'   => (string) Settings::get( 'policy.shop_focus', 'auto' ),
			'size'     => $s,
			'reason'   => sprintf( '%s محصول در %s دسته؛ میانگین %s محصول در هر دسته.', PersianNormalizer::fa_number( $s['products'] ), PersianNormalizer::fa_number( $s['categories'] ), PersianNormalizer::fa_number( $s['avg'] ) ),
			'advice'   => $why[ $focus ],
			'traffic'  => $traffic,
		);
	}

	/**
	 * Clicks from Google on product pages vs. archive pages (categories), when data exists.
	 *
	 * @return array{products: int, archives: int}|null
	 */
	public static function traffic_split(): ?array {
		if ( ! GscRepository::has_data() ) {
			return null;
		}
		$products = 0;
		foreach ( GscRepository::page_metrics() as $id => $m ) {
			if ( 'product' === get_post_type( $id ) ) {
				$products += $m['clicks'];
			}
		}
		$archives = 0;
		foreach ( GscRepository::unmatched( 500 ) as $row ) {
			if ( 'archive' === $row['match_state'] && false !== strpos( rawurldecode( (string) $row['item'] ), '/product-category/' ) ) {
				$archives += (int) $row['clicks'];
			}
		}
		return array(
			'products' => $products,
			'archives' => $archives,
		);
	}

	/**
	 * Products ordered by importance (sales, then Search Console impressions).
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public static function top_products( int $limit ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'meta_key'       => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		return array_map( 'intval', $ids );
	}
}
