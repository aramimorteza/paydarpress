<?php
/**
 * Change verdict tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Strategy\ChangeLog as C;

$b = array( 'clicks' => 20, 'impressions' => 1000, 'position' => 9.0 );
check( 'better', C::verdict( $b, array( 'clicks' => 40, 'impressions' => 1500, 'position' => 6.5 ) ), 'verdict: more clicks, better position' );
check( 'worse', C::verdict( $b, array( 'clicks' => 10, 'impressions' => 900, 'position' => 12.0 ) ), 'verdict: fewer clicks, worse position' );
check( 'same', C::verdict( $b, array( 'clicks' => 21, 'impressions' => 1000, 'position' => 9.4 ) ), 'verdict: small changes' );
check( 'same', C::verdict( $b, array( 'clicks' => 40, 'impressions' => 1500, 'position' => 11.0 ) ), 'verdict: mixed signals' );
check( 'no_data', C::verdict( null, $b ), 'verdict: nothing before' );
check( 'no_data', C::verdict( array( 'clicks' => 0, 'impressions' => 5, 'position' => 40 ), array( 'clicks' => 1, 'impressions' => 8, 'position' => 30 ) ), 'verdict: too few impressions' );
