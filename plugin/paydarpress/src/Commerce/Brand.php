<?php
/**
 * PaydarWP brand details shown in the training and support tab.
 *
 * Links that are not ready yet stay empty and the tab shows them as "به‌زودی".
 * Filter: paydarpress/brand.
 *
 * @package PaydarWP\PaydarPress
 */

namespace PaydarWP\PaydarPress\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Static brand data.
 */
final class Brand {

	public const SITE = 'https://paydarwp.ir';

	/**
	 * Contact and training links (empty = not published yet).
	 */
	public const LINKS = array(
		'site'     => array( 'label' => 'سایت پایدار وردپرس', 'url' => self::SITE, 'group' => 'about' ),
		'docs'     => array( 'label' => 'راهنمای کامل پایدارپرس', 'url' => '', 'group' => 'learn' ),
		'videos'   => array( 'label' => 'ویدیوهای آموزشی', 'url' => '', 'group' => 'learn' ),
		'blog'     => array( 'label' => 'مقاله‌های سئو و محتوا', 'url' => '', 'group' => 'learn' ),
		'ticket'   => array( 'label' => 'ارسال تیکت پشتیبانی', 'url' => '', 'group' => 'support' ),
		'telegram' => array( 'label' => 'تلگرام', 'url' => '', 'group' => 'support' ),
		'whatsapp' => array( 'label' => 'واتس‌اپ', 'url' => '', 'group' => 'support' ),
		'phone'    => array( 'label' => 'تلفن', 'url' => '', 'group' => 'support' ),
	);

	/**
	 * Brand data after the filter.
	 */
	public static function info(): array {
		$info = apply_filters(
			'paydarpress/brand',
			array(
				'name'    => 'پایدار وردپرس',
				'site'    => self::SITE,
				'tagline' => 'افزونه‌های فارسی وردپرس و ووکامرس، ساخته‌شده برای سایت‌های ایرانی',
				'about'   => 'پایدار وردپرس افزونه‌های تجاری فارسی برای وردپرس و ووکامرس می‌سازد: ابزارهایی که کار روزمره مدیر سایت را ساده می‌کنند، به اصول وردپرس وفادارند و داده سایت را بیرون نمی‌برند. پایدارپرس یکی از این محصولات است: شناخت سایت، ممیزی سلامت محتوا، لینک‌سازی داخلی و تولید محتوا با هوش مصنوعی.',
				'hours'   => '',
				'links'   => self::LINKS,
			)
		);
		$info  = is_array( $info ) ? $info : array();
		$links = array();
		foreach ( (array) ( $info['links'] ?? array() ) as $key => $l ) {
			if ( ! is_array( $l ) || empty( $l['label'] ) ) {
				continue;
			}
			$url = (string) ( $l['url'] ?? '' );
			// Only web, Telegram, WhatsApp and phone links.
			if ( '' !== $url && ! preg_match( '#^(https://|tel:|tg://)#', $url ) ) {
				$url = '';
			}
			$links[ sanitize_key( (string) $key ) ] = array(
				'label' => (string) $l['label'],
				'url'   => $url,
				'group' => in_array( $l['group'] ?? '', array( 'about', 'learn', 'support' ), true ) ? $l['group'] : 'support',
			);
		}
		return array(
			'name'    => (string) ( $info['name'] ?? 'پایدار وردپرس' ),
			'site'    => (string) ( $info['site'] ?? self::SITE ),
			'tagline' => (string) ( $info['tagline'] ?? '' ),
			'about'   => (string) ( $info['about'] ?? '' ),
			'hours'   => (string) ( $info['hours'] ?? '' ),
			'links'   => $links,
		);
	}

	/**
	 * Other PaydarWP products registered through the shared hub filter.
	 */
	public static function products(): array {
		$list = apply_filters( 'paydarwp_hub_products', array() );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $p ) {
			if ( is_array( $p ) && ! empty( $p['name'] ) ) {
				$out[] = array(
					'name'        => wp_strip_all_tags( (string) $p['name'] ),
					'description' => wp_strip_all_tags( (string) ( $p['description'] ?? '' ) ),
					'version'     => sanitize_text_field( (string) ( $p['version'] ?? '' ) ),
					'url'         => esc_url_raw( (string) ( $p['url'] ?? '' ) ),
				);
			}
		}
		return $out;
	}
}
