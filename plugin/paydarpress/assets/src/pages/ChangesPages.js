import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import PostPicker from '../components/PostPicker';
import { get, post, errorMessage } from '../lib/api';
import { num, ago, date } from '../lib/format';

const config = window.paydarpress || {};
const VERDICT_CLASS = { better: 'success', same: 'info', worse: 'danger', no_data: '' };

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
 * Changes PaydarPress made, with the Search Console result.
 */
export function ChangesPage() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		get( '/changes' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	return (
		<Page title="نتیجه تغییرات" helpKey="changes">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && 0 === data.items.length && <div className="pp-card"><EmptyState title="هنوز تغییری ثبت نشده است" text="هر بازنویسی، تغییر عنوان و متا، متن دسته و ادغامی که از پایدارپرس انجام شود اینجا ثبت می‌شود؛ پس از ورود خروجی بعدی سرچ کنسول، نتیجه‌اش سنجیده می‌شود." /></div> }
			{ data && data.items.length > 0 && (
				<>
					<p className="pp-muted">نتیجه هر تغییر وقتی سنجیده می‌شود که یک خروجی سرچ کنسول دست‌کم { num( data.min_days ) } روز بعد از تغییر را پوشش دهد. برای سنجش دقیق‌تر، خروجی «۲۸ روز گذشته» را حدود یک ماه بعد از تغییر وارد کنید؛ خروجی ۳ ماهه، روزهای قبل از تغییر را هم دارد.</p>
					<div className="pp-card pp-card--flush">
						<table className="pp-table">
							<thead><tr><th>صفحه</th><th>تغییر</th><th>قبل</th><th>بعد</th><th>نتیجه</th></tr></thead>
							<tbody>
								{ data.items.map( ( c ) => (
									<tr key={ c.id }>
										<td><a href={ c.edit_url }>{ c.title }</a><div className="pp-muted">{ ago( c.created_at ) }</div></td>
										<td><strong>{ c.kind_label }</strong><div className="pp-muted">{ c.summary }</div></td>
										<td><Metrics m={ c.before } /></td>
										<td><Metrics m={ c.after } /></td>
										<td>{ c.verdict ? <span className={ `pp-badge pp-badge--${ VERDICT_CLASS[ c.verdict ] }` }>{ c.verdict_label }</span> : <span className="pp-muted">سنجش پس از { date( c.measure_after ) }</span> }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
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
									<td>{ { merge: 'ادغام', manual: 'دستی', deleted: 'صفحه حذف‌شده' }[ r.reason ] || r.reason }</td>
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
