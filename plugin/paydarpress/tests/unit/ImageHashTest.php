<?php
/**
 * dHash tests.
 *
 * @package PaydarPress
 */

use PaydarWP\PaydarPress\Images\ImageHash as H;

$grid = array();
for ( $y = 0; $y < 8; $y++ ) {
	for ( $x = 0; $x < 9; $x++ ) {
		$grid[ $y ][ $x ] = 255 - $x * 20; // Brighter on the left: every bit 1.
	}
}
check( 'ffffffffffffffff', H::from_gray( $grid ), 'dhash: left brighter = all ones' );
$grid2 = $grid;
$grid2[0][0] = 0; // First bit flips.
check( '7fffffffffffffff', H::from_gray( $grid2 ), 'dhash: one changed pixel flips one bit' );
check( 1, H::distance( H::from_gray( $grid ), H::from_gray( $grid2 ) ), 'dhash: distance one' );
check( 64, H::distance( 'ffffffffffffffff', '0000000000000000' ), 'dhash: max distance' );
check( 64, H::distance( 'x', 'ffffffffffffffff' ), 'dhash: invalid hash is far' );
