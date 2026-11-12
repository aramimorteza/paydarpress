import { useEffect, useState } from '@wordpress/element';
import { Button, Modal, SelectControl, SearchControl, Spinner, Notice } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { get, errorMessage } from '../lib/api';
import { num, date, TYPE_LABELS, STATUS_LABELS, LINK_STATE_LABELS } from '../lib/format';

const FILTERS = [
	{ key: '', label: 'همه', count: 'total' },
	{ key: 'orphan', label: 'بدون لینک ورودی', count: 'orphan', hint: 'هیچ نوشته‌ای به این‌ها لینک نداده است.' },
	{ key: 'no_links', label: 'بدون لینک داخلی', count: 'no_links', hint: 'این‌ها به هیچ صفحه دیگری از سایت لینک نمی‌دهند.' },
	{ key: 'no_keyword', label: 'بدون کلمه کلیدی', count: 'no_keyword', hint: 'کلمه کلیدی کانونی در افزونه سئو تعیین نشده است.' },
	{ key: 'no_meta', label: 'بدون توضیحات متا', count: 'no_meta' },
	{ key: 'no_image', label: 'بدون تصویر شاخص', count: 'no_image' },
	{ key: 'alt', label: 'تصویر بدون متن جایگزین', count: 'alt' },
];

const SORTS = [
	{ value: 'modified_at:desc', label: 'آخرین ویرایش' },
	{ value: 'internal_in:asc', label: 'کمترین لینک ورودی' },
	{ value: 'word_count:asc', label: 'کم‌حجم‌ترین' },
	{ value: 'word_count:desc', label: 'پرحجم‌ترین' },
	{ value: 'published_at:asc', label: 'قدیمی‌ترین انتشار' },
];

/**
 * Content inventory with filters, search, sorting, pagination and a detail panel.
 */
export default function Inventory() {
	const [ filter, setFilter ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ sort, setSort ] = useState( 'modified_at:desc' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState( null );

	useEffect( () => {
		const [ orderby, order ] = sort.split( ':' );
		setLoading( true );
		const handle = setTimeout( () => {
			get( '/inventory', { filter, type, search, orderby, order, page, per_page: 20 } )
				.then( ( result ) => {
					setData( result );
					setError( '' );
				} )
				.catch( ( e ) => setError( errorMessage( e ) ) )
				.finally( () => setLoading( false ) );
		}, search ? 300 : 0 );
		return () => clearTimeout( handle );
	}, [ filter, type, search, sort, page ] );

	const counts = ( data && data.counts ) || {};
	const activeFilter = FILTERS.find( ( f ) => f.key === filter );
	const typeOptions = [
		{ value: '', label: 'همه انواع' },
		{ value: 'post', label: 'نوشته‌ها' },
		{ value: 'page', label: 'برگه‌ها' },
		...( window.paydarpress && window.paydarpress.modules && window.paydarpress.modules.woocommerce ? [ { value: 'product', label: 'محصولات' } ] : [] ),
	];

	return (
		<Page title="فهرست محتوا" helpKey="inventory">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ data && 0 === counts.total && ! loading ? (
				<div className="pp-card">
					<EmptyState
						title="فهرست محتوا خالی است"
						text="ابتدا سایت را اسکن کنید تا نوشته‌ها و برگه‌ها اینجا نمایش داده شوند."
						action={ <a className="button button-primary" href="#/welcome">شروع اسکن</a> }
					/>
				</div>
			) : (
				<>
					<div className="pp-tabs" role="tablist">
						{ FILTERS.map( ( f ) => (
							<button
								key={ f.key || 'all' }
								type="button"
								role="tab"
								aria-selected={ filter === f.key }
								className={ `pp-tab${ filter === f.key ? ' is-active' : '' }` }
								onClick={ () => {
									setFilter( f.key );
									setPage( 1 );
								} }
							>
								{ f.label }
								<span className="pp-tab__count">{ num( counts[ f.count ] ) }</span>
							</button>
						) ) }
					</div>
					{ activeFilter && activeFilter.hint && <p className="pp-muted pp-filter-hint">{ activeFilter.hint }</p> }

					<div className="pp-toolbar">
						<SearchControl
							__nextHasNoMarginBottom
							value={ search }
							onChange={ ( value ) => {
								setSearch( value );
								setPage( 1 );
							} }
							placeholder="جستجو در عنوان یا کلمه کلیدی…"
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label="نوع"
							hideLabelFromVision
							value={ type }
							options={ typeOptions }
							onChange={ ( value ) => {
								setType( value );
								setPage( 1 );
							} }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label="مرتب‌سازی"
							hideLabelFromVision
							value={ sort }
							options={ SORTS }
							onChange={ setSort }
						/>
						{ loading && <Spinner /> }
					</div>

					<div className="pp-card pp-card--flush">
						<table className="pp-table">
							<thead>
								<tr>
									<th>عنوان</th>
									<th>نوع</th>
									<th>دسته</th>
									<th>کلمه کلیدی</th>
									<th className="pp-num">کلمات</th>
									<th className="pp-num" title="لینک‌های ورودی از محتوای دیگر / لینک‌های خروجی داخلی">لینک ورودی / خروجی</th>
									<th className="pp-num">تصاویر</th>
									<th>آخرین ویرایش</th>
								</tr>
							</thead>
							<tbody>
								{ data &&
									data.items.map( ( item ) => (
										<tr key={ item.id }>
											<td>
												<button type="button" className="pp-link" onClick={ () => setSelected( item.id ) }>
													{ item.title || '(بدون عنوان)' }
												</button>
												{ 'publish' !== item.status && <span className="pp-badge">{ STATUS_LABELS[ item.status ] || item.status }</span> }
												{ item.cornerstone && <span className="pp-badge pp-badge--info">ستون</span> }
												{ item.noindex && <span className="pp-badge pp-badge--warning">noindex</span> }
											</td>
											<td>{ TYPE_LABELS[ item.type ] || item.type }</td>
											<td>{ item.primary_term || '—' }</td>
											<td>{ item.focus_keyword || <span className="pp-missing">ندارد</span> }</td>
											<td className="pp-num">{ num( item.word_count ) }</td>
											<td className="pp-num">
												<span className={ 0 === item.internal_in ? 'pp-missing' : '' }>{ num( item.internal_in ) }</span>
												{ ' / ' }
												<span className={ 0 === item.internal_out ? 'pp-missing' : '' }>{ num( item.internal_out ) }</span>
											</td>
											<td className="pp-num">
												{ item.featured_image ? '✓' : <span className="pp-missing">بدون شاخص</span> }
												{ item.inline_missing_alt > 0 && <span className="pp-badge pp-badge--warning">متن جایگزین ناقص: { num( item.inline_missing_alt ) }</span> }
											</td>
											<td>{ date( item.modified_at ) }</td>
										</tr>
									) ) }
							</tbody>
						</table>
						{ data && 0 === data.items.length && ! loading && <p className="pp-empty__text pp-table-empty">موردی با این فیلتر پیدا نشد.</p> }
					</div>

					{ data && data.total_pages > 1 && (
						<div className="pp-pagination">
							<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
								قبلی
							</Button>
							<span>
								صفحه { num( page ) } از { num( data.total_pages ) } ({ num( data.total ) } مورد)
							</span>
							<Button variant="secondary" disabled={ page >= data.total_pages } onClick={ () => setPage( page + 1 ) }>
								بعدی
							</Button>
						</div>
					) }
				</>
			) }

			{ selected && <ItemDetails id={ selected } onClose={ () => setSelected( null ) } /> }
		</Page>
	);
}

