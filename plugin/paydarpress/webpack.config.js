/**
 * @wordpress/scripts config plus one shared chunk: code used by two or more pages
 * (components, helpers) is downloaded once and cached, each page chunk keeps only its own code.
 */
const defaults = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaults,
	optimization: {
		...defaults.optimization,
		splitChunks: {
			...defaults.optimization.splitChunks,
			cacheGroups: {
				...defaults.optimization.splitChunks.cacheGroups,
				common: {
					name: 'common',
					chunks: 'async',
					minChunks: 2,
					minSize: 0,
					priority: -10,
					reuseExistingChunk: true,
				},
			},
		},
	},
};
