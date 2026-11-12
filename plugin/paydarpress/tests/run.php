<?php
/**
 * Minimal test runner for WordPress-independent units.
 * Usage: php tests/run.php
 *
 * @package PaydarPress
 */

declare(strict_types=1);

// Class files exit without ABSPATH (direct-access guard); the tests run outside WordPress.
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../vendor/autoload.php';

$GLOBALS['pp_tests'] = array( 'pass' => 0, 'fail' => 0 );

/**
 * Asserts equality.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $name     Test name.
 */
function check( $expected, $actual, string $name ): void {
	if ( $expected === $actual ) {
		++$GLOBALS['pp_tests']['pass'];
		return;
	}
	++$GLOBALS['pp_tests']['fail'];
	echo "FAIL: {$name}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

foreach ( glob( __DIR__ . '/unit/*Test.php' ) as $file ) {
	require $file;
}

printf( "\n%d passed, %d failed\n", $GLOBALS['pp_tests']['pass'], $GLOBALS['pp_tests']['fail'] );
exit( $GLOBALS['pp_tests']['fail'] > 0 ? 1 : 0 );
