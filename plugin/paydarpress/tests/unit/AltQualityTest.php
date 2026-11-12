<?php
/**
 * AltQuality tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Audit\AltQuality as A;

check( true, A::is_generic( 'IMG_2034' ), 'camera name' );
check( true, A::is_generic( 'DSC-0012' ), 'dsc' );
check( true, A::is_generic( 'تصویر' ), 'persian generic word' );
check( true, A::is_generic( 'Image' ), 'english generic word' );
check( true, A::is_generic( '12345' ), 'numbers only' );
check( true, A::is_generic( 'talagh-tavafoghi', 'https://x.ir/wp-content/uploads/2023/talagh-tavafoghi-1024x576.jpg' ), 'equals file name' );
check( true, A::is_generic( 'WhatsApp Image 2024-01-02' ), 'whatsapp' );
check( false, A::is_generic( 'مدارک لازم برای طلاق توافقی' ), 'descriptive persian' );
check( false, A::is_generic( 'وکیل طلاق در کرج', 'https://x.ir/a.jpg' ), 'descriptive with src' );
check( false, A::is_generic( '' ), 'empty is not generic' );

use PaydarWP\PaydarPress\Audit\AuditEngine as E;

check( null, E::health_score( 10.0, 0 ), 'health: no content' );
check( 100, E::health_score( 0.0, 20 ), 'health: no findings' );
check( 73, E::health_score( 25.0, 10 ), 'health: avg 2.5' );
check( 21, E::health_score( 175.0, 14 ), 'health: vakilkaraj sample' );
check( 'merge', E::refresh_action( 10, array( 'DUPLICATE_FOCUS_KEYWORD' ) ), 'refresh: duplicate keyword means merge' );
check( 'rewrite', E::refresh_action( 55, array() ), 'refresh: rewrite' );
check( 'light', E::refresh_action( 20, array() ), 'refresh: light' );
check( 'none', E::refresh_action( 15, array() ), 'refresh: none' );
