import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import PostPicker from '../components/PostPicker';
import { get, post, errorMessage } from '../lib/api';
import { num, ago, date } from '../lib/format';

const config = window.paydarpress || {};
const VERDICT_CLASS = { better: 'success', same: 'info', worse: 'danger', no_data: '', unmeasured: '' };

/**
 * Metrics cell.
 *
 * @param {Object} props
 * @param {Object} props.m Metrics.
 */
function Metrics( { m } ) {
	if ( ! m ) {
		return <span className="pp-muted">—</span>;
	}
	return <span>{ num( m.clicks ) } کلیک · { num( m.impressions ) } نمایش · جایگاه { num( m.position ) }</span>;
}

/**
 * Small position trail: 9.4 → 7.1 → 4.8 (lower is better).
 *
 * @param {Object} props
 * @param {Object} props.c Change.
 */
function Trail( { c } ) {
	const points = [ ...( c.before ? [ c.before.position ] : [] ), ...( c.history || [] ).map( ( h ) => h.position ) ].filter( ( p ) => p > 0 );
	if ( points.length < 2 ) {
		return null;
	}
	const max = Math.max( ...points );
	const min = Math.min( ...points );
	if ( max === min ) {
		return null;
	}
	const w = 90;
	const h = 22;
	const xy = points.map( ( p, i ) => `${ ( i / ( points.length - 1 ) ) * w },${ max === min ? h / 2 : ( ( p - min ) / ( max - min ) ) * h }` );
	const up = points[ points.length - 1 ] < points[ 0 ];
	return (
		<span className="pp-trail" title={ `رتبه: ${ points.map( ( p ) => num( p ) ).join( ' ← ' ) }` }>
			<svg width={ w } height={ h + 2 } viewBox={ `0 -1 ${ w } ${ h + 2 }` } aria-hidden="true"><polyline points={ xy.join( ' ' ) } fill="none" stroke={ up ? '#1e7e34' : '#b32d2e' } strokeWidth="2" /></svg>
			<span className="pp-muted">{ points.map( ( p ) => num( p ) ).join( ' ← ' ) }</span>
		</span>
	);
}

/**
 * Changes PaydarPress made: why, numbers before and now, the trail and the verdict.
 */
