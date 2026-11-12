import { useEffect, useState } from '@wordpress/element';
import { post } from './api';

/**
 * Simple / pro mode (architecture 12.7), stored per user in `paydarpress_ui_mode`.
 * Simple: help boxes open, advanced options hidden. Pro: help boxes collapsed, everything shown.
 */
const config = window.paydarpress || {};
let mode = 'pro' === config.uiMode ? 'pro' : 'simple';
const listeners = new Set();

export function getMode() {
	return mode;
}

/**
 * Changes the mode for this user and notifies subscribed components.
 *
 * @param {string} next 'simple' or 'pro'.
 */
export function setMode( next ) {
	if ( next === mode ) {
		return;
	}
	mode = next;
	config.uiMode = next;
	listeners.forEach( ( fn ) => fn( next ) );
	post( '/ui-mode', { mode: next } ).catch( () => {} );
}

/**
 * Current mode; re-renders when it changes.
 */
export function useMode() {
	const [ value, setValue ] = useState( mode );
	useEffect( () => {
		listeners.add( setValue );
		return () => listeners.delete( setValue );
	}, [] );
	return value;
}
