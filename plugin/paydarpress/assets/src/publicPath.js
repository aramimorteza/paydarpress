/* global __webpack_public_path__:writable */
/**
 * Page chunks are loaded from the plugin's build folder (set before any other module runs).
 */
if ( window.paydarpress && window.paydarpress.buildUrl ) {
	// eslint-disable-next-line camelcase, no-undef
	__webpack_public_path__ = window.paydarpress.buildUrl;
}
