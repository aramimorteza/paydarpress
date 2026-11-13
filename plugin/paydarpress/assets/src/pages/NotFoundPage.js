import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import PostPicker from '../components/PostPicker';
import { Uploader } from './GscPage';
import { get, post, errorMessage } from '../lib/api';
import { ago, date, num } from '../lib/format';

const config = window.paydarpress || {};

const VIEWS = [
	{ key: 'todo', label: 'نیازمند تصمیم' },
	{ key: 'all', label: 'همه آدرس‌های گزارش' },
	{ key: 'done', label: 'انجام‌شده و رهاشده' },
];

/**
 * One 404 address with its suggested fix.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Row.
 * @param {boolean}  props.canEdit  Manage capability.
 * @param {Function} props.onChange Reload.
 */
function Row( { item, canEdit, onChange } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ picking, setPicking ] = useState( false );
	const [ message, setMessage ] = useState( '' );

	const act = ( op, target = 0 ) => {
		setBusy( true );
		setError( '' );
		post( `/gsc/notfound/${ item.id }/${ op }`, target ? { target } : {} )
			.then( ( r ) => {
				setMessage( r.message || '' );
				setTimeout( onChange, r.message ? 1800 : 0 );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const c = item.candidate;
	const open = 'open' === item.status;

	return (
		<tr>
			<td className="pp-nf__url">
				<a href={ item.url } target="_blank" rel="noreferrer" dir="ltr">{ item.path || '/' }</a>
				<div className="pp-muted">{ item.state_label }{ item.last_crawled ? ` · آخرین خزش گوگل: ${ date( item.last_crawled + 'T12:00:00Z' ) }` : '' }</div>
				{ item.note && ! open && <div className="pp-muted">{ item.note }</div> }
				{ error && <div className="pp-check pp-check--error">{ error }</div> }
				{ message && <div className="pp-check pp-check--info">{ message }</div> }
			</td>
			<td>
				{ item.valuable ? <span className="pp-badge pp-badge--warning">باارزش</span> : <span className="pp-muted">—</span> }
				<div className="pp-muted">
					{ item.clicks > 0 && `${ num( item.clicks ) } کلیک · ` }
					{ item.impressions > 0 && `${ num( item.impressions ) } نمایش · ` }
					{ item.inbound > 0 && `${ num( item.inbound ) } لینک داخلی` }
				</div>
			</td>
			<td>
				{ c ? (
					<>
						<div>{ c.reason_label }:</div>
						<a href={ c.url } target="_blank" rel="noreferrer">{ c.title }</a>
					</>
				) : <span className="pp-muted">{ 'notfound' === item.state ? 'جایگزین مشخصی پیدا نشد' : '' }</span> }
			</td>
			<td className="pp-actions-cell">
				{ canEdit && open && (
					<>
						{ c && 'trash' === c.reason && <Button variant="primary" size="small" onClick={ () => act( 'restore' ) } isBusy={ busy } disabled={ busy }>بازگردانی از زباله‌دان</Button> }
						{ c && 'trash' !== c.reason && 'publish' === c.status && <Button variant="primary" size="small" onClick={ () => act( 'redirect' ) } isBusy={ busy } disabled={ busy }>ریدایرکت ۳۰۱ به این مطلب</Button> }
						<Button variant="secondary" size="small" onClick={ () => setPicking( ! picking ) } disabled={ busy }>{ c ? 'مقصد دیگر' : 'ریدایرکت به…' }</Button>
						{ item.inbound > 0 && <Button variant="tertiary" size="small" href="#/audit?code=BROKEN_INTERNAL_LINK">اصلاح لینک‌ها</Button> }
						<Button variant="tertiary" size="small" onClick={ () => act( 'ignore' ) } disabled={ busy }>رها کن</Button>
						{ picking && <PostPicker onPick={ ( p ) => act( 'redirect', p.id ) } label="مطلب مقصد را جست‌وجو کنید" /> }
					</>
				) }
				{ canEdit && ! open && <Button variant="tertiary" size="small" onClick={ () => act( 'reopen' ) } disabled={ busy }>بازگشت به فهرست</Button> }
			</td>
		</tr>
	);
}

export default function NotFoundPage() {
	const canEdit = !! ( config.caps && config.caps.manage );
	const [ view, setView ] = useState( 'todo' );
	const [ data, setData ] = useState( null );
	const [ gsc, setGsc ] = useState( null );
	const [ error, setError ] = useState( '' );

	const load = useCallback( () => {
		get( `/gsc/notfound?view=${ view }` ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ view ] );

	useEffect( () => {
		load();
	}, [ load ] );
	useEffect( () => {
		get( '/gsc' ).then( setGsc ).catch( () => {} );
	}, [] );

	const counts = data && data.counts;

	return (
		<Page title="صفحات ۴۰۴ در گوگل" helpKey="notfound">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }

			{ data && (
				<section className="pp-card pp-card--section">
					<h2 className="pp-card__title">آدرس‌هایی که گوگل باز می‌کند و صفحه‌ای ندارند</h2>
					<p className="pp-muted">
						هر ۴۰۴ مشکل نیست؛ آدرس‌های اشتباه و اسپم طبیعی‌اند. فقط آدرس‌های <strong>باارزش</strong> مهم‌اند: آن‌هایی که در خروجی عملکرد کلیک یا نمایش داشته‌اند، یا از داخل سایت به آن‌ها لینک داده شده. برای هر کدام راه‌حل پیشنهاد می‌شود؛ تا شما تأیید نکنید چیزی تغییر نمی‌کند.
					</p>
					{ counts.imported ? (
						<p>آخرین ورود: { ago( counts.imported.at_iso ) } — { num( counts.todo ) } آدرس نیازمند تصمیم، { num( counts.valuable ) } آدرس باارزش.</p>
					) : (
						<p>هنوز خروجی ۴۰۴ وارد نشده است. در سرچ کنسول: «Indexing» ← «Pages» ← پایین صفحه روی ردیف <strong>«Not found (404)»</strong> بزنید ← «Export» ← «Download CSV»، و فایل zip را اینجا بیندازید.</p>
					) }
					{ data.owner && <p className="pp-muted">ریدایرکت‌ها در { data.owner.name } مدیریت می‌شوند؛ پایدارپرس پیش از ساخت ریدایرکت بررسی می‌کند آنجا ریدایرکتی هست یا نه.</p> }
				</section>
			) }

			{ canEdit && gsc && <Uploader data={ gsc } onDone={ () => load() } /> }

			{ data && (
				<>
					<div className="pp-tabs" role="tablist">
						{ VIEWS.map( ( v ) => (
							<button key={ v.key } type="button" role="tab" aria-selected={ v.key === view } className={ `pp-tab${ v.key === view ? ' is-active' : '' }` } onClick={ () => setView( v.key ) }>{ v.label }</button>
						) ) }
					</div>
					{ 0 === data.items.length ? (
						<div className="pp-card"><EmptyState title={ 'todo' === view ? 'آدرسی نیازمند تصمیم نیست' : 'موردی نیست' } text={ counts.imported ? 'همه آدرس‌های ۴۰۴ گزارش یا حل شده‌اند، یا سیستمی و بی‌ارزش‌اند.' : 'پس از ورود خروجی Not found (404)، آدرس‌ها اینجا می‌آیند.' } /></div>
					) : (
						<div className="pp-card pp-card--flush">
							<table className="pp-table">
								<thead><tr><th>آدرس</th><th>ارزش</th><th>جایگزین پیشنهادی</th><th></th></tr></thead>
								<tbody>{ data.items.map( ( i ) => <Row key={ i.id } item={ i } canEdit={ canEdit } onChange={ load } /> ) }</tbody>
							</table>
						</div>
					) }
				</>
			) }
		</Page>
	);
}
