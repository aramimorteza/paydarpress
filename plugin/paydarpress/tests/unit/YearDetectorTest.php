<?php
/**
 * YearDetector tests. Reference date: 2026-11-12 (Jalali 1405).
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Text\YearDetector;

$now = gmmktime( 12, 0, 0, 11, 12, 2026 );
$d   = new YearDetector( $now );

check( 1405, $d->current_jalali(), 'jalali year in November' );
check( 1404, YearDetector::jalali_year( gmmktime( 12, 0, 0, 3, 20, 2026 ) ), 'before nowruz' );
check( 1405, YearDetector::jalali_year( gmmktime( 12, 0, 0, 3, 21, 2026 ) ), 'on nowruz' );

check( array( 1402 ), $d->detect( 'نفقه زن در سال ۱۴۰۲ چقدر است؟', '' ), 'title year counts' );
check( array( 1402 ), $d->detect( 'نفقه زن', 'میزان نفقه در سال 1402 افزایش یافت.' ), 'body year with context' );
check( array(), $d->detect( 'تاریخچه', 'این قانون در 1392 تصویب و در دادگاه اجرا شد.' ), 'body year without context ignored' );
check( array( 1401, 1405 ), $d->detect( 'مهریه ۱۴۰۵', 'نرخ تورم 1401 مبنای محاسبه است.' ), 'mixed' );
check( array(), $d->detect( 'شماره پرونده 1398/12', 'کد 14021234' ), 'not a standalone year' );
check( array( 2024 ), $d->detect( 'Best practices for 2024', '' ), 'gregorian title' );
check( array(), $d->detect( 'مقاله', 'تلفن 2109 را بگیرید' ), 'out of range number' );

check( array(), $d->detect( 'اقسام طلاق', '(ماده 29 قانون حمایت از خانواده مصوب (1391 ) تا قبل از صدور' ), 'law date with parenthesis ignored' );
check( array(), $d->detect( 'اجرای محکومیت', 'قانون نحوه اجرای محکومیتهای مالی مصوب 1393 و زندانی کردن' ), 'law date ignored' );
check( array(), $d->detect( 'قانون حمایت خانواده مصوب ۱۳۹۱', '' ), 'law date in title ignored' );
check( array( 1402 ), $d->detect( 'نفقه', 'مبلغ نفقه در سال 1402 طبق قانون' ), 'year before law word still counts' );

check( array(), $d->detect( 'مقاله آزمایشی شماره 1395', '' ), 'number after شماره ignored' );
check( array(), $d->detect( 'خرید زمین ۱۴۰۰ متری', '' ), 'quantity with unit ignored' );
check( array(), $d->detect( 'مبلغ', 'هزینه آن 1400 تومان در سال است' ), 'price ignored' );
check( array( 1403 ), $d->detect( 'قیمت کابینت در سال ۱۴۰۳', '' ), 'real year in title still counts' );

check( array( 1402, 2024 ), $d->outdated( array( 1402, 1405, 2024, 2026 ) ), 'outdated filter' );
