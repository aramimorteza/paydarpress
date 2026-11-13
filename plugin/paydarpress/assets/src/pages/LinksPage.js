import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { get, post, errorMessage } from '../lib/api';
import { num, ago } from '../lib/format';

const config = window.paydarpress || {};

const TABS = [
	{ key: 'pending', label: 'در انتظار تأیید' },
	{ key: 'approved', label: 'منتظر انتشار مقصد' },
	{ key: 'failed', label: 'نیاز به بررسی' },
	{ key: 'applied', label: 'درج‌شده' },
	{ key: 'rejected', label: 'ردشده' },
];

const ORIGIN = { contentmaster: 'هوش مصنوعی', paydarpress: 'پایدارپرس' };

function initialStatus() {
	const m = window.location.hash.match( /[?&]status=([a-z]+)/ );
	return m ? m[ 1 ] : 'pending';
}

/**
 * Internal link approval queue.
 */
export default function LinksPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ status, setStatus ] = useState( initialStatus );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ bulkBusy, setBulkBusy ] = useState( false );
	const [ message, setMessage ] = useState( null );

	const load = () => {
		get( '/links', { status, page, per_page: 20 } )
			.then( ( d ) => {
				setData( d );
				setSelected( [] );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	};

	useEffect( () => {
		setData( null );
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status, page ] );

	const replace = ( item ) => {
		setData( ( d ) => ( { ...d, items: d.items.map( ( i ) => ( i.id === item.id ? item : i ) ) } ) );
	};

	const bulk = ( action ) => {
		setBulkBusy( true );
		post( '/links/bulk', { ids: selected, action } )
			.then( ( r ) => {
				const applied = r.items.filter( ( i ) => 'applied' === i.status ).length;
				const failed = r.items.filter( ( i ) => 'failed' === i.status ).length;
				setMessage( 'apply' === action ? `${ num( applied ) } لینک درج شد${ failed ? `، ${ num( failed ) } مورد نیاز به بررسی دارد` : '' }.` : `${ num( r.items.length ) } پیشنهاد رد شد.` );
				load();
			} )
			.finally( () => setBulkBusy( false ) );
	};

	const selectable = data ? data.items.filter( ( i ) => 'pending' === i.status && i.found ).map( ( i ) => i.id ) : [];

	return (
		<Page title="صف لینک داخلی" helpKey="links" actions={ canManage && <Button variant="secondary" href="#/orphans">پیشنهاد برای محتوای یتیم</Button> }>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ message && <Notice status="success" onRemove={ () => setMessage( null ) }>{ message }</Notice> }

			<div className="pp-tabs" role="tablist" aria-label="وضعیت پیشنهاد">
				{ TABS.map( ( t ) => (
					<button
						key={ t.key }
						type="button"
						role="tab"
						aria-selected={ status === t.key }
						className={ `pp-tab${ status === t.key ? ' is-active' : '' }` }
						onClick={ () => {
							setStatus( t.key );
							setPage( 1 );
							window.history.replaceState( null, '', `#/links?status=${ t.key }` );
						} }
					>
						{ t.label }
						{ data && <span className="pp-tab__count">{ num( data.counts[ t.key ] ) }</span> }
					</button>
				) ) }
			</div>

			{ ! data && ! error && <Spinner /> }

			{ data && 0 === data.items.length && (
				<div className="pp-card">
					<EmptyState
						title={ 'pending' === status ? 'پیشنهادی در انتظار تأیید نیست' : 'موردی نیست' }
						text={ 'pending' === status ? 'پیشنهادها از پاسخ‌های هوش مصنوعی (لینک از مقالات قدیمی به مقاله جدید) و از «محتوای یتیم» اینجا می‌آیند.' : '' }
						action={ 'pending' === status && canManage && <a className="button button-primary" href="#/orphans">ساخت پیشنهاد برای محتوای یتیم</a> }
					/>
				</div>
			) }

			{ data && data.items.length > 0 && 'pending' === status && canManage && selectable.length > 0 && (
				<div className="pp-bulk">
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ `انتخاب همه (${ num( selectable.length ) })` }
						checked={ selected.length === selectable.length }
						onChange={ ( on ) => setSelected( on ? selectable : [] ) }
					/>
					<Button variant="primary" disabled={ ! selected.length || bulkBusy } isBusy={ bulkBusy } onClick={ () => bulk( 'apply' ) }>
						تأیید و درج { selected.length ? `(${ num( selected.length ) })` : '' }
					</Button>
					<Button variant="tertiary" disabled={ ! selected.length || bulkBusy } onClick={ () => bulk( 'reject' ) }>رد</Button>
				</div>
			) }

			<div className="pp-link-list">
				{ data && data.items.map( ( item ) => (
					<LinkCard
						key={ item.id }
						item={ item }
						canManage={ canManage }
						checked={ selected.includes( item.id ) }
						onCheck={ ( on ) => setSelected( on ? [ ...selected, item.id ] : selected.filter( ( i ) => i !== item.id ) ) }
						onChange={ replace }
					/>
				) ) }
			</div>

			{ data && data.total_pages > 1 && (
				<div className="pp-pagination">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
					<span>صفحه { num( page ) } از { num( data.total_pages ) }</span>
					<Button variant="secondary" disabled={ page >= data.total_pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
				</div>
			) }
		</Page>
	);
}

