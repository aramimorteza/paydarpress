<?php
/**
 * ContentMaster import parser tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Import\ImportParser as P;

$json = '{"schema":"paydarpress-import/1","generator":"ContentMaster 1.6","site":"vakilkaraj.net",'
	. '"topics":[{"title":"  مهریه   به نرخ روز چگونه محاسبه می‌شود؟ ","category":"خانواده","focus_keyword":"محاسبه مهریه","intent":"How-To","notes":"<b>x</b>"},{"title":""},"bad"],'
	. '"articles":[{"title":"نفقه زن","html":"<h1>نفقه زن</h1><p>متن</p>","seo":{"title":"t","description":"d","keyword":"نفقه"},"tags":["نفقه",["x"],"نفقه"],"faq":[{"question":"q1","answer":"a1"},{"q":"","a":"x"}],"featured_image":{"prompt":"Create","alt":"alt"},"reverse_links":[{"source_url":"https://vakilkaraj.net/a/","anchor":"نفقه"}]},{"title":"no html"}],'
	. '"link_suggestions":[{"source_url":"https://x/a","target_url":"https://x/b","anchor":"aa"},{"source_url":"https://x/a"}]}';

$r = P::parse( "این متن را بچسبانید:\n```json\n" . $json . "\n```\nقدم بعد: ..." );
check( 'ContentMaster 1.6', $r['meta']['generator'], 'parser: markdown block with chat text around' );
check( 1, count( $r['topics'] ), 'parser: empty and non-object topics dropped' );
check( 'مهریه به نرخ روز چگونه محاسبه می‌شود؟', $r['topics'][0]['title'], 'parser: whitespace collapsed' );
check( 'how-to', $r['topics'][0]['intent'], 'parser: intent normalised' );
check( 'x', $r['topics'][0]['notes'], 'parser: tags stripped from notes' );
check( 1, count( $r['articles'] ), 'parser: article without html dropped' );
check( '<p>متن</p>', $r['articles'][0]['html'], 'parser: leading h1 removed' );
check( array( 'نفقه' ), $r['articles'][0]['tags'], 'parser: tags unique, non-scalars dropped' );
check( 'd', $r['articles'][0]['seo']['meta_description'], 'parser: seo description alias' );
check( 'نفقه', $r['articles'][0]['seo']['focus_keyword'], 'parser: seo keyword alias' );
check( array( array( 'q' => 'q1', 'a' => 'a1' ) ), $r['articles'][0]['faq'], 'parser: faq aliases, empty dropped' );
check( 'alt', $r['articles'][0]['image_alt'], 'parser: image alt' );
check( 1, count( $r['articles'][0]['reverse_links'] ), 'parser: reverse links' );
check( 1, count( $r['link_suggestions'] ), 'parser: incomplete link suggestion dropped' );

$raw = P::parse( $json );
check( 1, count( $raw['topics'] ), 'parser: plain JSON' );

$errors = array();
foreach ( array(
	'not json at all'                                => 'no_json',
	'{"schema":"paydarpress-import/1","topics":[}'   => 'broken',
	'{"export_meta":{"generator":"PaydarPress 0.7"}}' => 'own_export',
	'{"schema":"other"}'                             => 'schema',
	'{"schema":"paydarpress-import/1","topics":[]}'  => 'empty',
) as $input => $name ) {
	try {
		P::parse( $input );
		$errors[ $name ] = 'no exception';
	} catch ( RuntimeException $e ) {
		$errors[ $name ] = 'ok';
	}
}
check( array( 'no_json' => 'ok', 'broken' => 'ok', 'own_export' => 'ok', 'schema' => 'ok', 'empty' => 'ok' ), $errors, 'parser: invalid inputs rejected' );

$many = array( 'schema' => 'paydarpress-import/1', 'topics' => array_fill( 0, 250, array( 'title' => 'a b c' ) ) );
$m    = P::parse( json_encode( $many ) );
check( 200, count( $m['topics'] ), 'parser: topic limit' );
check( array( 'limit:topics:200' ), $m['notices'], 'parser: limit notice' );

// Title similarity.
check( true, P::similar_titles( 'مهریه چیست؟', 'مهريه چيست' ), 'similar: arabic letters and punctuation' );
check( true, P::similar_titles( 'نحوه محاسبه مهریه به نرخ روز', 'محاسبه مهریه به نرخ روز' ), 'similar: word overlap' );
check( false, P::similar_titles( 'محاسبه مهریه به نرخ روز', 'محاسبه نفقه زن در سال جدید' ), 'similar: different topics' );
check( false, P::similar_titles( 'مهریه', 'نفقه' ), 'similar: short titles not fuzzy' );
