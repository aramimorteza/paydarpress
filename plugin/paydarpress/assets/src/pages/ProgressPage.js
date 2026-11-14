import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import AiButton from '../components/AiButton';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import { slugsCommand } from '../lib/aiCommands';

const config = window.paydarpress || {};
const canManage = () => !! ( config.caps && config.caps.manage );

/** Areas of the monthly trend: key, label, better when (up|down), unit. */
const TREND = [
	[ 'tags_good', 'برچسب‌های پر (۳+ مطلب)', 'up', '٪' ],
	[ 'tags_dups', 'برچسب تکراری', 'down', '' ],
	[ 'slugs_poor', 'آدرس فارسی یا بی‌معنا', 'down', '' ],
	[ 'refresh', 'مطلب نیازمند بازنویسی', 'down', '' ],
	[ 'orphans', 'مطلب یتیم (بدون لینک ورودی)', 'down', '' ],
	[ 'inbound', 'میانگین لینک ورودی هر مقاله', 'up', '' ],
	[ 'no_alt', 'تصویر بدون متن جایگزین', 'down', '' ],
	[ 'clicks', 'کلیک گوگل (۳ ماه)', 'up', '' ],
];

/**
 * Monthly trend of every area: last 6 months as small bars.
 *
 * @param {Object} props
 * @param {Object} props.history Month => numbers.
 * @param {Object} props.now     Current numbers.
 */