/**
 * Detail modal: outline, inbound and outbound links.
 *
 * @param {Object}   props
 * @param {number}   props.id      Post ID.
 * @param {Function} props.onClose Close handler.
 */
function ItemDetails( { id, onClose } ) {
	const [ item, setItem ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		get( `/inventory/${ id }` )
			.then( setItem )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ id ] );

	return (
		<Modal title={ item ? item.title : 'جزئیات محتوا' } onRequestClose={ onClose } className="pp-modal" size="large">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! item && ! error && <Spinner /> }
			{ item && (
				<div className="pp-details">
					<div className="pp-details__actions">
						<a className="button" href={ item.url } target="_blank" rel="noreferrer">
							مشاهده
						</a>
						{ item.edit_url && (
							<a className="button" href={ item.edit_url }>
								ویرایش
							</a>
						) }
					</div>

					<dl className="pp-facts">
						<div><dt>کلمه کلیدی</dt><dd>{ item.focus_keyword || 'ندارد' }</dd></div>
						<div><dt>توضیحات متا</dt><dd>{ item.has_meta ? 'دارد' : 'ندارد' }</dd></div>
						<div><dt>تعداد کلمات</dt><dd>{ num( item.word_count ) }</dd></div>
						<div><dt>پاسخ مستقیم در ابتدا</dt><dd>{ item.direct_answer ? 'دارد' : 'تشخیص داده نشد' }</dd></div>
						<div><dt>سال‌های ذکرشده</dt><dd>{ item.years.length ? item.years.map( num ).join( '، ' ) : '—' }</dd></div>
						<div><dt>نویسنده</dt><dd>{ item.author || '—' }</dd></div>
					</dl>

					<h3>ساختار سرفصل‌ها</h3>
					{ item.outline.length ? (
						<ul className="pp-outline">
							{ item.outline.map( ( h, i ) => (
								<li key={ i } className={ `pp-outline__h${ h.level }` }>
									{ h.text }
								</li>
							) ) }
						</ul>
					) : (
						<p className="pp-muted">این محتوا سرفصل (H2) ندارد.</p>
					) }

					<h3>لینک‌های ورودی ({ num( item.inbound.length ) })</h3>
					{ item.inbound.length ? (
						<ul className="pp-linklist">
							{ item.inbound.map( ( l, i ) => (
								<li key={ i }>
									{ 'menu' === l.source_type ? <span className="pp-badge">منو</span> : null } { l.title || l.url }
									{ l.anchor && <span className="pp-muted"> — «{ l.anchor }»</span> }
								</li>
							) ) }
						</ul>
					) : (
						<p className="pp-muted">هیچ محتوایی به این صفحه لینک نداده است.</p>
					) }

					<h3>لینک‌های خروجی ({ num( item.outbound.length ) })</h3>
					{ item.outbound.length ? (
						<ul className="pp-linklist">
							{ item.outbound.map( ( l, i ) => (
								<li key={ i }>
									<span className={ `pp-badge pp-badge--${ [ 'missing', 'unpublished' ].includes( l.state ) ? 'danger' : 'redirect' === l.state ? 'warning' : 'neutral' }` }>
										{ 'external' === l.type ? 'خارجی' : 'asset' === l.type ? 'فایل' : LINK_STATE_LABELS[ l.state ] || l.state }
									</span>{ ' ' }
									«{ l.anchor || 'بدون متن' }» <span className="pp-url" dir="ltr">{ decodeURI( l.url ) }</span>
								</li>
							) ) }
						</ul>
					) : (
						<p className="pp-muted">این محتوا هیچ لینکی ندارد.</p>
					) }
				</div>
			) }
		</Modal>
	);
}
