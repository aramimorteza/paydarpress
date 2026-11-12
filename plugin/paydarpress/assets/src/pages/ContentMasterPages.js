import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, TextareaControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { get, post, errorMessage } from '../lib/api';
import { num, ago } from '../lib/format';

const config = window.paydarpress || {};

const LEVEL_CLASS = {
	duplicate: 'pp-check--duplicate',
	error: 'pp-check--error',
	warning: 'pp-check--warning',
	info: 'pp-check--info',
};

const INTENT_LABELS = {
	informational: 'اطلاعاتی',
	educational: 'آموزشی',
	'how-to': 'راهنمای عملی',
	commercial: 'تجاری',
	support: 'پشتیبانی',
	transactional: 'اقدام',
};

/**
 * Checks list of one preview item.
 *
 * @param {Object} props
 * @param {Array}  props.checks Checks.
 */
function Checks( { checks } ) {
	if ( ! checks.length ) {
		return <span className="pp-check pp-check--ok">بدون مشکل</span>;
	}
	return (
		<ul className="pp-checks">
			{ checks.map( ( c, i ) => (
				<li key={ i } className={ `pp-check ${ LEVEL_CLASS[ c.level ] || '' }` }>{ c.text }</li>
			) ) }
		</ul>
	);
}

/**
 * Import from ContentMaster: paste or upload, preview, select, commit.
 */
