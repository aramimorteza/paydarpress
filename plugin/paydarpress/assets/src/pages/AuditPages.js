import { useEffect, useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import FindingsList from '../components/FindingsList';
import FindingCard from '../components/FindingCard';
import AiButton from '../components/AiButton';
import StructurePanel from '../components/StructurePanel';
import ClassifyPanel from '../components/ClassifyPanel';
import MergeButton from '../components/MergeButton';
import { refreshCommand, termCommand, termTextCommand } from '../lib/aiCommands';
import { get, post, errorMessage } from '../lib/api';
import { num, date, ago } from '../lib/format';

/**
 * Reads ?code= from the hash route (e.g. #/audit?code=ORPHAN_POST).
 */
function hashParam( name ) {
	const query = window.location.hash.split( '?' )[ 1 ] || '';
	return new URLSearchParams( query ).get( name ) || '';
}

/**
 * Generic loader hook.
 *
 * @param {string} path  Endpoint path.
 * @param {Object} query Query args.
 * @param {Array}  deps  Dependencies.
 */
function useLoad( path, query = {}, deps = [] ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );

	const load = () => {
		setLoading( true );
		return get( path, query )
			.then( ( d ) => {
				setData( d );
				setError( '' );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setLoading( false ) );
	};

	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( () => { load(); }, deps );

	return { data, error, loading, reload: load, setData };
}

/**
 * All findings.
 */
export function AuditPage() {
	return (
		<Page title="مشکلات محتوا" helpKey="audit">
			<FindingsList initialCode={ hashParam( 'code' ) } homes={ { images: '/images', gsc: '/gsc' } } />
		</Page>
	);
}

/**
 * Image findings.
 */
export { default as ImagesPage } from './ImagesPage';

/**
 * Orphan content with link suggestions.
 */
export function OrphansPage() {
	const [ page, setPage ] = useState( 1 );
	const { data, error, loading } = useLoad( '/orphans', { page, per_page: 10 }, [ page ] );
	const canManage = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );
	const [ gen, setGen ] = useState( null );
	const [ queued, setQueued ] = useState( {} );

	const generate = () => {
		setGen( { busy: true } );
		post( '/links/generate' )
			.then( ( r ) => setGen( r ) )
			.catch( ( e ) => setGen( { error: errorMessage( e ) } ) );
	};

	const queue = ( source, target, anchor ) => {
		const key = `${ source }-${ target }`;
		setQueued( { ...queued, [ key ]: 'busy' } );
		post( '/links', { source_id: source, target_id: target, anchor } )
			.then( ( r ) => setQueued( ( q ) => ( { ...q, [ key ]: r.found ? 'ok' : 'notfound' } ) ) )
			.catch( () => setQueued( ( q ) => ( { ...q, [ key ]: null } ) ) );
	};

	return (
		<Page title="محتوای یتیم" helpKey="orphans">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ loading && ! data && <Spinner /> }
			{ data && 0 === data.total && (
				<div className="pp-card">
					<EmptyState title="محتوای یتیم پیدا نشد" text="به همه نوشته‌ها و برگه‌های منتشرشده حداقل از یک صفحه لینک داده شده است." />
				</div>
			) }
			{ data && data.total > 0 && <p className="pp-muted">{ num( data.total ) } محتوای یتیم. برای هر مورد، از یکی از نوشته‌های پیشنهادی به آن لینک بدهید.</p> }
			{ data && data.settling > 0 && (
				<p className="pp-muted pp-settling">
					{ num( data.settling ) } مطلب تازه (کمتر از { num( data.grace_days ) } روز از انتشار) هنوز لینک ورودی ندارد اما یتیم حساب نشده است؛ در مهلت جاافتادن هستند. لینک‌های مطلب تازه باید همراه خودش ساخته شوند (هنگام ورود از هوش مصنوعی خودکار انجام می‌شود).
				</p>
			) }
			{ data && data.total > 0 && canManage && (
				<section className="pp-card pp-card--section pp-gen">
					<div>
						<h2 className="pp-card__title">پیشنهاد لینک خودکار</h2>
						<p className="pp-muted">پایدارپرس در نوشته‌های مرتبط، عبارتی را پیدا می‌کند که به موضوع هر محتوای یتیم اشاره دارد و پیشنهاد لینک را در صف تأیید می‌گذارد. تا تأیید شما هیچ نوشته‌ای تغییر نمی‌کند.</p>
						{ gen && gen.error && <p className="pp-missing">{ gen.error }</p> }
						{ gen && undefined !== gen.created && (
							<p>
								{ gen.created ? `${ num( gen.created ) } پیشنهاد برای ${ num( gen.targets ) } محتوا ساخته شد.` : 'پیشنهاد تازه‌ای پیدا نشد (عبارت مناسبی در نوشته‌های مرتبط نبود یا قبلاً پیشنهاد شده است).' }{ ' ' }
								{ gen.created > 0 && <a href="#/links">بررسی و تأیید ←</a> }
							</p>
						) }
					</div>
					<Button variant="primary" onClick={ generate } isBusy={ !! ( gen && gen.busy ) } disabled={ !! ( gen && gen.busy ) } __next40pxDefaultSize>ساخت پیشنهادها</Button>
				</section>
			) }
			<div className="pp-finding-list">
				{ data &&
					data.items.map( ( item ) => (
						<FindingCard key={ item.id } finding={ item }>
							<div className="pp-suggest">
								<strong>از این نوشته‌ها به آن لینک بدهید:</strong>
								{ item.suggestions.length ? (
									<ul>
										{ item.suggestions.map( ( s ) => (
											<li key={ s.id }>
												<a href={ s.edit_url }>{ s.title }</a>
												<span className="pp-muted"> — { s.reasons.join( '، ' ) }</span>
												{ canManage && item.object && ( () => {
													const state = queued[ `${ s.id }-${ item.object.id }` ];
													if ( 'ok' === state ) {
														return <span className="pp-ok"> — در صف تأیید</span>;
													}
													if ( 'notfound' === state ) {
														return <span className="pp-missing"> — در صف؛ متن لنگر در نوشته نیست، در <a href="#/links?status=pending">صف لینک</a> ویرایشش کنید</span>;
													}
													return (
														<Button variant="link" disabled={ 'busy' === state } onClick={ () => queue( s.id, item.object.id, item.anchor ) }>
															افزودن به صف لینک
														</Button>
													);
												} )() }
											</li>
										) ) }
									</ul>
								) : (
									<p className="pp-muted">نوشته مرتبطی پیدا نشد؛ از صفحه اصلی یا منو به آن لینک بدهید.</p>
								) }
								<p className="pp-muted">
									انکرتکست پیشنهادی: «{ item.anchor }» (یا یک عبارت توصیفی مشابه)
								</p>
							</div>
						</FindingCard>
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

const ACTION_CLASS = { rewrite: 'danger', merge: 'warning', light: 'info', none: 'neutral' };

/**
 * Refresh candidates.
 */
export function RefreshPage() {
	const [ page, setPage ] = useState( 1 );
	const { data, error, loading } = useLoad( '/refresh-candidates', { page, per_page: 20 }, [ page ] );

	return (
		<Page title="نیازمند بازنویسی" helpKey="refresh">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ loading && ! data && <Spinner /> }
			{ data && 0 === data.total && (
				<div className="pp-card">
					<EmptyState title="مقاله‌ای نیاز فوری به بروزرسانی ندارد" text="امتیاز نیاز به بروزرسانی همه نوشته‌ها صفر است." />
				</div>
			) }
			{ data && data.total > 0 && (
				<div className="pp-card pp-card--flush">
					<table className="pp-table">
						<thead>
							<tr>
								<th>عنوان</th>
								<th>امتیاز نیاز</th>
								<th>اقدام پیشنهادی</th>
								<th>دلایل</th>
								{ data.items.some( ( i ) => i.gsc ) && <th className="pp-num">گوگل (کلیک / نمایش / جایگاه)</th> }
								<th className="pp-num">کلمات</th>
								<th>آخرین ویرایش</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( item ) => (
								<tr key={ item.id }>
									<td>
										<a className="pp-link" href={ item.edit_url }>{ item.title }</a>
										<div className="pp-row-ai"><AiButton command={ refreshCommand( item.title, item.url, item.reasons, item.id, item.type ) } /> <MergeButton id={ item.id } title={ item.title } /></div>
									</td>
									<td>
										<div className="pp-score">
											<div className="pp-bar pp-bar--small"><span style={ { width: `${ item.score }%` } } /></div>
											<span>{ num( item.score ) }</span>
										</div>
									</td>
									<td><span className={ `pp-badge pp-badge--${ ACTION_CLASS[ item.action ] }` }>{ item.action_label }</span></td>
									<td className="pp-reasons">{ item.reasons.map( ( r ) => <span key={ r } className="pp-chip">{ r }</span> ) }</td>
									{ data.items.some( ( i ) => i.gsc ) && (
										<td className="pp-num">
											{ item.gsc && item.gsc.impressions > 0
												? `${ num( item.gsc.clicks ) } / ${ num( item.gsc.impressions ) } / ${ num( item.gsc.position ) }`
												: 'بدون نمایش' }
										</td>
									) }
									<td className="pp-num">{ num( item.words ) }</td>
									<td>{ date( item.modified_at ) }</td>
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
		</Page>
	);
}

/**
 * Categories and tags health.
 */
export function TaxonomiesPage() {
	const { data, error, loading } = useLoad( '/taxonomies' );
	const [ plan, setPlan ] = useState( {} );
	useEffect( () => {
		get( '/structure' ).then( ( s ) => setPlan( Object.fromEntries( s.categories.map( ( c ) => [ c.id, c ] ) ) ) ).catch( () => {} );
	}, [] );
	const IMPORTANCE = { high: 'مهم', normal: 'عادی', low: 'کم‌اهمیت' };

	const table = ( items, emptyText, kind ) =>
		items.length ? (
			<div className="pp-card pp-card--flush">
				<table className="pp-table">
					<thead>
						<tr>
							<th>نام</th>
							<th className="pp-num">تعداد مطلب</th>
							{ 'category' === kind && <th>هدف (اهمیت)</th> }
							<th className="pp-num">توضیحات</th>
							<th>ایندکس</th>
							<th>مشکلات</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( t ) => (
							<tr key={ t.id }>
								<td>
									<a className="pp-link" href={ t.edit_url }>{ t.name }</a>
									<div className="pp-row-ai">
										{ t.findings.some( ( f ) => [ 'WEAK_CATEGORY', 'EMPTY_CATEGORY', 'THIN_TAG', 'EMPTY_TAG', 'INDEXED_THIN_ARCHIVE' ].includes( f.code ) ) && (
											<AiButton command={ termCommand( t.name, kind, t.id ) } />
										) }
										{ 'category' === kind && t.count > 0 && <AiButton command={ termTextCommand( t.name, t.id ) } /> }
									</div>
								</td>
								<td className="pp-num">{ num( t.count ) }</td>
								{ 'category' === kind && (
									<td>
										{ plan[ t.id ] ? (
											<>
												{ num( plan[ t.id ].target ) } <span className="pp-muted">({ IMPORTANCE[ plan[ t.id ].importance ] })</span>
												{ plan[ t.id ].gap > 0 ? <div className="pp-missing">{ num( plan[ t.id ].gap ) } مطلب کم دارد</div> : <div className="pp-ok">کامل</div> }
											</>
										) : <span className="pp-muted">—</span> }
									</td>
								) }
								<td className="pp-num">{ t.description > 0 ? `${ num( t.description ) } نویسه` : <span className="pp-missing">ندارد</span> }</td>
								<td>{ t.noindex ? <span className="pp-badge">noindex</span> : 'قابل ایندکس' }</td>
								<td className="pp-reasons">
									{ t.findings.length ? t.findings.map( ( f ) => <span key={ f.code } className={ `pp-chip pp-chip--${ f.severity }` }>{ f.title }</span> ) : <span className="pp-muted">—</span> }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		) : (
			<p className="pp-muted">{ emptyText }</p>
		);

	return (
		<Page title="دسته‌ها و برچسب‌ها" helpKey="taxonomies" actions={ <Button variant="secondary" href="#/decisions">تصمیم برای خالی و کم‌مطلب‌ها</Button> }>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ loading && <Spinner /> }
			{ data && (
				<>
					<StructurePanel />
					<ClassifyPanel />
					<h2 className="pp-section-title">دسته‌بندی‌ها ({ num( data.categories.length ) })</h2>
					<p className="pp-muted">«هدف» تعداد مطلب مناسب هر دسته است و از مرحله سایت و نقش دسته می‌آید. نقش، محدوده و هدف هر دسته و برچسب را در <a href="#/profile">پروفایل ← نقشه دسته‌ها و برچسب‌ها</a> تعیین کنید؛ دسته‌های «فقط ناوبری» و «کنار گذاشته» هدف ندارند.</p>
					{ table( data.categories, 'دسته‌بندی‌ای وجود ندارد.', 'category' ) }
					<h2 className="pp-section-title">برچسب‌ها ({ num( data.tags.length ) })</h2>
					{ table( data.tags, 'برچسبی وجود ندارد.', 'tag' ) }
				</>
			) }
		</Page>
	);
}

const BOT_STATE = {
	allowed: [ 'success', 'آزاد' ],
	partial: [ 'info', 'آزاد (به‌جز بخش‌هایی مانند پیشخوان)' ],
	blocked: [ 'danger', 'مسدود' ],
};

/**
 * Crawler access.
 */
export function VisibilityPage() {
	const canManage = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );
	const { data, error, loading, setData } = useLoad( '/crawlers' );
	const [ checking, setChecking ] = useState( false );
	const [ checkError, setCheckError ] = useState( '' );

	const check = () => {
		setChecking( true );
		setCheckError( '' );
		post( '/crawlers/check' )
			.then( setData )
			.catch( ( e ) => setCheckError( errorMessage( e ) ) )
			.finally( () => setChecking( false ) );
	};

	return (
		<Page
			title="دسترسی خزنده‌ها"
			helpKey="visibility"
			actions={ canManage && <Button variant="secondary" onClick={ check } isBusy={ checking } disabled={ checking }>بررسی مجدد</Button> }
		>
			{ ( error || checkError ) && <Notice status="error" isDismissible={ false }>{ error || checkError }</Notice> }
			{ loading && <Spinner /> }
			{ data && (
				<>
					<div className={ `pp-card pp-status ${ data.site_public ? 'is-ok' : 'is-bad' }` }>
						<strong>{ data.site_public ? 'سایت برای موتورهای جستجو قابل مشاهده است.' : 'سایت از ایندکس موتورهای جستجو خارج شده است!' }</strong>
						{ ! data.site_public && <p>در تنظیمات ← خواندن، گزینه «از موتورهای جستجو بخواهید…» را غیرفعال کنید.</p> }
					</div>

					<h2 className="pp-section-title">وضعیت خزنده‌ها در robots.txt</h2>
					{ ! data.checked_at ? (
						<div className="pp-card">
							<EmptyState title="هنوز بررسی نشده است" text="پس از اولین اسکن سایت یا با دکمه «بررسی مجدد» انجام می‌شود." />
						</div>
					) : (
						<div className="pp-card pp-card--flush">
							<table className="pp-table">
								<thead>
									<tr>
										<th>خزنده</th>
										<th>وضعیت</th>
										<th>توضیح</th>
									</tr>
								</thead>
								<tbody>
									{ data.bots.map( ( b ) => {
										const [ cls, label ] = BOT_STATE[ b.state ] || [ 'neutral', '—' ];
										return (
											<tr key={ b.name }>
												<td dir="ltr" className="pp-bot">{ b.name }</td>
												<td><span className={ `pp-badge pp-badge--${ cls }` }>{ label }</span></td>
												<td className="pp-muted">{ b.description }</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					) }
					{ data.checked_at && (
						<p className="pp-muted">
							آخرین بررسی { ago( data.checked_at ) }
							{ 'wordpress' === data.source && ' (فایل robots.txt از سرور دریافت نشد؛ نسخه‌ای که وردپرس می‌سازد بررسی شد)' } —{ ' ' }
							<a href={ data.robots_url } target="_blank" rel="noreferrer">مشاهده robots.txt</a>
						</p>
					) }
					<p className="pp-muted">
						بررسی دسترسی سایت از خارج ایران و تنظیمات فایروال در نسخه‌های بعدی اضافه می‌شود. اگر سایت روی هاستی است که ترافیک خارجی را محدود می‌کند، خزنده‌ها حتی با robots.txt آزاد هم نمی‌توانند سایت را ببینند.
					</p>
				</>
			) }
		</Page>
	);
}
