import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { get, post, errorMessage } from './api';

const POLL_MS = 2500;

/**
 * Scan state with polling. While a scan runs and the user can manage, each poll also asks the
 * server to process due queue items (tick=1), so scans finish even without a working WP-Cron.
 */
export default function useScan() {
	const canManage = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );
	const [ scan, setScan ] = useState( null );
	const [ loaded, setLoaded ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ starting, setStarting ] = useState( false );
	const timer = useRef( null );

	const refresh = useCallback(
		( tick = false ) =>
			get( '/scan/status', tick && canManage ? { tick: 1 } : {} )
				.then( ( data ) => {
					setScan( data.scan );
					setLoaded( true );
					setError( '' );
					return data.scan;
				} )
				.catch( ( e ) => {
					setLoaded( true );
					setError( errorMessage( e ) );
					return null;
				} ),
		[ canManage ]
	);

	useEffect( () => {
		refresh( false );
	}, [ refresh ] );

	const running = !! scan && 'running' === scan.status;

	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		let cancelled = false;
		const loop = () => {
			refresh( true ).then( () => {
				if ( ! cancelled ) {
					timer.current = setTimeout( loop, POLL_MS );
				}
			} );
		};
		timer.current = setTimeout( loop, POLL_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer.current );
		};
	}, [ running, refresh ] );

	const start = () => {
		setStarting( true );
		setError( '' );
		return post( '/scan' )
			.then( ( data ) => setScan( data.scan ) )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setStarting( false ) );
	};

	const cancel = () => post( '/scan/cancel' ).then( ( data ) => setScan( data.scan ) );

	return { scan, loaded, running, error, starting, start, cancel, canManage };
}
