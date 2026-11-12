import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const config = window.paydarpress || {};
const base = `/${ config.restNamespace || 'paydarpress/v1' }`;

/**
 * GET request to a PaydarPress endpoint.
 *
 * @param {string} path  Path after the namespace, e.g. '/inventory'.
 * @param {Object} query Query args.
 */
export function get( path, query = {} ) {
	return apiFetch( { path: addQueryArgs( base + path, query ) } );
}

/**
 * POST request to a PaydarPress endpoint.
 *
 * @param {string} path Path after the namespace.
 * @param {Object} data Body.
 */
export function post( path, data = {} ) {
	return apiFetch( { path: base + path, method: 'POST', data } );
}

/**
 * User-facing error message from an apiFetch error.
 *
 * @param {Object} error Error.
 */
export function errorMessage( error ) {
	if ( error && error.message ) {
		return error.message;
	}
	return 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.';
}
