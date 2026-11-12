/**
 * Minimal `react-jsx-runtime` shim for WordPress 6.5.
 *
 * WordPress 6.6+ registers this script itself; AdminPage only registers this file when the
 * handle is missing. Builds made with @wordpress/scripts use the automatic JSX runtime,
 * which calls jsx()/jsxs() with `children` inside props and the key as a third argument.
 */
( function ( React ) {
	if ( ! React || window.ReactJSXRuntime ) {
		return;
	}

	function jsx( type, props, key ) {
		var finalProps = props || {};

		if ( key !== undefined ) {
			finalProps = Object.assign( {}, finalProps, { key: key } );
		}

		return React.createElement( type, finalProps );
	}

	window.ReactJSXRuntime = {
		Fragment: React.Fragment,
		jsx: jsx,
		jsxs: jsx,
	};
} )( window.React );