export function ImportPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const fileInput = useRef( null );
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	const [ result, setResult ] = useState( null );

	if ( ! canManage ) {
		return (
			<Page title="ورود از ContentMaster" helpKey="cm_import">
				<div className="pp-card"><EmptyState title="دسترسی ندارید" text="ورود محتوا فقط برای مدیر سایت مجاز است." /></div>
			</Page>
		);
	}

	const startPreview = ( data ) => {
		setBusy( true );
		setError( '' );
		setResult( null );
		apiFetch( { path: `/${ config.restNamespace }/cm/preview`, method: 'POST', ...data } )
			.then( ( p ) => {
				setPreview( p );
				// Default selection: everything that is neither a duplicate nor blocked.
				const sel = {};
				[ 'topics', 'articles', 'link_suggestions' ].forEach( ( s ) => {
					sel[ s ] = p[ s ].filter( ( i ) => ! i.duplicate && ! i.blocked ).map( ( i ) => i.index );
				} );
				setPicked( sel );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const fromFile = ( file ) => {
		if ( ! file ) {
			return;
		}
		const body = new window.FormData();
		body.append( 'file', file, file.name );
		startPreview( { body } );
	};

	const toggle = ( section, index, on ) => {
		const list = picked[ section ] || [];
		setPicked( { ...picked, [ section ]: on ? [ ...list, index ] : list.filter( ( i ) => i !== index ) } );
	};

	const commit = () => {
		setBusy( true );
		setError( '' );
		post( '/cm/commit', { token: preview.token, ...picked } )
			.then( ( r ) => {
				setResult( r );
				setPreview( null );
				setText( '' );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	const count = ( s ) => ( picked[ s ] || [] ).length;
	const total = count( 'topics' ) + count( 'articles' ) + count( 'link_suggestions' );

	return (
		<Page title="ورود از ContentMaster" helpKey="cm_import">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

			{ result && (
				<section className="pp-card pp-card--section pp-result">
					<h2 className="pp-card__title">ورود انجام شد</h2>
					<p>
						{ num( result.topics ) } موضوع به «موضوعات پیشنهادی» و { num( result.articles.length ) } مقاله به‌صورت پیش‌نویس اضافه شد
						{ result.link_suggestions ? `؛ ${ num( result.link_suggestions ) } پیشنهاد لینک در صف تأیید قرار گرفت` : '' }.
					</p>
					{ result.articles.length > 0 && (
						<ul className="pp-mini-list">
							{ result.articles.map( ( a ) => (
								<li key={ a.id }><a href={ a.edit_url }>{ a.title }</a> <span className="pp-muted">(پیش‌نویس — بازبینی و انتشار با شما)</span></li>
							) ) }
						</ul>
					) }
					{ result.errors.map( ( e ) => <p key={ e } className="pp-missing">{ e }</p> ) }
					<p className="pp-muted">در صفحه ویرایش هر پیش‌نویس، جعبه «پایدارپرس» پرامپت و متن جایگزین تصویر شاخص و پرسش‌های متداول را نشان می‌دهد.</p>
					<div className="pp-form__actions pp-form__actions--start">
						<Button variant="secondary" href="#/topics">موضوعات پیشنهادی</Button>
						<Button variant="tertiary" onClick={ () => setResult( null ) }>ورود متن دیگر</Button>
					</div>
				</section>
			) }

			{ ! preview && ! result && (
				<section className="pp-card pp-card--section">
					<h2 className="pp-card__title">متن خروجی را بچسبانید</h2>
					<ol className="pp-howto">
						<li>در گفتگوی ContentMaster همین سایت، فرمان <strong>«خروجی پایدارپرس»</strong> را بفرستید.</li>
						<li>دکمه کپی بلوک کد را بزنید و متن را اینجا بچسبانید (یا فایل ‎.json را انتخاب کنید).</li>
						<li>پیش‌نمایش را بررسی کنید و موارد دلخواه را وارد کنید. مقالات فقط پیش‌نویس می‌شوند.</li>
					</ol>
					<TextareaControl
						__nextHasNoMarginBottom
						label="متن خروجی ContentMaster"
						hideLabelFromVision
						className="pp-paste"
						rows={ 10 }
						dir="ltr"
						placeholder={ '```json\n{ "schema": "paydarpress-import/1", ... }\n```' }
						value={ text }
						onChange={ setText }
					/>
					<div className="pp-form__actions pp-form__actions--start">
						<Button variant="primary" onClick={ () => startPreview( { data: { text } } ) } isBusy={ busy } disabled={ busy || ! text.trim() } __next40pxDefaultSize>
							پیش‌نمایش
						</Button>
						<Button variant="secondary" onClick={ () => fileInput.current && fileInput.current.click() } disabled={ busy } __next40pxDefaultSize>
							انتخاب فایل
						</Button>
						<input ref={ fileInput } type="file" accept=".json,.txt,application/json,text/plain" hidden onChange={ ( e ) => { fromFile( e.target.files[ 0 ] ); e.target.value = ''; } } />
					</div>
				</section>
			) }

			{ busy && ! preview && <Spinner /> }

			{ preview && (
				<>
					{ preview.warnings.map( ( w ) => <Notice key={ w } status="warning" isDismissible={ false }>{ w }</Notice> ) }
					<p className="pp-muted pp-filter-hint">
						موارد تکراری و دارای خطا به‌طور پیش‌فرض انتخاب نشده‌اند. { preview.meta.generator && `منبع: ${ preview.meta.generator }` }
					</p>

					{ preview.articles.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">مقالات ({ num( preview.articles.length ) }) — به‌صورت پیش‌نویس</h2>
							{ preview.articles.map( ( a ) => (
								<div key={ a.index } className={ `pp-import-item${ a.duplicate ? ' is-duplicate' : '' }` }>
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ a.title }
										checked={ ( picked.articles || [] ).includes( a.index ) }
										disabled={ a.blocked }
										onChange={ ( on ) => toggle( 'articles', a.index, on ) }
									/>
									<div className="pp-import-meta">
										<span>{ a.category_name || 'دسته پیش‌فرض' }</span>
										<span>{ num( a.words ) } کلمه</span>
										<span>{ num( a.internal_links ) } لینک داخلی</span>
										<span>نویسنده: { a.author_name }</span>
										{ a.seo.focus_keyword && <span>کلمه کلیدی: { a.seo.focus_keyword }</span> }
										{ a.faq.length > 0 && <span>{ num( a.faq.length ) } پرسش متداول</span> }
										{ a.reverse_links.length > 0 && <span>{ num( a.reverse_links.filter( ( r ) => r.source_id ).length ) } پیشنهاد لینک به این مقاله</span> }
									</div>
									<p className="pp-muted pp-import-excerpt">{ a.html_preview }…</p>
									<Checks checks={ a.checks } />
								</div>
							) ) }
							{ 'none' === preview.seo_plugin && <p className="pp-muted">افزونه سئو فعال نیست؛ عنوان، توضیحات و کلمه کلیدی در جعبه پایدارپرس صفحه ویرایش نوشته نمایش داده می‌شوند.</p> }
						</section>
					) }

					{ preview.topics.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">موضوعات ({ num( preview.topics.length ) })</h2>
							{ preview.topics.map( ( t ) => (
								<div key={ t.index } className={ `pp-import-item${ t.duplicate ? ' is-duplicate' : '' }` }>
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ t.title }
										checked={ ( picked.topics || [] ).includes( t.index ) }
										onChange={ ( on ) => toggle( 'topics', t.index, on ) }
									/>
									<div className="pp-import-meta">
										{ t.category_name && <span>{ t.category_name }</span> }
										{ t.focus_keyword && <span>کلمه کلیدی: { t.focus_keyword }</span> }
										{ t.intent && <span>{ INTENT_LABELS[ t.intent ] || t.intent }</span> }
									</div>
									<Checks checks={ t.checks } />
								</div>
							) ) }
						</section>
					) }

					{ preview.link_suggestions.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">پیشنهادهای لینک داخلی ({ num( preview.link_suggestions.length ) })</h2>
							<p className="pp-muted">این پیشنهادها در صف تأیید لینک قرار می‌گیرند و تا تأیید شما در نوشته‌ای درج نمی‌شوند.</p>
							{ preview.link_suggestions.map( ( s ) => (
								<div key={ s.index } className="pp-import-item">
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ `«${ s.anchor }»: ${ s.source_title || s.source_url } ← ${ s.target_title || s.target_url }` }
										checked={ ( picked.link_suggestions || [] ).includes( s.index ) }
										disabled={ s.blocked }
										onChange={ ( on ) => toggle( 'link_suggestions', s.index, on ) }
									/>
									<Checks checks={ s.checks } />
								</div>
							) ) }
						</section>
					) }

					<div className="pp-sticky-actions">
						<span className="pp-muted">
							{ num( count( 'articles' ) ) } مقاله، { num( count( 'topics' ) ) } موضوع و { num( count( 'link_suggestions' ) ) } پیشنهاد لینک انتخاب شده است.
						</span>
						<div className="pp-sticky-actions__buttons">
							<Button variant="tertiary" onClick={ () => setPreview( null ) } disabled={ busy }>انصراف</Button>
							<Button variant="primary" onClick={ commit } isBusy={ busy } disabled={ busy || 0 === total } __next40pxDefaultSize>وارد کن</Button>
						</div>
					</div>
				</>
			) }
		</Page>
	);
}

