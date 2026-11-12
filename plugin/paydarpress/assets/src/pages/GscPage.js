import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import AiButton from '../components/AiButton';
import { refreshCommand, queryCommand } from '../lib/aiCommands';
import { get, post, errorMessage } from '../lib/api';
import { num, date, ago } from '../lib/format';

const config = window.paydarpress || {};

const TABS = [
	{ key: 'near', label: 'نزدیک صفحه اول', hint: 'جایگاه میانگین ۸ تا ۲۰ با نمایش کافی. با کمی بهبود و چند لینک داخلی می‌توانند به صفحه اول برسند.' },
	{ key: 'drop', label: 'افت کلیک', hint: 'کلیک نسبت به دوره قبل دست‌کم ۳۰٪ کم شده است. فقط وقتی خروجی در حالت «مقایسه» گرفته شده باشد.' },
	{ key: 'ctr', label: 'رتبه خوب، کلیک کم', hint: 'در ۵ جایگاه اول دیده می‌شوند اما کمتر از ۲٪ بینندگان کلیک می‌کنند؛ عنوان و توضیحات متا را بهتر کنید.' },
	{ key: 'absent', label: 'دیده‌نشده در گوگل', hint: 'نوشته‌های قدیمی‌تر از ۳ ماه که در این دوره هیچ نمایشی در گوگل نداشته‌اند.' },
	{ key: 'top', label: 'پربازدیدترین صفحات', hint: 'صفحات سایت به ترتیب کلیک از گوگل.' },
	{ key: 'unmatched', label: 'آدرس‌های بدون محتوا', hint: 'آدرس‌هایی که در گوگل دیده شده‌اند اما به نوشته یا برگه فعلی نمی‌رسند.' },
	{ key: 'gaps', label: 'جست‌وجوهای بی‌مقاله', hint: 'کاربران این عبارت‌ها را جست‌وجو کرده‌اند و سایت شما در نتایج دیده شده، اما هیچ عنوان یا کلمه کلیدی مقاله‌ای آن را پوشش نمی‌دهد. فرصت مقاله جدید یا تکمیل یک مقاله نزدیک است.' },
	{ key: 'queries', label: 'عبارت‌های جست‌وجو', hint: 'عبارت‌هایی که سایت شما با آن‌ها در گوگل دیده شده است.' },
];

const STATE_LABELS = {
	missing: { label: 'صفحه حذف شده', cls: 'danger', tip: 'این آدرس هنوز از گوگل بازدید می‌گیرد اما صفحه‌ای ندارد. آن را به مرتبط‌ترین صفحه موجود ریدایرکت کنید.' },
	old_url: { label: 'آدرس قدیمی', cls: 'warning', tip: 'نامک این نوشته عوض شده و وردپرس آن را به آدرس جدید می‌برد. پس از مدتی گوگل آدرس جدید را جایگزین می‌کند.' },
	unpublished: { label: 'منتشرنشده', cls: 'warning', tip: 'این محتوا پیش‌نویس یا خصوصی شده اما هنوز در گوگل دیده می‌شود.' },
	archive: { label: 'آرشیو یا صفحه اصلی', cls: 'info', tip: 'صفحه اصلی، دسته یا برچسب؛ نیازی به اقدام نیست.' },
};

function percent( v ) {
	return `${ num( Math.round( ( v || 0 ) * 1000 ) / 10 ) }٪`;
}