/**
 * One suggestion.
 *
 * @param {Object}   props
 * @param {Object}   props.item      Item.
 * @param {boolean}  props.canManage Can act.
 * @param {boolean}  props.checked   Selected.
 * @param {Function} props.onCheck   Selection change.
 * @param {Function} props.onChange  Called with the updated item.
 */
function LinkCard( { item, canManage, checked, onCheck, onChange } ) {
	const [ busy, setBusy ] = useState( false );
	const [ editing, setEditing ] = useState( false );
	const [ anchor, setAnchor ] = useState( item.anchor );

	const act = ( action ) => {
		setBusy( true );
		post( `/links/${ item.id }/${ action }` )
			.then( onChange )
			.finally( () => setBusy( false ) );
	};

	const saveAnchor = () => {
		setBusy( true );
		post( `/links/${ item.id }/anchor`, { anchor } )
			.then( ( i ) => {
				onChange( i );
				setEditing( false );
			} )
			.finally( () => setBusy( false ) );
	};

	const open = [ 'pending', 'failed' ].includes( item.status );

	return (
		<div className={ `pp-card pp-link-card is-${ item.status }` }>
			<div className="pp-link-card__head">
				{ canManage && 'pending' === item.status && item.found && (
					<CheckboxControl __nextHasNoMarginBottom label="انتخاب" hideLabelFromVision checked={ checked } onChange={ onCheck } />
				) }
				<div className="pp-link-card__route">
					<a href={ item.source.edit_url }>{ item.source.title }</a>
					<span className="pp-link-card__arrow" aria-hidden="true">←</span>
					<a href={ item.target.url } target="_blank" rel="noreferrer">{ item.target.title }</a>
					{ 'publish' !== item.target.status && <span className="pp-badge pp-badge--warning">مقصد منتشر نشده</span> }
				</div>
				<span className="pp-muted pp-link-card__origin">{ ORIGIN[ item.origin ] || item.origin } · { ago( item.updated_at ) }</span>
			</div>

			{ open && item.context && (
				<p className="pp-link-card__context">
					{ item.context.before } <mark>{ item.context.match }</mark> { item.context.after }
				</p>
			) }
			{ open && ! item.found && (
				<p className="pp-missing">
					متن لنگر «{ item.anchor }» در متن نوشته مبدأ پیدا نشد. یک عبارت از همان نوشته را بنویسید که به موضوع مقصد اشاره دارد.
					{ item.sentence && <span className="pp-muted"> جمله پیشنهادی: «{ item.sentence }»</span> }
				</p>
			) }
			{ ! open && <p className="pp-link-card__anchor">متن لنگر: «{ item.anchor }»</p> }
			{ item.note && <p className={ 'failed' === item.status ? 'pp-missing' : 'pp-muted' }>{ item.note }</p> }

			{ editing && (
				<div className="pp-link-card__edit">
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label="متن لنگر (عینا از متن نوشته مبدأ)" value={ anchor } onChange={ setAnchor } />
					<Button variant="primary" onClick={ saveAnchor } isBusy={ busy } disabled={ busy || ! anchor.trim() }>بررسی</Button>
					<Button variant="tertiary" onClick={ () => setEditing( false ) }>انصراف</Button>
				</div>
			) }

			{ canManage && ! editing && (
				<div className="pp-link-card__actions">
					{ open && item.found && ! item.source.builder && <Button variant="primary" size="small" onClick={ () => act( 'apply' ) } isBusy={ busy } disabled={ busy }>تأیید و درج لینک</Button> }
					{ open && <Button variant="secondary" size="small" onClick={ () => setEditing( true ) } disabled={ busy }>ویرایش متن لنگر</Button> }
					{ 'failed' === item.status && <Button variant="tertiary" size="small" onClick={ () => act( 'done' ) } disabled={ busy }>دستی انجام شد</Button> }
					{ [ 'pending', 'failed', 'approved' ].includes( item.status ) && <Button variant="tertiary" size="small" onClick={ () => act( 'reject' ) } disabled={ busy }>رد</Button> }
					{ 'rejected' === item.status && <Button variant="tertiary" size="small" onClick={ () => act( 'restore' ) } disabled={ busy }>بازگرداندن به صف</Button> }
					{ item.can_undo && <Button variant="link" isDestructive size="small" onClick={ () => act( 'undo' ) } disabled={ busy }>برداشتن لینک</Button> }
					{ 'applied' === item.status && <a href={ item.source.url } target="_blank" rel="noreferrer">مشاهده نوشته</a> }
				</div>
			) }
		</div>
	);
}
