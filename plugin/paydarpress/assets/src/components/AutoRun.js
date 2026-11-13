import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import InlineImport from './InlineImport';

/**
 * Polls a job until it leaves queued/running. One request at a time: the poll request may run
 * the job itself (hosts without WP-Cron), which takes as long as the AI service needs.
 *
 * @param {Object}   job      Job.
 * @param {Function} setJob   Setter.
 * @param {Function} setError Error setter.
 */
export function useJobPolling( job, setJob, setError ) {
	const alive = useRef( true );
	useEffect( () => {
		alive.current = true;
		return () => {
			alive.current = false;
		};
	}, [] );

	const failures = useRef( 0 );
	const active = job && ( 'queued' === job.status || 'running' === job.status );
	const id = job ? job.id : 0;

	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const timer = setTimeout( () => {
			get( `/ai/jobs/${ id }` )
				.then( ( j ) => {
					failures.current = 0;
					if ( alive.current ) {
						setJob( j );
					}
				} )
				.catch( ( e ) => {
					// A proxy may cut the request that runs the job; the job itself goes on.
					failures.current += 1;
					if ( ! alive.current ) {
						return;
					}
					if ( failures.current >= 5 ) {
						setError( errorMessage( e ) );
					} else {
						setJob( { ...job } );
					}
				} );
		}, 3000 );
		return () => clearTimeout( timer );
		// Re-run after every state change of the job (each response schedules the next poll).
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ job ] );
}

/**
 * Elapsed seconds since a UTC MySQL datetime, ticking every second.
 *
 * @param {string}  since  Start (UTC).
 * @param {boolean} active Whether to tick.
 */
function useElapsed( since, active ) {
	const [ now, setNow ] = useState( Date.now() );
	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const t = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( t );
	}, [ active ] );
	if ( ! since ) {
		return 0;
	}
	return Math.max( 0, Math.round( ( now - Date.parse( since ) ) / 1000 ) );
}

/**
 * Job progress line.
 *
 * @param {Object} props
 * @param {Object} props.job Job.
 */
export function JobProgress( { job } ) {
	const running = 'queued' === job.status || 'running' === job.status;
	const seconds = useElapsed( job.created_at, running );
	if ( ! running ) {
		return null;
	}
	return (
		<div className="pp-auto-progress" role="status">
			<Spinner />
			<div>
				<strong>{ 'queued' === job.status ? 'در صف…' : 'هوش مصنوعی در حال نوشتن است…' }</strong> <span className="pp-muted">{ num( seconds ) } ثانیه</span>
				<div className="pp-muted">مقاله کامل معمولاً ۱ تا ۴ دقیقه طول می‌کشد. می‌توانید این پنجره را ببندید؛ کار ادامه پیدا می‌کند و نتیجه در «تولید محتوا ← تولید خودکار» و «امروز» منتظر بررسی می‌ماند.</div>
			</div>
		</div>
	);
}

/**
 * "Build automatically" block of the AI dialog.
 *
 * @param {Object} props
 * @param {Object} props.prompt { task, args }.
 * @param {Object} props.cost   ai_cost from /prompt.
 */
export default function AutoRun( { prompt, cost } ) {
	const [ job, setJob ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ closed, setClosed ] = useState( '' );

	useJobPolling( job, setJob, setError );

	const start = () => {
		setBusy( true );
		setError( '' );
		setClosed( '' );
		post( '/ai/run', prompt )
			.then( setJob )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const retry = () => {
		setBusy( true );
		setError( '' );
		post( `/ai/jobs/${ job.id }/retry` )
			.then( setJob )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const month = cost && cost.month;
	const limits = cost && cost.limits;
	const usage = month && limits && (
		<span className="pp-muted">
			{ cost.article
				? `این کار ۱ مقاله از سهمیه ماهانه مصرف می‌کند (${ num( month.articles ) } از ${ num( limits.articles ) } مصرف شده).`
				: `این کار ۱ درخواست از سهمیه ماهانه مصرف می‌کند (${ num( month.requests ) } از ${ num( limits.requests ) } مصرف شده).` }
		</span>
	);

	return (
		<div className="pp-auto-run">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }

			{ ! job && (
				<div className="pp-ai-modal__row">
					<Button variant="primary" icon="controls-play" onClick={ start } isBusy={ busy } disabled={ busy }>ساخت خودکار</Button>
					{ usage }
				</div>
			) }

			{ job && <JobProgress job={ job } /> }

			{ job && 'failed' === job.status && (
				<>
					<Notice status="error" isDismissible={ false }>{ job.error }</Notice>
					<div className="pp-ai-modal__row">
						<Button variant="secondary" onClick={ retry } isBusy={ busy } disabled={ busy }>دوباره</Button>
						<span className="pp-muted">هر تلاش یک درخواست از سهمیه حساب می‌شود.</span>
					</div>
				</>
			) }

			{ job && 'ready' === job.status && 'discarded' !== closed && (
				<>
					<p className="pp-muted">
						آماده شد ({ num( job.seconds || 0 ) } ثانیه، { num( job.output_tokens ) } توکن خروجی). بررسی‌ها را ببینید و موارد درست را وارد کنید؛ مقاله‌ها فقط پیش‌نویس می‌شوند.
					</p>
					<InlineImport job={ job.id } onClosed={ setClosed } />
				</>
			) }

			{ 'discarded' === closed && (
				<div className="pp-ai-modal__row">
					<span className="pp-muted">پاسخ کنار گذاشته شد.</span>
					<Button variant="secondary" onClick={ () => setJob( null ) }>ساخت دوباره</Button>
				</div>
			) }
		</div>
	);
}
