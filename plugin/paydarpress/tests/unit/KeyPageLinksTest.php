<?php
/**
 * Anchor classification tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Links\KeyPageLinks as K;

check( 'exact', K::classify( 'وکیل طلاق', 'وکیل طلاق', 'وکیل کرج' ), 'anchor: exact' );
check( 'exact', K::classify( 'وكيل  طلاق', 'وکیل طلاق', '' ), 'anchor: exact with arabic letters and spaces' );
check( 'partial', K::classify( 'بهترین وکیل طلاق', 'وکیل طلاق', '' ), 'anchor: partial contains main' );
check( 'partial', K::classify( 'وکیل برای طلاق توافقی', 'وکیل طلاق', '' ), 'anchor: partial shares words' );
check( 'branded', K::classify( 'مشاوره با وکیل کرج', 'وکیل طلاق', 'وکیل کرج' ), 'anchor: branded' );
check( 'generic', K::classify( 'اینجا کلیک کنید', 'وکیل طلاق', '' ), 'anchor: generic' );
check( 'other', K::classify( 'تماس با ما', 'وکیل طلاق', '' ), 'anchor: other' );
check( 'other', K::classify( '', 'وکیل طلاق', '' ), 'anchor: empty' );