function Trend( { history, now } ) {
	const months = Object.keys( history ).slice( -6 );
	return (
		<table className="pp-table pp-trend">
			<thead><tr><th>بخش</th><th>اکنون</th><th>روند ماهانه</th></tr></thead>
			<tbody>
				{ TREND.map( ( [ key, label, better, unit ] ) => {
					const vals = months.map( ( m ) => Number( history[ m ][ key ] || 0 ) );
					const max = Math.max( 1, ...vals );
					const first = vals[ 0 ];
					const last = Number( now[ key ] || 0 );
					const good = months.length > 1 && ( 'up' === better ? last > first : last < first );
					const bad = months.length > 1 && ( 'up' === better ? last < first : last > first );
					return (
						<tr key={ key }>
							<td>{ label }</td>
							<td><strong>{ num( last ) }{ unit }</strong> { good && <span className="pp-ok">▲ بهتر</span> }{ bad && <span className="pp-missing">▼ بدتر</span> }</td>
							<td>
								<span className="pp-bars" title={ months.map( ( m, i ) => `${ m }: ${ vals[ i ] }` ).join( ' · ' ) }>
									{ vals.map( ( v, i ) => <span key={ months[ i ] } style={ { height: `${ Math.max( 2, ( v / max ) * 22 ) }px` } } /> ) }
								</span>
								{ months.length < 2 && <span className="pp-muted"> روند از ماه بعد</span> }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

/**
 * Address program: this week's batch with editable slugs.
 *
 * @param {Object}   props
 * @param {Object}   props.s      State.
 * @param {Function} props.reload Reload.
 */
function Slugs( { s, reload } ) {
	const [ edit, setEdit ] = useState( {} );
	const [ picked, setPicked ] = useState( () => s.week.filter( ( i ) => i.new && ! i.busy ).map( ( i ) => i.id ) );
	const [ busyOk, setBusyOk ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ msg, setMsg ] = useState( null );

	const slugOf = ( i ) => ( undefined !== edit[ i.id ] ? edit[ i.id ] : i.new );
	const apply = () => {
		setBusy( true );
		setMsg( null );
		post( '/progress/slugs/apply', { items: s.week.filter( ( i ) => picked.includes( i.id ) ).map( ( i ) => ( { id: i.id, slug: slugOf( i ) } ) ), busy_ok: busyOk } )
			.then( ( r ) => {
				setMsg( { ok: true, text: `${ num( r.done.length ) } آدرس عوض شد؛ ${ num( r.done.reduce( ( a, d ) => a + d.links, 0 ) ) } لینک داخلی به آدرس تازه اصلاح شد.${ r.errors.length ? ` خطا: ${ r.errors.join( ' · ' ) }` : '' }` } );
				reload();
			} )
			.catch( ( e ) => setMsg( { ok: false, text: errorMessage( e ) } ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<section className="pp-card pp-card--section" id="slugs">
			<h2 className="pp-card__title"><span className="dashicons dashicons-admin-links" aria-hidden="true" /> آدرس‌ها: فارسی ← انگلیسی معنادار</h2>
			<div className="pp-bar pp-progress__bar"><span style={ { width: `${ Math.max( 2, s.percent ) }%` } } /></div>
			<p className="pp-muted">
				{ num( s.poor ) } آدرس از { num( s.total ) } فارسی، عددی یا تکراری است ({ num( s.percent ) }٪ مناسب). سهم این هفته: { num( s.quota ) } آدرس، { num( s.done_week ) } انجام شده. اول صفحه‌های کم‌بازدید؛ هر تغییر ریدایرکت ۳۰۱ می‌گیرد، لینک‌های داخلی به آدرس تازه اصلاح می‌شوند و نتیجه‌اش در «نتیجه تغییرات» سنجیده می‌شود.
			</p>
			{ s.paused && (
				<Notice status="warning" isDismissible={ false }>
					{ s.pause_why }{ ' ' }
					{ canManage() && <Button variant="link" onClick={ () => post( '/progress/slugs/resume' ).then( reload ) }>ادامه برنامه</Button> }
				</Notice>
			) }
			{ msg && <Notice status={ msg.ok ? 'success' : 'error' } onRemove={ () => setMsg( null ) }>{ msg.text }</Notice> }
			{ s.needs_slug.length > 0 && canManage() && (
				<div className="pp-ai-modal__row">
					<span className="pp-muted">{ num( s.needs_slug.length ) } آدرس هنوز نامک پیشنهادی ندارد.</span>
					<AiButton command={ slugsCommand() } />
				</div>
			) }
			{ s.week.length > 0 && ! s.paused && (
				<>
					<table className="pp-table pp-slugs">
						<thead><tr><th></th><th>صفحه</th><th>آدرس فعلی</th><th>آدرس تازه</th><th>گوگل (۳ ماه)</th></tr></thead>
						<tbody>
							{ s.week.map( ( i ) => (
								<tr key={ i.id } className={ i.busy ? 'is-busy' : '' }>
									<td><CheckboxControl __nextHasNoMarginBottom checked={ picked.includes( i.id ) } disabled={ ! slugOf( i ) } onChange={ ( on ) => setPicked( on ? [ ...picked, i.id ] : picked.filter( ( x ) => x !== i.id ) ) } /></td>
									<td>{ i.title }{ i.busy && <span className="pp-badge pp-badge--warning">پربازدید</span> }</td>
									<td><bdi dir="ltr" className="pp-url" title={ i.why }>{ i.slug }</bdi></td>
									<td><TextControl __nextHasNoMarginBottom dir="ltr" value={ slugOf( i ) } placeholder="english-slug" onChange={ ( v ) => setEdit( { ...edit, [ i.id ]: v.toLowerCase().replace( /[^a-z0-9-]/g, '-' ).replace( /-+/g, '-' ) } ) } /></td>
									<td className="pp-muted">{ num( i.clicks ) } کلیک · { num( i.impressions ) } نمایش</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ canManage() && (
						<div className="pp-ai-modal__row">
							{ s.week.some( ( i ) => i.busy ) && <CheckboxControl __nextHasNoMarginBottom checked={ busyOk } onChange={ setBusyOk } label="صفحه‌های پربازدید را هم عوض کن (ریسک موقت افت رتبه)" /> }
							<Button variant="primary" isBusy={ busy } disabled={ busy || 0 === picked.length } onClick={ apply }>تغییر { num( picked.length ) } آدرس</Button>
						</div>
					) }
				</>
			) }
			{ 0 === s.week.length && ! s.paused && s.poor > 0 && <p className="pp-ok">سهم این هفته انجام شده است.</p> }
			{ 0 === s.poor && <p className="pp-ok">همه آدرس‌ها انگلیسی و معنادارند.</p> }
		</section>
	);
}

/**
 * Tag program.
 *
 * @param {Object}   props
 * @param {Object}   props.t      State.
 * @param {Function} props.reload Reload.
 */
function Tags( { t, reload } ) {
	const [ msg, setMsg ] = useState( null );
	const create = ( name ) => post( '/progress/tags/create', { name } ).then( ( r ) => {
		setMsg( r.message );
		reload();
	} ).catch( ( e ) => setMsg( errorMessage( e ) ) );

	return (
		<section className="pp-card pp-card--section">
			<h2 className="pp-card__title"><span className="dashicons dashicons-tag" aria-hidden="true" /> برچسب‌ها: کم‌ارزش‌ها بروند، باارزش‌ها بیایند</h2>
			<div className="pp-bar pp-progress__bar"><span style={ { width: `${ Math.max( 2, t.percent ) }%` } } /></div>
			<p className="pp-muted">{ num( t.good ) } برچسب از { num( t.total ) } دست‌کم ۳ مطلب دارد ({ num( t.percent ) }٪). { num( t.low ) } برچسب با ۰ یا ۱ مطلب، { num( t.dups ) } تکراری و { num( t.over ) } مطلب با برچسب بیش از اندازه.</p>
			{ msg && <Notice status="info" onRemove={ () => setMsg( null ) }>{ msg }</Notice> }
			<ul className="pp-progress__steps">
				<li><a href="#/decisions">تصمیم برای برچسب‌های تکراری، خالی و کم‌مطلب</a> — روزی چند کارت؛ ادغام در نسخه باارزش‌تر با ریدایرکت</li>
				<li><a href="#/taxonomies">پاک‌سازی برچسب‌ها</a> — در «طبقه‌بندی محتوای موجود» دامنه «پاک‌سازی برچسب‌ها»؛ پیشنهاد حذف با دلیل و تیک شما</li>
			</ul>
			{ t.groups.length > 0 && (
				<details>
					<summary>{ num( t.groups.length ) } گروه برچسب تکراری</summary>
					<ul className="pp-mini-list">{ t.groups.map( ( g ) => <li key={ g.keep.id }><strong>{ g.keep.name }</strong> ({ num( g.keep.count ) }) ← { g.merge.map( ( m ) => `${ m.name } (${ m.why })` ).join( '، ' ) }</li> ) }</ul>
				</details>
			) }
			{ t.suggested.length > 0 && (
				<>
					<h3>برچسب‌های باارزش از جست‌وجوهای گوگل</h3>
					<p className="pp-muted">مردم این عبارت‌ها را جست‌وجو می‌کنند و سایت شما نمایش گرفته، اما برچسب یا دسته‌ای برایشان نیست.</p>
					<ul className="pp-mini-list">
						{ t.suggested.map( ( s ) => (
							<li key={ s.name }>
								<strong>{ s.name }</strong> <span className="pp-muted">{ num( s.impressions ) } نمایش · رتبه { num( s.position ) }</span>
								{ canManage() && <Button variant="link" onClick={ () => create( s.name ) }>ساخت برچسب</Button> }
							</li>
						) ) }
					</ul>
				</>
			) }
		</section>
	);
}

/**
 * Improvement program overview.
 */
export default function ProgressPage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const load = () => get( '/progress' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	useEffect( () => {
		load();
	}, [] );

	return (
		<Page title="برنامه بهبود" helpKey="progress">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<section className="pp-card pp-card--section">
						<p className="pp-muted">
							هر بخش سایت هفته به هفته بهتر می‌شود: زمان کار هفتگی { num( data.settings.weekly_minutes ) } دقیقه، تبدیل { num( data.settings.slugs_per_week ) } آدرس در هفته و ترمز در افت کلیک بیش از { num( data.settings.brake_drop ) }٪. <a href="#/settings?tab=improve">تغییر</a>
						</p>
						<Trend history={ data.history } now={ data.now } />
					</section>
					<Tags t={ data.tags } reload={ load } />
					<Slugs key={ JSON.stringify( data.slugs.week.map( ( i ) => i.id + i.new ) ) } s={ data.slugs } reload={ load } />
					<section className="pp-card pp-card--section">
						<h2 className="pp-card__title"><span className="dashicons dashicons-update" aria-hidden="true" /> مقالات و لینک داخلی</h2>
						<p className="pp-muted">{ num( data.now.refresh ) } مطلب نیازمند بازنویسی، { num( data.now.orphans ) } مطلب یتیم و میانگین { num( data.now.inbound ) } لینک ورودی برای هر مقاله. برنامه هفتگی این دو بخش در نسخه بعد کامل می‌شود؛ تا آن زمان از «کارهای این هفته»، <a href="#/refresh">نیازمند بازنویسی</a> و <a href="#/links">صف لینک داخلی</a> استفاده کنید.</p>
					</section>
				</>
			) }
		</Page>
	);
}
