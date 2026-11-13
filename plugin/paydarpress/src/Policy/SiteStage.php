<?php
/**
 * Site stage (new / growing / mature), detected from the amount of published content, with
 * recommended content-policy values for each stage.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Policy;

use PaydarWP\PaydarPress\Core\Settings;
use PaydarWP\PaydarPress\Text\PersianNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * The owner can override the detected stage in Settings → content policy.
 */
final class SiteStage {

	public const STAGES = array(
		'new'     => 'تازه‌تأسیس',
		'growing' => 'در حال رشد',
		'mature'  => 'بالغ',
	);

	/** Recommended policy per stage. */
	public const PRESETS = array(
		'new'     => array(
			'new_per_month'   => 30,
			'publish_per_day' => 2,
			'refresh_share'   => 0,
			'grace_days'      => 60,
		),
		'growing' => array(
			'new_per_month'   => 12,
			'publish_per_day' => 2,
			'refresh_share'   => 30,
			'grace_days'      => 45,
		),
		'mature'  => array(
			'new_per_month'   => 8,
			'publish_per_day' => 2,
			'refresh_share'   => 50,
			'grace_days'      => 45,
		),
	);

	public const ADVICE = array(
		'new'     => 'سایت هنوز محتوای کافی ندارد. اول صفحات کلیدی و صفحات خدمات/محصول را کامل کنید؛ سپس روزی ۱ تا ۲ مقاله، دسته به دسته، تا به حدود ۳۰ مطلب پایه برسید. در این مرحله بازنویسی لازم نیست و «یتیم» بودن مطالب تازه طبیعی است.',
		'growing' => 'پایه سایت ساخته شده است. بین مقاله جدید (برای دسته‌ها و جست‌وجوهای پوشش‌داده‌نشده) و بهتر کردن مطالبی که در گوگل دیده می‌شوند تعادل نگه دارید.',
		'mature'  => 'سایت محتوای زیادی دارد. بیشترین بازده در بازنویسی، ادغام مطالب هم‌موضوع و تقویت مطالب پرترافیک است؛ مقاله جدید فقط برای نیازی که واقعاً پوشش داده نشده.',
	);

	/**
	 * Published content counts.
	 *
	 * @return array{posts: int, pages: int, products: int, first: ?string}
	 */
	public static function counts(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page','product') GROUP BY post_type", ARRAY_A );
		$first = $wpdb->get_var( "SELECT MIN(post_date) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );
		// phpcs:enable
		$by = array();
		foreach ( (array) $rows as $r ) {
			$by[ $r['post_type'] ] = (int) $r['n'];
		}
		return array(
			'posts'    => $by['post'] ?? 0,
			'pages'    => $by['page'] ?? 0,
			'products' => $by['product'] ?? 0,
			'first'    => $first ? (string) $first : null,
		);
	}

	/**
	 * Stage from the counts: articles (or products for shops) are the content that grows.
	 */
	public static function detect(): string {
		$c       = self::counts();
		$content = max( $c['posts'], (int) floor( $c['products'] / 5 ) );
		if ( $content < 20 ) {
			return 'new';
		}
		return $content <= 200 ? 'growing' : 'mature';
	}

	/**
	 * Stage in use (owner's choice or detected).
	 */
	public static function current(): string {
		$chosen = (string) Settings::get( 'policy.site_stage', 'auto' );
		return isset( self::STAGES[ $chosen ] ) ? $chosen : self::detect();
	}

	/**
	 * Payload for the UI.
	 */
	public static function info(): array {
		$stage    = self::current();
		$detected = self::detect();
		$c        = self::counts();
		$current  = array();
		foreach ( array_keys( self::PRESETS[ $stage ] ) as $key ) {
			$current[ $key ] = (int) Settings::get( 'policy.' . $key, 0 );
		}
		return array(
			'stage'       => $stage,
			'label'       => self::STAGES[ $stage ],
			'detected'    => $detected,
			'chosen'      => (string) Settings::get( 'policy.site_stage', 'auto' ),
			'counts'      => $c,
			'reason'      => sprintf( '%s مقاله، %s برگه%s منتشر شده است.', PersianNormalizer::fa_number( $c['posts'] ), PersianNormalizer::fa_number( $c['pages'] ), $c['products'] ? '، ' . PersianNormalizer::fa_number( $c['products'] ) . ' محصول' : '' ),
			'advice'      => self::ADVICE[ $stage ],
			'recommended' => self::PRESETS[ $stage ],
			'current'     => $current,
			'matches'     => $current === self::PRESETS[ $stage ],
		);
	}

	/**
	 * Writes the recommended values of the current stage.
	 */
	public static function apply(): void {
		Settings::replace( array( 'policy' => self::PRESETS[ self::current() ] ) );
	}
}