const STATUS_TABS = [
	{ key: '', label: 'همه' },
	{ key: 'new', label: 'جدید' },
	{ key: 'accepted', label: 'پذیرفته' },
	{ key: 'written', label: 'نوشته شد' },
	{ key: 'rejected', label: 'ردشده' },
];

/**
 * Suggested topics.
 */
export function TopicsPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ copied, setCopied ] = useState( null );

	const load = () => get( '/topics', { status, page, per_page: 30 } ).then( setData ).catch( () => {} );
	useEffect( () => {
		setData( null );
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status, page ] );

	const act = ( id, action ) => {
		const path = 'delete' === action ? `/topics/${ id }/delete` : `/topics/${ id }`;
		post( path, 'delete' === action ? {} : { status: action } ).then( load );
	};

	const copy = ( topic ) => {
		const command = `مقاله: ${ topic.title }`;
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( command ).then( () => {
				setCopied( topic.id );
				setTimeout( () => setCopied( null ), 1500 );
			} );
		}
	};

	return (
		<Page title="موضوعات پیشنهادی" helpKey="topics" actions={ canManage && <Button variant="primary" href="#/cm-import">ورود از ContentMaster</Button> }>
			<div className="pp-tabs" role="tablist" aria-label="وضعیت موضوع">
				{ STATUS_TABS.map( ( t ) => (
					<button
						key={ t.key || 'all' }
						type="button"
						role="tab"
						aria-selected={ status === t.key }
						className={ `pp-tab${ status === t.key ? ' is-active' : '' }` }
						onClick={ () => {
							setStatus( t.key );
							setPage( 1 );
						} }
					>
						{ t.label }
						{ data && t.key && <span className="pp-tab__count">{ num( data.counts[ t.key ] ) }</span> }
					</button>
				) ) }
			</div>

			{ ! data && <Spinner /> }

			{ data && 0 === data.total && (
				<div className="pp-card">
					<EmptyState
						title="موضوعی ثبت نشده است"
						text="در ContentMaster با «موضوع جدید» فهرست موضوع بگیرید، سپس با «خروجی پایدارپرس» آن را اینجا وارد کنید."
						action={ canManage && <a className="button button-primary" href="#/cm-import">ورود از ContentMaster</a> }
					/>
				</div>
			) }

			{ data && data.total > 0 && (
				<div className="pp-card pp-card--flush">
					<table className="pp-table">
						<thead>
							<tr>
								<th>موضوع</th>
								<th>دسته</th>
								<th>کلمه کلیدی</th>
								<th>وضعیت</th>
								<th>اقدام</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( t ) => (
								<tr key={ t.id } className={ 'rejected' === t.status ? 'is-muted' : '' }>
									<td>
										<strong>{ t.title }</strong>
										{ t.notes && <div className="pp-muted">{ t.notes }</div> }
										<div className="pp-muted">{ INTENT_LABELS[ t.intent ] || '' } { t.intent && '·' } { ago( t.created_at ) }</div>
									</td>
									<td>{ t.category || '—' }</td>
									<td>{ t.focus_keyword || '—' }</td>
									<td>
										<span className={ `pp-badge pp-topic-${ t.status }` }>{ t.status_label }</span>
										{ t.post && <div><a href={ t.post.edit_url }>{ 'publish' === t.post.status ? 'مشاهده نوشته' : 'ویرایش پیش‌نویس' }</a></div> }
									</td>
									<td className="pp-actions">
										{ 'written' !== t.status && (
											<Button variant="secondary" size="small" onClick={ () => copy( t ) } title="فرمان را در ContentMaster بچسبانید">
												{ copied === t.id ? 'کپی شد' : 'کپی فرمان مقاله' }
											</Button>
										) }
										{ canManage && 'new' === t.status && <Button variant="tertiary" size="small" onClick={ () => act( t.id, 'accepted' ) }>پذیرفتن</Button> }
										{ canManage && [ 'new', 'accepted' ].includes( t.status ) && <Button variant="tertiary" size="small" onClick={ () => act( t.id, 'rejected' ) }>رد</Button> }
										{ canManage && 'rejected' === t.status && <Button variant="tertiary" size="small" onClick={ () => act( t.id, 'new' ) }>بازگرداندن</Button> }
										{ canManage && 'rejected' === t.status && <Button variant="link" isDestructive size="small" onClick={ () => act( t.id, 'delete' ) }>حذف</Button> }
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
		</Page>
	);
}
