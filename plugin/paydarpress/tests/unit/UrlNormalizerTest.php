<?php
/**
 * UrlNormalizer tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Links\UrlNormalizer;

$u    = new UrlNormalizer( 'https://vakilkaraj.net' );
$base = 'https://vakilkaraj.net/blog/consensual-divorce/';

check( 'https://vakilkaraj.net/alimony/', $u->absolute( '/alimony/', $base ), 'root-relative' );
check( 'https://vakilkaraj.net/blog/consensual-divorce/x/', $u->absolute( 'x/', $base ), 'relative' );
check( 'https://vakilkaraj.net/blog/y', $u->absolute( '../y', $base ), 'dot segments' );
check( 'https://cdn.example.com/a', $u->absolute( '//cdn.example.com/a', $base ), 'protocol-relative' );
check( null, $u->absolute( 'mailto:a@b.c', $base ), 'mailto rejected' );
check( 'https://vakilkaraj.net/a?b=1&c=2', $u->absolute( 'https://vakilkaraj.net/a?b=1&amp;c=2', $base ), 'entity decoded' );

check( true, $u->is_internal( 'https://www.vakilkaraj.net/x' ), 'www is internal' );
check( true, $u->is_internal( 'http://vakilkaraj.net/x' ), 'http is internal' );
check( false, $u->is_internal( 'https://google.com/' ), 'external' );

check( true, $u->is_asset( 'https://vakilkaraj.net/wp-content/uploads/2023/a.jpg' ), 'upload is asset' );
check( true, $u->is_asset( 'https://vakilkaraj.net/files/doc.PDF' ), 'pdf is asset' );
check( false, $u->is_asset( 'https://vakilkaraj.net/alimony/' ), 'page not asset' );

check( true, $u->is_special_route( 'https://vakilkaraj.net/feed/' ), 'feed special' );
check( true, $u->is_special_route( 'https://vakilkaraj.net/blog/page/2/' ), 'pagination special' );
check( true, $u->is_special_route( 'https://vakilkaraj.net/?s=طلاق' ), 'search special' );
check( true, $u->is_special_route( 'https://vakilkaraj.net/' ), 'home special' );
check( false, $u->is_special_route( 'https://vakilkaraj.net/alimony/' ), 'post not special' );

$encoded = 'https://vakilkaraj.net/%D9%86%D9%81%D9%82%D9%87/';
check( 'vakilkaraj.net/نفقه', $u->key( $encoded ), 'key decodes persian slug' );
check( $u->key( 'https://www.vakilkaraj.net/نفقه' ), $u->key( $encoded ), 'key equal encoded/decoded/www/slash' );
check( 'vakilkaraj.net/?p=12', $u->key( 'https://vakilkaraj.net/?p=12&utm_source=x' ), 'key keeps p param' );
check( 'vakilkaraj.net/alimony', $u->key( 'https://vakilkaraj.net/alimony/?utm_source=x#top' ), 'key drops tracking and fragment' );

$sub = new UrlNormalizer( 'https://example.com/site' );
check( true, $sub->is_special_route( 'https://example.com/site/' ), 'subfolder home special' );
check( false, $sub->is_special_route( 'https://example.com/site/about/' ), 'subfolder page' );