export default function GscPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ tab, setTab ] = useState( 'near' );

	const load = () => get( '/gsc' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	useEffect( () => {
		load();
	}, [] );

	const summary = data && data.import ? data.import.summary : null;

	return (
		<Page title="سرچ کنسول" helpKey="gsc">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }

			{ data && data.stale && (
				<Notice status="warning" isDismissible={ false }>
					داده سرچ کنسول بیش از ۲ ماه پیش وارد شده است. برای پیشنهادهای دقیق، خروجی تازه را آپلود کنید.
				</Notice>
			) }

			{ data && ! data.import && (
				<>
					<HowTo />
					{ canManage ? <Uploader data={ data } onDone={ ( d ) => setData( d ) } /> : (
						<div className="pp-card"><EmptyState title="هنوز داده‌ای وارد نشده است" text="ورود داده سرچ کنسول فقط برای مدیر سایت مجاز است." /></div>
					) }
				</>
			) }

			{ summary && (
				<>
					<Summary data={ data } summary={ summary } />
					<div className="pp-tabs" role="tablist" aria-label="گزارش‌های سرچ کنسول">
						{ TABS.map( ( t ) => (
							<button
								key={ t.key }
								type="button"
								role="tab"
								aria-selected={ tab === t.key }
								className={ `pp-tab${ tab === t.key ? ' is-active' : '' }` }
								onClick={ () => setTab( t.key ) }
							>
								{ t.label }
								{ undefined !== data.counts[ t.key ] && <span className="pp-tab__count">{ num( data.counts[ t.key ] ) }</span> }
							</button>
						) ) }
					</div>
					<p className="pp-muted pp-filter-hint">{ TABS.find( ( t ) => t.key === tab ).hint }</p>
					{ 'queries' === tab && <QueriesTable /> }
					{ 'gaps' === tab && <GapsTable /> }
					{ 'queries' !== tab && 'gaps' !== tab && <PagesTable key={ tab } tab={ tab } compare={ !! summary.compare } /> }
					{ canManage && (
						<details className="pp-card pp-card--section pp-gsc-replace">
							<summary>آپلود داده تازه یا حذف داده</summary>
							<HowTo compact />
							<Uploader data={ data } onDone={ ( d ) => setData( d ) } />
							<ClearButton onDone={ ( d ) => setData( d ) } />
						</details>
					) }
				</>
			) }
		</Page>
	);
}

/**
 * Steps to export from Search Console.
 *
 * @param {Object}  props
 * @param {boolean} props.compact Smaller variant.
 */
function HowTo( { compact } ) {
	return (
		<section className={ compact ? 'pp-howto-wrap' : 'pp-card pp-card--section' }>
			{ ! compact && <h2 className="pp-card__title">گرفتن خروجی از سرچ کنسول (۲ دقیقه)</h2> }
			<ol className="pp-howto">
				<li>در <bdi dir="ltr">search.google.com/search-console</bdi> سایت را انتخاب کنید و به گزارش «عملکرد» (Performance) بروید.</li>
				<li>بازه زمانی را «۳ ماه گذشته» بگذارید. برای دیدن افت کلیک، در همان پنجره تب «مقایسه» را بزنید و «۳ ماه گذشته با دوره قبل» را انتخاب کنید.</li>
				<li>دکمه «خروجی» (Export) بالای صفحه را بزنید و «دانلود CSV» را انتخاب کنید. یک فایل zip دانلود می‌شود.</li>
				<li>همان فایل zip را بدون باز کردن، اینجا آپلود کنید.</li>
			</ol>
		</section>
	);
}

/**
 * Drag-and-drop uploader.
 *
 * @param {Object}   props
 * @param {Object}   props.data   Summary payload.
 * @param {Function} props.onDone Called with the new summary.
 */
