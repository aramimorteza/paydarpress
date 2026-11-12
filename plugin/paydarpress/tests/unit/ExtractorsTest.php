<?php
/**
 * HtmlExtractor and ElementorExtractor tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Extractors\ElementorExtractor;
use PaydarWP\PaydarPress\Extractors\HtmlExtractor;

$html = <<<HTML
<!-- wp:paragraph -->
<p>طلاق توافقی ساده‌ترین نوع جدایی است که با رضایت هر دو طرف انجام می‌شود.</p>
<!-- /wp:paragraph -->
<p>برای <a href="https://example.com/consultation">مشاوره حقوقی</a> تماس بگیرید.</p>
<h2>مراحل طلاق توافقی</h2>
<p>پاراگراف بعد از H2 نباید جزو مقدمه باشد.</p>
<h3>مدارک لازم</h3>
<figure><img class="wp-image-42" src="https://example.com/a.jpg" alt="مدارک طلاق"></figure>
<img src="https://example.com/b.jpg">
<img src="https://example.com/c.jpg" alt="">
<a href="#section">لینک داخلی صفحه</a>
<a href="tel:0912">تماس</a>
[contact-form-7 id="12"]
<script>var x = 1;</script>
HTML;

$r = ( new HtmlExtractor() )->extract( $html );

check( 'blocks', $r->source, 'html: source' );
check( array( array( 'level' => 2, 'text' => 'مراحل طلاق توافقی' ), array( 'level' => 3, 'text' => 'مدارک لازم' ) ), $r->headings, 'html: headings' );
check( array( array( 'url' => 'https://example.com/consultation', 'anchor' => 'مشاوره حقوقی' ) ), $r->links, 'html: links skip # and tel:' );
check( 3, count( $r->images ), 'html: image count' );
check( 42, $r->images[0]['attachment_id'], 'html: attachment id from class' );
check( 'مدارک طلاق', $r->images[0]['alt'], 'html: alt' );
check( null, $r->images[1]['alt'], 'html: missing alt is null' );
check( '', $r->images[2]['alt'], 'html: empty alt is empty string' );
check( 2, count( $r->intro_paragraphs ), 'html: intro paragraphs before first h2' );
check( false, str_contains( $r->text, 'var x' ), 'html: script removed' );
check( false, str_contains( $r->text, 'contact-form-7' ), 'html: shortcode removed' );
check( true, str_contains( $r->text, 'مراحل طلاق توافقی' ), 'html: text contains heading' );

$empty = ( new HtmlExtractor() )->extract( '<!-- wp:spacer /-->' );
check( '', $empty->text, 'html: empty content' );

$elementor = array(
	array(
		'elType'   => 'section',
		'elements' => array(
			array(
				'elType'   => 'column',
				'elements' => array(
					array(
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p>مقدمه کوتاه درباره مهریه.</p>' ),
					),
					array(
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => 'مهریه چیست؟' ),
					),
					array(
						'elType'     => 'widget',
						'widgetType' => 'image',
						'settings'   => array( 'image' => array( 'url' => 'https://example.com/m.jpg', 'id' => 7 ) ),
					),
					array(
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array( 'text' => 'رزرو مشاوره', 'link' => array( 'url' => '/booking/' ) ),
					),
					array(
						'elType'     => 'widget',
						'widgetType' => 'accordion',
						'settings'   => array( 'tabs' => array( array( 'tab_title' => 'سؤال اول', 'tab_content' => '<p>پاسخ <a href="/faq/">بیشتر</a></p>' ) ) ),
					),
					array(
						'elType'     => 'widget',
						'widgetType' => 'some-addon-box',
						'settings'   => array( 'title_text' => 'عنوان افزونه', 'link' => array( 'url' => 'https://other.com/' ) ),
					),
				),
			),
		),
	),
);

$e = ( new ElementorExtractor() )->extract( $elementor );

check( 'elementor', $e->source, 'elementor: source' );
check( array( array( 'level' => 2, 'text' => 'مهریه چیست؟' ), array( 'level' => 3, 'text' => 'سؤال اول' ) ), $e->headings, 'elementor: headings (default h2, accordion h3)' );
check( array( '/booking/', '/faq/', 'https://other.com/' ), array_column( $e->links, 'url' ), 'elementor: links from button, accordion, generic' );
check( 7, $e->images[0]['attachment_id'], 'elementor: image id' );
check( null, $e->images[0]['alt'], 'elementor: unknown alt is null' );
check( array( 'مقدمه کوتاه درباره مهریه.' ), $e->intro_paragraphs, 'elementor: intro paragraph' );
check( true, str_contains( $e->text, 'عنوان افزونه' ), 'elementor: generic widget text' );
check( null, ElementorExtractor::decode( '[]' ), 'elementor: empty data' );
check( null, ElementorExtractor::decode( 'not json' ), 'elementor: invalid json' );