export function ChangesPage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ f, setF ] = useState( { kind: '', verdict: '', days: 0 } );
	const query = `kind=${ f.kind }&verdict=${ f.verdict }&days=${ f.days }`;

	useEffect( () => {
		get( `/changes?${ query }` ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ query ] );

	const download = () =>
		get( `/changes/export?${ query }` )
			.then( ( r ) => {
				const url = window.URL.createObjectURL( new window.Blob( [ r.csv ], { type: 'text/csv;charset=utf-8' } ) );
				const a = document.createElement( 'a' );
				a.href = url;
				a.download = r.name;
				document.body.appendChild( a );
				a.click();
				a.remove();
				window.URL.revokeObjectURL( url );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );

	const s = data && data.summary;
	const measured = s ? s.better + s.same + s.worse : 0;

	return (
		<Page title="نتیجه تغییرات" helpKey="changes">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<section className="pp-card pp-card--section">
						{ s.total > 0 && (
							<p className="pp-changes__summary">
								<strong>{ num( s.total ) } تغییر</strong>
								{ measured > 0 && <> · { num( measured ) } سنجیده شد: <span className="pp-ok">{ num( s.better ) } بهتر</span>، { num( s.same ) } بی‌تغییر، <span className="pp-missing">{ num( s.worse ) } بدتر</span></> }
								{ measured > 0 && 0 !== s.clicks && <> · { s.clicks > 0 ? '+' : '−' }{ num( Math.abs( s.clicks ) ) } کلیک در بازه سرچ کنسول نسبت به پیش از تغییرها</> }
								{ s.pending > 0 && <span className="pp-muted"> · { num( s.pending ) } هنوز زود است</span> }
							</p>
						) }
						<div className="pp-classify__options">
							<SelectControl __nextHasNoMarginBottom label="نوع" value={ f.kind } options={ [ { value: '', label: 'همه' }, ...Object.entries( data.kinds ).map( ( [ value, label ] ) => ( { value, label } ) ) ] } onChange={ ( v ) => setF( { ...f, kind: v } ) } />
							<SelectControl __nextHasNoMarginBottom label="نتیجه" value={ f.verdict } options={ [ { value: '', label: 'همه' }, { value: 'pending', label: 'هنوز زود است' }, ...Object.entries( data.verdicts ).map( ( [ value, label ] ) => ( { value, label } ) ) ] } onChange={ ( v ) => setF( { ...f, verdict: v } ) } />
							<SelectControl __nextHasNoMarginBottom label="بازه" value={ String( f.days ) } options={ [ { value: '0', label: 'همه' }, { value: '30', label: '۳۰ روز اخیر' }, { value: '90', label: '۳ ماه اخیر' }, { value: '180', label: '۶ ماه اخیر' } ] } onChange={ ( v ) => setF( { ...f, days: Number( v ) } ) } />
							{ data.items.length > 0 && <Button variant="secondary" onClick={ download }>دانلود گزارش (CSV)</Button> }
						</div>
						<p className="pp-muted">هر تغییر با دلیلش و آمار سرچ کنسول همان لحظه ثبت می‌شود. با هر بارگذاری تازه سرچ کنسول یک نقطه به مسیرش اضافه می‌شود و از { num( data.min_days ) } روز بعد نتیجه می‌گیرد. گزارش CSV فقط آدرس، نوع تغییر، دلیل و اعداد دارد.</p>
					</section>
					{ 0 === data.items.length ? (
						<div className="pp-card"><EmptyState title="تغییری با این فیلتر نیست" text="بازنویسی، عنوان و متا، متن دسته، ادغام، لینک داخلی، ریدایرکت ۴۰۴، خوشه و تصمیم دسته و برچسب اینجا ثبت می‌شوند." /></div>
					) : (
						<div className="pp-card pp-card--flush">
							<table className="pp-table pp-changes">
								<thead><tr><th>صفحه و تغییر</th><th>چرا</th><th>قبل</th><th>اکنون</th><th>نتیجه</th></tr></thead>
								<tbody>
									{ data.items.map( ( c ) => (
										<tr key={ c.id }>
											<td>
												<strong>{ c.kind_label }</strong>: { c.edit_url ? <a href={ c.edit_url }>{ c.title }</a> : c.title }
												{ c.url && <> <a className="pp-task__view" href={ c.url } target="_blank" rel="noreferrer">مشاهده ↗</a></> }
												<div className="pp-muted">{ c.summary } · { ago( c.created_at ) }</div>
											</td>
											<td className="pp-changes__why">{ c.reason || <span className="pp-muted">—</span> }</td>
											<td><Metrics m={ c.before } /></td>
											<td><Metrics m={ c.now } /><Trail c={ c } /></td>
											<td>
												{ c.verdict ? <span className={ `pp-badge pp-badge--${ VERDICT_CLASS[ c.verdict ] || '' }` }>{ c.verdict_label }</span> : ( c.measurable ? <span className="pp-muted">هنوز زود است؛ از { date( c.measure_after ) }</span> : <span className="pp-muted">بدون سنجش</span> ) }
												{ c.overlap > 0 && <div className="pp-muted" title="در ۲۸ روز اطراف این تغییر، تغییر دیگری هم روی همین صفحه انجام شده؛ نتیجه را نمی‌شود فقط به یکی نسبت داد.">⚠ { num( c.overlap ) } تغییر هم‌زمان</div> }
												{ c.revert_url && <div><a href={ c.revert_url }>بازگشت به نسخه قبل</a></div> }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) }
		</Page>
	);
}

/**
 * 301 redirects managed by PaydarPress.
 */
export function RedirectsPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ target, setTarget ] = useState( null );

	const load = () => get( '/redirects' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	useEffect( () => {
		load();
		const m = window.location.hash.match( /[?&]source=([^&]+)/ );
		if ( m ) {
			setSource( decodeURIComponent( m[ 1 ] ) );
		}
	}, [] );

	const add = () => {
		setError( '' );
		post( '/redirects', { source, target: target.url } )
			.then( ( r ) => {
				setData( r );
				setSource( '' );
				setTarget( null );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	};
	const del = ( id ) => post( '/redirects/delete', { id } ).then( setData );

	return (
		<Page title="ریدایرکت‌ها" helpKey="redirects">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ data && data.owner && (
				<Notice status="info" isDismissible={ false }>
					ریدایرکت‌های سایت شما در <a href={ data.owner.url }>{ data.owner.name }</a> مدیریت می‌شود. پایدارپرس ریدایرکت تکراری نمی‌سازد: پس از ادغام یا تغییر آدرس بررسی می‌کند آدرس قبلی آنجا ریدایرکت شده یا نه، و فقط اگر نشده بود خودش ریدایرکت می‌سازد. ریدایرکت‌های پایدارپرس فقط روی آدرس‌هایی اجرا می‌شوند که صفحه‌ای ندارند، پس با آن افزونه تداخلی ندارند.
				</Notice>
			) }
			{ canManage && (
				<section className="pp-card pp-card--section">
					<h2 className="pp-card__title">ریدایرکت ۳۰۱ تازه</h2>
					<p className="pp-muted">برای آدرسی که دیگر صفحه ندارد (حذف شده یا ادغام شده) اما هنوز از گوگل یا لینک‌های بیرونی بازدید می‌گیرد. ریدایرکت فقط وقتی اجرا می‌شود که آن آدرس واقعاً صفحه‌ای نداشته باشد.</p>
					<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label="آدرس قدیمی" value={ source } onChange={ setSource } dir="ltr" placeholder="/old-article/" />
					{ target ? <p>مقصد: <strong>{ target.title }</strong> <Button variant="link" onClick={ () => setTarget( null ) }>تغییر</Button></p> : <PostPicker onPick={ setTarget } label="مقصد را جست‌وجو کنید" /> }
					<Button variant="primary" onClick={ add } disabled={ ! source.trim() || ! target }>ساخت ریدایرکت</Button>
				</section>
			) }
			{ ! data && <Spinner /> }
			{ data && data.items.length > 0 && (
				<div className="pp-card pp-card--flush">
					<table className="pp-table">
						<thead><tr><th>از</th><th>به</th><th>دلیل</th><th className="pp-num">بازدید</th><th></th></tr></thead>
						<tbody>
							{ data.items.map( ( r ) => (
								<tr key={ r.id }>
									<td dir="ltr">{ r.source }</td>
									<td dir="ltr">{ r.target }</td>
									<td>{ { merge: 'ادغام', manual: 'دستی', deleted: 'صفحه حذف‌شده', gsc404: 'صفحه ۴۰۴ گوگل', term: 'دسته یا برچسب', slug: 'تغییر آدرس' }[ r.reason ] || r.reason }</td>
									<td className="pp-num">{ num( r.hits ) }{ r.last_hit && <div className="pp-muted">{ ago( r.last_hit ) }</div> }</td>
									<td>{ canManage && <Button variant="link" isDestructive onClick={ () => del( r.id ) }>حذف</Button> }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ data && 0 === data.items.length && <div className="pp-card"><EmptyState title="ریدایرکتی ثبت نشده است" text="ادغام مقاله‌ها و آدرس‌های حذف‌شده‌ای که هنوز از گوگل بازدید می‌گیرند (سرچ کنسول ← آدرس‌های بدون محتوا) اینجا ریدایرکت می‌شوند." /></div> }
		</Page>
	);
}