function Uploader( { data, onDone } ) {
	const input = useRef( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ over, setOver ] = useState( false );
	const [ done, setDone ] = useState( null );

	const send = ( files ) => {
		const list = Array.from( files || [] );
		if ( ! list.length ) {
			return;
		}
		const tooBig = list.find( ( f ) => f.size > data.max_upload );
		if ( tooBig ) {
			setError( `حجم «${ tooBig.name }» بیش از حد مجاز (${ num( Math.round( data.max_upload / 1048576 ) ) } مگابایت) است.` );
			return;
		}
		const body = new window.FormData();
		list.forEach( ( f ) => body.append( 'files[]', f, f.name ) );
		setBusy( true );
		setError( '' );
		apiFetch( { path: `/${ config.restNamespace }/gsc/import`, method: 'POST', body } )
			.then( ( d ) => {
				setDone( d.import ? d.import.summary : null );
				onDone( d );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<section className="pp-card pp-card--section">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ done && <Notice status="success" isDismissible={ false }>داده وارد شد: { num( done.pages ) } صفحه و { num( done.queries ) } عبارت. یافته‌ها و پیشنهاد بازنویسی بروز شدند.</Notice> }
			<div
				className={ `pp-dropzone${ over ? ' is-over' : '' }` }
				onDragOver={ ( e ) => {
					e.preventDefault();
					setOver( true );
				} }
				onDragLeave={ () => setOver( false ) }
				onDrop={ ( e ) => {
					e.preventDefault();
					setOver( false );
					send( e.dataTransfer.files );
				} }
			>
				{ busy ? (
					<p><Spinner /> در حال خواندن فایل و تطبیق آدرس‌ها…</p>
				) : (
					<>
						<p className="pp-dropzone__title">فایل zip خروجی سرچ کنسول را اینجا رها کنید</p>
						<p className="pp-muted">یا فایل‌های Pages.csv و Queries.csv را جداگانه انتخاب کنید. فایل روی سرور ذخیره نمی‌شود؛ فقط ارقام و آدرس‌ها.</p>
						<Button variant="primary" onClick={ () => input.current && input.current.click() } __next40pxDefaultSize>انتخاب فایل</Button>
					</>
				) }
				<input
					ref={ input }
					type="file"
					accept=".zip,.csv,application/zip,text/csv"
					multiple
					hidden
					onChange={ ( e ) => {
						send( e.target.files );
						e.target.value = '';
					} }
				/>
			</div>
			{ ! data.zip_supported && <p className="pp-muted">پشتیبانی zip روی این سرور فعال نیست؛ فایل zip را باز کنید و Pages.csv و Queries.csv را آپلود کنید.</p> }
		</section>
	);
}

/**
 * Summary cards of the current data set.
 *
 * @param {Object} props
 * @param {Object} props.data    Payload.
 * @param {Object} props.summary Import summary.
 */
function Summary( { data, summary } ) {
	const period = summary.period || {};
	const warnings = ( summary.warnings || [] ).map( ( w ) => {
		if ( w.startsWith( 'filtered:' ) ) {
			return `خروجی با فیلتر «${ w.slice( 9 ) }» گرفته شده و فقط بخشی از سایت را دارد.`;
		}
		if ( w.startsWith( 'truncated:' ) ) {
			return `فایل ${ w.slice( 10 ) } بیش از ۵۰٬۰۰۰ ردیف داشت؛ بقیه خوانده نشد.`;
		}
		return null;
	} ).filter( Boolean );
	if ( summary.pages_capped ) {
		warnings.push( 'سرچ کنسول فقط ۱٬۰۰۰ صفحه اول را در خروجی می‌دهد؛ صفحات کم‌بازدیدتر در داده نیستند و «دیده‌نشده در گوگل» بررسی نشد.' );
	}

	return (
		<section className="pp-card pp-card--section">
			<div className="pp-gsc-head">
				<h2 className="pp-card__title">داده فعلی</h2>
				<span className="pp-muted">
					{ period.start ? `${ date( period.start ) } تا ${ date( period.end ) }` : period.label || 'بازه نامشخص' }
					{ summary.compare ? ' — با مقایسه دوره قبل' : '' }
					{ ' — وارد شده ' }{ ago( data.import.imported_at ) }
				</span>
			</div>
			<dl className="pp-facts">
				<div><dt>کلیک از گوگل</dt><dd>{ num( summary.clicks ) }</dd></div>
				<div><dt>نمایش در گوگل</dt><dd>{ num( summary.impressions ) }</dd></div>
				<div><dt>مطالب سایت با نمایش</dt><dd>{ num( summary.content_items ) }</dd></div>
				<div><dt>تطبیق آدرس‌ها</dt><dd>{ null === summary.match_rate ? '—' : percent( summary.match_rate ) }</dd></div>
				<div><dt>عبارت جست‌وجو</dt><dd>{ num( summary.queries ) }</dd></div>
			</dl>
			{ warnings.map( ( w ) => <p key={ w } className="pp-missing">{ w }</p> ) }
		</section>
	);
}

/**
 * Page lists (opportunities, top pages, unmatched URLs).
 *
 * @param {Object}  props
 * @param {string}  props.tab     Tab key.
 * @param {boolean} props.compare Whether previous-period data exists.
 */
function PagesTable( { tab, compare } ) {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setData( null );
		get( '/gsc/pages', { tab, page, per_page: 25 } )
			.then( setData )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ tab, page ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}
	if ( 0 === data.total ) {
		const empty = 'drop' === tab && ! compare
			? 'برای دیدن افت کلیک، خروجی سرچ کنسول را در حالت «مقایسه» بگیرید.'
			: 'موردی در این گروه نیست.';
		return <div className="pp-card"><EmptyState title="موردی پیدا نشد" text={ empty } /></div>;
	}

	const unmatched = 'unmatched' === tab;

	return (
		<>
			<div className="pp-card pp-card--flush">
				<table className="pp-table">
					<thead>
						<tr>
							<th>{ unmatched ? 'آدرس' : 'عنوان' }</th>
							{ unmatched && <th>وضعیت</th> }
							<th className="pp-num">کلیک</th>
							{ compare && <th className="pp-num">کلیک دوره قبل</th> }
							<th className="pp-num">نمایش</th>
							<th className="pp-num">نرخ کلیک</th>
							<th className="pp-num">جایگاه</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( item ) => {
							const m = item.metrics;
							const state = unmatched ? STATE_LABELS[ item.state ] || STATE_LABELS.archive : null;
							return (
								<tr key={ unmatched ? item.url : item.id }>
									<td>
										{ unmatched ? (
											<>
												<bdi dir="ltr" className="pp-url">{ item.url }</bdi>
												{ item.target && <div className="pp-muted">← { item.target.title }</div> }
											</>
										) : (
											<>
												<a className="pp-link" href={ item.edit_url }>{ item.title }</a>
												<div><a className="pp-muted" href={ item.url } target="_blank" rel="noreferrer">مشاهده</a></div>
												{ [ 'near', 'ctr', 'drop', 'absent' ].includes( tab ) && (
													<div className="pp-row-ai"><AiButton command={ refreshCommand( item.title, item.url, [ ( TABS.find( ( t ) => t.key === tab ) || {} ).label ] ) } /></div>
												) }
											</>
										) }
									</td>
									{ unmatched && <td><span className={ `pp-badge pp-badge--${ state.cls }` } title={ state.tip }>{ state.label }</span></td> }
									<td className="pp-num">{ m ? num( m.clicks ) : '۰' }</td>
									{ compare && <td className="pp-num">{ m && null !== m.prev_clicks && undefined !== m.prev_clicks ? num( m.prev_clicks ) : '—' }</td> }
									<td className="pp-num">{ m ? num( m.impressions ) : '۰' }</td>
									<td className="pp-num">{ m && m.impressions ? percent( m.ctr ) : '—' }</td>
									<td className="pp-num">{ m && m.position ? num( m.position ) : '—' }</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
			{ unmatched && <p className="pp-muted pp-table-note">{ STATE_LABELS.missing.tip }</p> }
			{ data.total_pages > 1 && (
				<div className="pp-pagination">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
					<span>صفحه { num( page ) } از { num( data.total_pages ) }</span>
					<Button variant="secondary" disabled={ page >= data.total_pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
				</div>
			) }
		</>
	);
}

/**
 * Search queries.
 */
function QueriesTable() {
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ data, setData ] = useState( null );

	useEffect( () => {
		const t = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 350 );
		return () => clearTimeout( t );
	}, [ search ] );

	useEffect( () => {
		setData( null );
		get( '/gsc/queries', { page, per_page: 50, search: query } ).then( setData ).catch( () => {} );
	}, [ page, query ] );

	return (
		<>
			<div className="pp-toolbar">
				<SearchControl __nextHasNoMarginBottom label="جست‌وجو در عبارت‌ها" placeholder="جست‌وجو در عبارت‌ها…" value={ search } onChange={ setSearch } />
			</div>
			{ ! data && <Spinner /> }
			{ data && 0 === data.total && <div className="pp-card"><EmptyState title="عبارتی پیدا نشد" text="فایل Queries.csv در خروجی نبود یا با این جست‌وجو موردی نیست." /></div> }
			{ data && data.total > 0 && (
				<div className="pp-card pp-card--flush">
					<table className="pp-table">
						<thead>
							<tr>
								<th>عبارت جست‌وجو</th>
								<th className="pp-num">کلیک</th>
								<th className="pp-num">نمایش</th>
								<th className="pp-num">نرخ کلیک</th>
								<th className="pp-num">جایگاه</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( q ) => (
								<tr key={ q.item }>
									<td>
										{ q.item }
										<div className="pp-row-ai"><AiButton command={ queryCommand( q.item ) } /></div>
									</td>
									<td className="pp-num">{ num( q.clicks ) }</td>
									<td className="pp-num">{ num( q.impressions ) }</td>
									<td className="pp-num">{ percent( q.ctr ) }</td>
									<td className="pp-num">{ num( q.position ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ data && data.total_pages > 1 && (
				<div className="pp-pagination">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
					<span>صفحه { num( page ) } از { num( data.total_pages ) }</span>
					<Button variant="secondary" disabled={ page >= data.total_pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
				</div>
			) }
		</>
	);
}

/**
 * Searches with impressions that no article covers.
 */
function GapsTable() {
	const [ status, setStatus ] = useState( 'open' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ added, setAdded ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const manage = !! ( config.caps && config.caps.manage );

	useEffect( () => {
		setData( null );
		get( '/gsc/gaps', { status, page, per_page: 30 } ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ status, page ] );

	const addTopic = ( q ) => {
		setAdded( ( a ) => ( { ...a, [ q ]: 'busy' } ) );
		post( '/gsc/gaps/topic', { query: q } )
			.then( ( r ) => setAdded( ( a ) => ( { ...a, [ q ]: r.created ? 'added' : 'exists' } ) ) )
			.catch( ( e ) => {
				setAdded( ( a ) => ( { ...a, [ q ]: null } ) );
				setError( errorMessage( e ) );
			} );
	};

	const counts = data ? data.counts : null;
	const FILTERS = [
		[ 'open', 'همه فرصت‌ها', counts ? counts.gap + counts.partial : null ],
		[ 'gap', 'بدون هیچ مقاله', counts ? counts.gap : null ],
		[ 'partial', 'مقاله نزدیک دارد', counts ? counts.partial : null ],
		[ 'planned', 'در موضوعات پیشنهادی', counts ? counts.planned : null ],
	];

	return (
		<>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			<div className="pp-toolbar pp-segmented">
				{ FILTERS.map( ( [ key, label, count ] ) => (
					<Button key={ key } variant={ status === key ? 'primary' : 'secondary' } size="small" onClick={ () => { setStatus( key ); setPage( 1 ); } }>
						{ label }{ null !== count && ` (${ num( count ) })` }
					</Button>
				) ) }
			</div>
			{ ! data && ! error && <Spinner /> }
			{ data && 0 === data.total && (
				<div className="pp-card"><EmptyState title="موردی نیست" text={ 'planned' === status ? 'هنوز هیچ جست‌وجویی به موضوعات پیشنهادی اضافه نشده است.' : 'همه جست‌وجوهای پرنمایش (۱۰ نمایش یا بیشتر) مقاله‌ای مرتبط در سایت دارند.' } /></div>
			) }
			{ data && data.total > 0 && (
				<div className="pp-card pp-card--flush">
					<table className="pp-table">
						<thead>
							<tr>
								<th>عبارت جست‌وجو</th>
								<th className="pp-num">نمایش</th>
								<th className="pp-num">کلیک</th>
								<th className="pp-num">جایگاه</th>
								<th>وضعیت</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( g ) => (
								<tr key={ g.query }>
									<td>
										<strong>{ g.query }</strong>
										<div className="pp-row-ai">
											{ 'planned' !== g.status && <AiButton command={ queryCommand( g.query ) } /> }
											{ manage && 'planned' !== g.status && (
												<Button variant="link" disabled={ !! added[ g.query ] } onClick={ () => addTopic( g.query ) }>
													{ { busy: 'در حال افزودن…', added: '✓ به موضوعات پیشنهادی اضافه شد', exists: 'از قبل در موضوعات هست' }[ added[ g.query ] ] || 'افزودن به موضوعات پیشنهادی' }
												</Button>
											) }
										</div>
									</td>
									<td className="pp-num">{ num( g.impressions ) }</td>
									<td className="pp-num">{ num( g.clicks ) }</td>
									<td className="pp-num">{ num( g.position ) }</td>
									<td>
										{ 'gap' === g.status && <span className="pp-badge pp-badge--danger">بدون مقاله</span> }
										{ 'planned' === g.status && <span className="pp-badge pp-badge--info">در موضوعات</span> }
										{ 'partial' === g.status && g.closest && (
											<>
												<span className="pp-badge pp-badge--warning">مقاله نزدیک</span>
												<div className="pp-muted"><a href={ g.closest.url } target="_blank" rel="noreferrer">{ g.closest.title }</a> — شاید با افزودن یک بخش این جست‌وجو را هم پوشش دهد.</div>
											</>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ data && data.total_pages > 1 && (
				<div className="pp-pagination">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
					<span>صفحه { num( page ) } از { num( data.total_pages ) }</span>
					<Button variant="secondary" disabled={ page >= data.total_pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
				</div>
			) }
		</>
	);
}

/**
 * Deletes Search Console data (with confirmation in the UI, no browser dialog).
 *
 * @param {Object}   props
 * @param {Function} props.onDone Called with the new summary.
 */
function ClearButton( { onDone } ) {
	const [ confirm, setConfirm ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	if ( ! confirm ) {
		return <Button variant="link" isDestructive onClick={ () => setConfirm( true ) }>حذف داده سرچ کنسول</Button>;
	}

	return (
		<div className="pp-confirm">
			<span>داده سرچ کنسول و یافته‌های «عملکرد در گوگل» حذف شوند؟</span>
			<Button
				variant="primary"
				isDestructive
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => {
					setBusy( true );
					post( '/gsc/clear' ).then( onDone ).finally( () => setBusy( false ) );
				} }
			>
				بله، حذف شود
			</Button>
			<Button variant="tertiary" onClick={ () => setConfirm( false ) }>انصراف</Button>
		</div>
	);
}
