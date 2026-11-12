<?php
/**
 * Link inserter tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Links\LinkInserter as L;

$u = 'https://x.ir/mahrieh/';

$r = L::insert( '<p>برای محاسبه مهریه به نرخ روز ابتدا شاخص را بدانید.</p>', 'محاسبه مهریه', $u );
check( '<p>برای <a href="https://x.ir/mahrieh/">محاسبه مهریه</a> به نرخ روز ابتدا شاخص را بدانید.</p>', $r['html'], 'insert: basic' );

$r = L::insert( '<h2>محاسبه مهریه</h2><p>نکته: <a href="/a">محاسبه مهریه</a> و سپس محاسبه مهریه دقیق.</p>', 'محاسبه مهریه', $u );
check( '<h2>محاسبه مهریه</h2><p>نکته: <a href="/a">محاسبه مهریه</a> و سپس <a href="https://x.ir/mahrieh/">محاسبه مهریه</a> دقیق.</p>', $r['html'], 'insert: skips heading and existing link' );

$r = L::insert( '<p>مهريه زن و نفقه</p>', 'مهریه', $u );
check( '<p><a href="https://x.ir/mahrieh/">مهريه</a> زن و نفقه</p>', $r['html'], 'insert: arabic yeh variant, original text kept' );

check( null, L::insert( '<p>مهریه‌ای که تعیین شد</p>', 'مهریه', $u ), 'insert: no partial word (zwnj suffix)' );
check( null, L::insert( '<p>کارمهریه</p>', 'مهریه', $u ), 'insert: no partial word (prefix)' );

$r = L::insert( '<p>طلاق توافقی چگونه است</p>', 'طلاق‌توافقی', $u );
check( true, null !== $r, 'insert: half-space in phrase matches space in text' );

$r = L::insert( '<p>سال ۱۴۰۲ و قانون</p>', 'سال 1402', $u );
check( '<p><a href="https://x.ir/mahrieh/">سال ۱۴۰۲</a> و قانون</p>', $r['html'], 'insert: persian digits' );

check( 'x', null === L::insert( '<p>[caption id="1"]محاسبه مهریه[/caption]</p>', 'محاسبه مهریه', $u ) ? null : 'x', 'insert: text between caption shortcodes allowed' );
check( null, L::insert( '<p>[gallery title="محاسبه مهریه"]</p>', 'محاسبه مهریه', $u ), 'insert: not inside shortcode attributes' );
check( null, L::insert( '<img alt="محاسبه مهریه" src="a.jpg"><p class="محاسبه مهریه">x</p>', 'محاسبه مهریه', $u ), 'insert: never in attributes' );

$block = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">حضانت فرزند</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>قانون <strong>حضانت فرزند</strong> را بخوانید.</p>\n<!-- /wp:paragraph -->";
$r     = L::insert( $block, 'حضانت فرزند', $u );
check( true, str_contains( $r['html'], '<strong><a href="https://x.ir/mahrieh/">حضانت فرزند</a></strong>' ), 'insert: gutenberg paragraph, heading skipped' );
check( true, str_contains( $r['html'], '<!-- wp:heading -->' ), 'insert: block comments intact' );

$r = L::insert( '<p>CTA: Legal Advice now</p>', 'legal advice', $u );
check( '<p>CTA: <a href="https://x.ir/mahrieh/">Legal Advice</a> now</p>', $r['html'], 'insert: case-insensitive latin' );

check( null, L::insert( '<pre>محاسبه مهریه</pre><code>محاسبه مهریه</code>', 'محاسبه مهریه', $u ), 'insert: not in code' );
check( null, L::insert( '<p>x</p>', 'م', $u ), 'insert: too short phrase' );

// Undo.
$orig = '<p>برای محاسبه مهریه بخوانید.</p>';
$r    = L::insert( $orig, 'محاسبه مهریه', $u );
check( $orig, L::remove( $r['html'], $r['inserted'], $r['text'] ), 'remove: restores original' );
check( null, L::remove( '<p>edited by hand</p>', $r['inserted'], $r['text'] ), 'remove: null when link was edited away' );

// Context.
$c = L::context( '<p>مقدمه طولانی.</p><p>برای محاسبه مهریه به نرخ روز</p>', 'محاسبه مهریه', 20 );
check( array( 'before' => 'مقدمه طولانی. برای', 'match' => 'محاسبه مهریه', 'after' => 'به نرخ روز' ), $c, 'context: around match' );

// Persian ezafe after a word ending in ه.
$r = L::insert( '<p>هزینه‌ی وکیل طلاق در کرج</p>', 'هزینه وکیل طلاق', $u );
check( '<p><a href="https://x.ir/mahrieh/">هزینه‌ی وکیل طلاق</a> در کرج</p>', $r['html'], 'insert: ezafe with zwnj' );
$r = L::insert( '<p>هزینهٔ وکیل طلاق</p>', 'هزینه وکیل طلاق', $u );
check( true, null !== $r, 'insert: ezafe hamza' );
$r = L::insert( '<p>درباره خانه ی ما</p>', 'خانه', $u );
check( '<p>درباره <a href="https://x.ir/mahrieh/">خانه ی</a> ما</p>', $r['html'], 'insert: separated ezafe at phrase end' );
check( null, L::insert( '<p>خانه‌ها و خانه یک</p>', 'خانه بزرگ', $u ), 'insert: ezafe never swallows another word' );
check( null, L::insert( '<p>خانه‌ها</p>', 'خانه', $u ), 'insert: plural is not ezafe' );
