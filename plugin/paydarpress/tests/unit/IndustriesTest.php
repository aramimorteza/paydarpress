<?php
/**
 * Industry detection tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Profile\Industries as I;
use PaydarWP\PaydarPress\Text\PersianNormalizer as N;

$d = static fn( $text, $woo = false ) => I::detect( N::normalize( $text ), $woo );

check( 'ecommerce-retail', $d( 'کتاب‌فروشی آنلاین نیلوفر رمان کتاب کودک' ), 'industry: bookstore' );
check( 'legal', $d( 'دفتر وکالت وکیل کرج دعاوی خانواده' ), 'industry: law firm' );
check( 'medical-health', $d( 'کلینیک دندانپزشکی لبخند ایمپلنت' ), 'industry: dental clinic' );
check( 'local-services', $d( 'تعمیرات لوازم خانگی در محل' ), 'industry: home repairs' );
check( 'technology-software', $d( 'طراحی سایت وردپرس و سئو' ), 'industry: web design' );
check( 'tourism-travel', $d( 'تور طبیعت‌گردی و اقامتگاه بوم‌گردی' ), 'industry: tourism' );
check( 'product-manufacturing', $d( 'تولیدی کابینت و کمد سفارشی', true ), 'industry: manufacturer beats generic shop' );
check( 'ecommerce-retail', $d( 'سایت من', true ), 'industry: woocommerce fallback' );
check( 'custom', $d( 'سایت من' ), 'industry: unknown' );
check( 19, count( I::all() ), 'industry: registry size' );

$missing = array();
foreach ( I::for_ui() as $key => $industry ) {
	foreach ( array( 'offer', 'audience', 'problems', 'forbidden', 'scope', 'link_when', 'anchors', 'cta', 'cta_when' ) as $field ) {
		if ( empty( $industry['ex'][ $field ] ) ) {
			$missing[] = "$key.$field";
		}
	}
}
check( array(), $missing, 'industry: every industry has every example (custom falls back to the default)' );
