import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl, Spinner, Notice } from '@wordpress/components';
import FindingCard from './FindingCard';
import EmptyState from './EmptyState';
import { get, errorMessage } from '../lib/api';
import { num } from '../lib/format';

const STATES = [
	{ value: 'open', label: 'باز' },
	{ value: 'reviewed', label: 'بررسی‌شده' },
	{ value: 'ignored', label: 'نادیده گرفته‌شده' },
	{ value: 'resolved', label: 'رفع‌شده' },
];

const SEVERITIES = [
	{ value: '', label: 'همه شدت‌ها' },
	{ value: 'high', label: 'شدت بالا' },
	{ value: 'medium', label: 'شدت متوسط' },
	{ value: 'low', label: 'شدت کم' },
];

/**
 * Paginated findings with group tabs and filters.
 *
 * @param {Object} props
 * @param {string} props.fixedGroup Show only this group (no group tabs).
 * @param {string} props.initialCode Optional code filter (from dashboard links).
 */
export default function FindingsList( { fixedGroup = '', initialCode = '' } ) {
	const [ group, setGroup ] = useState( fixedGroup );
	const [ code, setCode ] = useState( initialCode );
	const [ severity, setSeverity ] = useState( '' );
	const [ state, setState ] = useState( 'open' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		get( '/dashboard' ).then( ( d ) => setStats( d.stats ) ).catch( () => {} );
	}, [] );

	useEffect( () => {
		setLoading( true );
		get( '/findings', { group: code ? '' : group, code, severity, state, page, per_page: 20 } )
			.then( ( result ) => {
				setData( result );
				setError( '' );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setLoading( false ) );
	}, [ group, code, severity, state, page ] );

	const groups = ( data && data.groups ) || {};
	const byGroup = ( stats && stats.by_group ) || {};
	const totalOpen = Object.values( byGroup ).reduce( ( a, b ) => a + b, 0 );

	const reset = ( fn ) => ( value ) => {
		fn( value );
		setPage( 1 );
	};

	return (
		<div className="pp-findings">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

			{ ! fixedGroup && (
				<div className="pp-tabs" role="tablist">
					<button type="button" role="tab" aria-selected={ '' === group && ! code } className={ `pp-tab${ '' === group && ! code ? ' is-active' : '' }` } onClick={ () => { setCode( '' ); reset( setGroup )( '' ); } }>
						همه <span className="pp-tab__count">{ num( totalOpen ) }</span>
					</button>
					{ Object.keys( groups ).map( ( key ) => (
						<button key={ key } type="button" role="tab" aria-selected={ group === key && ! code } className={ `pp-tab${ group === key && ! code ? ' is-active' : '' }` } onClick={ () => { setCode( '' ); reset( setGroup )( key ); } }>
							{ groups[ key ] } <span className="pp-tab__count">{ num( byGroup[ key ] ) }</span>
						</button>
					) ) }
				</div>
			) }

			<div className="pp-toolbar">
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label="وضعیت" hideLabelFromVision value={ state } options={ STATES } onChange={ reset( setState ) } />
				<SelectControl __nextHasNoMarginBottom __next40pxDefaultSize label="شدت" hideLabelFromVision value={ severity } options={ SEVERITIES } onChange={ reset( setSeverity ) } />
				{ code && (
					<Button variant="tertiary" onClick={ () => reset( setCode )( '' ) }>
						حذف فیلتر «{ data && data.items[ 0 ] ? data.items[ 0 ].title : code }»
					</Button>
				) }
				{ loading && <Spinner /> }
				{ data && <span className="pp-muted">{ num( data.total ) } مورد</span> }
			</div>

			{ data && 0 === data.items.length && ! loading && (
				<div className="pp-card">
					<EmptyState
						title={ 'open' === state ? 'مشکلی در این بخش پیدا نشد' : 'موردی با این وضعیت نیست' }
						text={ 'open' === state ? 'اگر اخیراً تغییری داده‌اید، پس از چند دقیقه یا با اسکن مجدد بروز می‌شود.' : '' }
					/>
				</div>
			) }

			<div className="pp-finding-list">
				{ data && data.items.map( ( finding ) => <FindingCard key={ finding.id } finding={ finding } onChange={ setStats } /> ) }
			</div>

			{ data && data.total_pages > 1 && (
				<div className="pp-pagination">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
					<span>صفحه { num( page ) } از { num( data.total_pages ) }</span>
					<Button variant="secondary" disabled={ page >= data.total_pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
				</div>
			) }
		</div>
	);
}
