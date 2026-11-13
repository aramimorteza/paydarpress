import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, Spinner, TextareaControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import Page from '../components/Page';
import AiButton from '../components/AiButton';
import { topicsCommand, topicArticleCommand } from '../lib/aiCommands';
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
const SECTIONS = [ 'topics', 'articles', 'link_suggestions', 'term_descriptions', 'seo_updates' ];

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
			<Page title="ورود پاسخ هوش مصنوعی" helpKey="cm_import">
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
				SECTIONS.forEach( ( s ) => {
					sel[ s ] = ( p[ s ] || [] ).filter( ( i ) => ! i.duplicate && ! i.blocked ).map( ( i ) => i.index );
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
	const total = SECTIONS.reduce( ( sum, s ) => sum + count( s ), 0 );
	const terms = preview ? preview.term_descriptions || [] : [];
	const seoUpdates = preview ? preview.seo_updates || [] : [];

	return (
		<Page title="ورود پاسخ هوش مصنوعی" helpKey="cm_import">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

			{ result && (
				<section className="pp-card pp-card--section pp-result">
					<h2 className="pp-card__title">ورود انجام شد</h2>
					<p>
						{ num( result.topics ) } موضوع به «موضوعات پیشنهادی» و { num( result.articles.length ) } مقاله به‌صورت پیش‌نویس اضافه شد
						{ result.link_suggestions ? `؛ ${ num( result.link_suggestions ) } پیشنهاد لینک در صف تأیید قرار گرفت` : '' }
						{ result.terms ? `؛ متن ${ num( result.terms ) } دسته به‌روز شد` : '' }
						{ result.seo ? `؛ عنوان و توضیحات متای ${ num( result.seo ) } صفحه به‌روز شد` : '' }.
					</p>
					{ result.articles.length > 0 && (
						<ul className="pp-mini-list">
							{ result.articles.map( ( a ) => (
								<li key={ a.id }><a href={ a.edit_url }>{ a.title }</a> <span className="pp-muted">{ a.rewrite ? '(پیش‌نویس بازنویسی — در ویرایشگر بخوانید و «جایگزینی نوشته اصلی» را بزنید)' : '(پیش‌نویس — بازبینی و انتشار با شما)' }</span></li>
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
						<li>پاسخ JSON هوش مصنوعی را کپی کنید: اگر از دکمه‌های «تولید با هوش مصنوعی» و «کپی پرامپت کامل» استفاده کرده‌اید، پاسخ ChatGPT، Claude یا Gemini همان بلوک JSON است؛ در گفتگوی ContentMaster هم فرمان <strong>«خروجی پایدارپرس»</strong> را بفرستید.</li>
						<li>متن را اینجا بچسبانید (یا فایل ‎.json را انتخاب کنید).</li>
						<li>پیش‌نمایش را بررسی کنید و موارد دلخواه را وارد کنید. مقالات فقط پیش‌نویس می‌شوند.</li>
					</ol>
					<TextareaControl
						__nextHasNoMarginBottom
						label="پاسخ هوش مصنوعی یا خروجی ContentMaster"
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
							{ preview.articles.some( ( a ) => a.rewrite_id ) && <p className="pp-muted">بازنویسی‌ها پیش‌نویس جدا می‌شوند؛ نوشته منتشرشده فقط وقتی تغییر می‌کند که در ویرایشگر «جایگزینی نوشته اصلی» را بزنید.</p> }
							{ preview.articles.map( ( a ) => (
								<div key={ a.index } className={ `pp-import-item${ a.duplicate ? ' is-duplicate' : '' }` }>
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ a.rewrite_id ? `بازنویسی: ${ a.title }` : a.title }
										checked={ ( picked.articles || [] ).includes( a.index ) }
										disabled={ a.blocked }
										onChange={ ( on ) => toggle( 'articles', a.index, on ) }
									/>
									<div className="pp-import-meta">
										{ a.rewrite_id ? <span>جایگزین «{ a.rewrite_title }»</span> : <span>{ a.category_name || 'دسته پیش‌فرض' }</span> }
										<span>{ num( a.words ) } کلمه</span>
										<span>{ num( a.internal_links ) } لینک داخلی</span>
										{ ! a.rewrite_id && <span>نویسنده: { a.author_name }</span> }
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

					{ terms.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">متن معرفی دسته‌ها ({ num( terms.length ) })</h2>
							<p className="pp-muted">با ورود، متن بالای صفحه این دسته‌ها عوض می‌شود. متن قبلی ذخیره می‌ماند.</p>
							{ terms.map( ( t ) => (
								<div key={ t.index } className="pp-import-item">
									<CheckboxControl __nextHasNoMarginBottom label={ `«${ t.name }» — ${ num( t.words ) } کلمه` } checked={ ( picked.term_descriptions || [] ).includes( t.index ) } disabled={ t.blocked } onChange={ ( on ) => toggle( 'term_descriptions', t.index, on ) } />
									{ t.current && <p className="pp-muted pp-import-excerpt"><strong>فعلی:</strong> { t.current }</p> }
									<p className="pp-import-excerpt"><strong>جدید:</strong> { t.new_preview }…</p>
									<Checks checks={ t.checks } />
								</div>
							) ) }
						</section>
					) }

					{ seoUpdates.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">عنوان و توضیحات متا ({ num( seoUpdates.length ) })</h2>
							<p className="pp-muted">در افزونه سئوی سایت ذخیره می‌شود؛ مقدار قبلی نگه داشته می‌شود.</p>
							{ seoUpdates.map( ( u ) => (
								<div key={ u.index } className="pp-import-item">
									<CheckboxControl __nextHasNoMarginBottom label={ u.title || u.url } checked={ ( picked.seo_updates || [] ).includes( u.index ) } disabled={ u.blocked } onChange={ ( on ) => toggle( 'seo_updates', u.index, on ) } />
									<table className="pp-table pp-table--compact pp-seo-diff">
										<thead><tr><th></th><th>فعلی</th><th>جدید</th></tr></thead>
										<tbody>
											<tr><th>عنوان</th><td>{ u.current_title || '—' }</td><td>{ u.seo_title || '(بدون تغییر)' } <span className="pp-muted">({ num( ( u.seo_title || '' ).length ) } نویسه)</span></td></tr>
											<tr><th>توضیحات</th><td>{ u.current_description || '—' }</td><td>{ u.meta_description || '(بدون تغییر)' } <span className="pp-muted">({ num( ( u.meta_description || '' ).length ) } نویسه)</span></td></tr>
										</tbody>
									</table>
									<Checks checks={ u.checks } />
								</div>
							) ) }
						</section>
					) }

					<div className="pp-sticky-actions">
						<span className="pp-muted">
							{ num( total ) } مورد انتخاب شده است.
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
	const load = () => get( '/topics', { status, page, per_page: 30 } ).then( setData ).catch( () => {} );
	useEffect( () => {
		const onImported = () => load();
		window.addEventListener( 'paydarpress:imported', onImported );
		return () => window.removeEventListener( 'paydarpress:imported', onImported );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status, page ] );
	useEffect( () => {
		setData( null );
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ status, page ] );

	const act = ( id, action ) => {
		const path = 'delete' === action ? `/topics/${ id }/delete` : `/topics/${ id }`;
		post( path, 'delete' === action ? {} : { status: action } ).then( load );
	};

	return (
		<Page
			title="موضوعات"
			helpKey="topics"
			actions={
				canManage && (
					<>
						<AiButton command={ topicsCommand() } small={ false } variant="primary" />
						<Button variant="secondary" href="#/cm-import">ورود پاسخ هوش مصنوعی</Button>
					</>
				)
			}
		>
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
						text="با «پیشنهاد موضوع با هوش مصنوعی» یک پرامپت آماده برای ChatGPT، Claude یا Gemini بگیرید و پاسخ را در «ورود پاسخ هوش مصنوعی» بچسبانید. از «جست‌وجوهای بی‌مقاله» سرچ کنسول هم می‌توانید موضوع اضافه کنید."
						action={ canManage && <AiButton command={ topicsCommand() } small={ false } variant="primary" /> }
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
										{ 'written' !== t.status && 'rejected' !== t.status && <AiButton command={ topicArticleCommand( t ) } /> }
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
