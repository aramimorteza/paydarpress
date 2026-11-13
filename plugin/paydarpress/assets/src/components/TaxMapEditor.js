import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl, TextareaControl } from '@wordpress/components';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

const FILTERS = [
	{ value: 'all', label: 'همه' },
	{ value: 'unscoped', label: 'بدون توضیح محدوده' },
	{ value: 'empty', label: 'بدون مطلب' },
	{ value: 'noted', label: 'دارای یادداشت' },
	{ value: 'retired', label: 'ناوبری یا کنار گذاشته' },
];

const ROLE_HELP = {
	high: 'محتوای تازه با اولویت؛ هدف تعداد دو برابر.',
	normal: 'محتوای تازه عادی.',
	low: 'محتوای تازه کم؛ هدف تعداد نصف.',
	nav: 'فقط برای منو و دسته‌بندی؛ محتوای تازه و هدف تعداد ندارد.',
	retired: 'دیگر برای محتوای تازه استفاده نمی‌شود؛ بعداً برایش تصمیم می‌گیرید (ادغام یا حذف).',
};

/**
 * One term of the map.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Term with notes.
 * @param {Object}   props.meta     roles, key_pages, targets.
 * @param {boolean}  props.canEdit  Manage capability.
 * @param {Function} props.onSaved  Called with the saved item.
 */
function TermRow( { item, meta, canEdit, onSaved } ) {
	const [ draft, setDraft ] = useState( item );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	useEffect( () => setDraft( item ), [ item ] );

	const isCat = 'category' === item.taxonomy || 'product_cat' === item.taxonomy;
	const isPostCat = 'category' === item.taxonomy;
	const dirty = [ 'scope', 'search_patterns', 'role', 'target', 'key_page' ].some( ( k ) => String( draft[ k ] ?? '' ) !== String( item[ k ] ?? '' ) );
	const set = ( k ) => ( v ) => setDraft( { ...draft, [ k ]: v } );
	const auto = meta.targets && meta.targets[ draft.role ];

	const save = () => {
		setBusy( true );
		setError( '' );
		post( `/taxmap/${ item.id }`, { scope: draft.scope, search_patterns: draft.search_patterns, role: draft.role, target: Number( draft.target ) || 0, key_page: Number( draft.key_page ) || 0 } )
			.then( onSaved )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className={ `pp-taxmap__row${ [ 'nav', 'retired' ].includes( draft.role ) ? ' is-muted' : '' }` }>
			<div className="pp-taxmap__head">
				<strong>{ item.name }</strong>
				<span className="pp-muted">{ num( item.count ) } مطلب</span>
				{ item.url && <a href={ item.url } target="_blank" rel="noreferrer" className="pp-muted">مشاهده</a> }
				{ 0 === item.count && <span className="pp-badge pp-badge--warning">خالی</span> }
			</div>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			<div className="pp-taxmap__fields">
				<SelectControl
					__nextHasNoMarginBottom
					label={ <>نقش <span className="pp-default">پیش‌فرض: عادی</span></> }
					help={ ROLE_HELP[ draft.role ] }
					value={ draft.role }
					options={ Object.entries( meta.roles ).map( ( [ value, label ] ) => ( { value, label } ) ) }
					onChange={ set( 'role' ) }
					disabled={ ! canEdit }
				/>
				{ isPostCat && [ 'high', 'normal', 'low' ].includes( draft.role ) && (
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						label={ <>هدف تعداد مطلب <span className="pp-default">پیش‌فرض: خودکار ({ num( auto || 0 ) })</span></> }
						help="۰ یعنی خودکار، بر اساس مرحله سایت و نقش."
						value={ String( draft.target || 0 ) }
						onChange={ set( 'target' ) }
						disabled={ ! canEdit }
					/>
				) }
				{ isCat && meta.key_pages.length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ <>صفحه کلیدی مرتبط <span className="pp-default">پیش‌فرض: ندارد</span></> }
						help="مطالب این دسته در اولویت به این صفحه لینک می‌دهند."
						value={ String( draft.key_page || 0 ) }
						options={ [ { value: '0', label: '—' }, ...meta.key_pages.map( ( p ) => ( { value: String( p.id ), label: p.title } ) ) ] }
						onChange={ set( 'key_page' ) }
						disabled={ ! canEdit }
					/>
				) }
			</div>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ isCat ? 'چه موضوعاتی در این دسته قرار می‌گیرد؟' : 'این برچسب برای چه مطالبی است؟' }
				help={ isCat ? 'زیرموضوع‌های اصلی را با ویرگول بنویسید؛ موضوعات دسته‌های دیگر را نیاورید.' : 'یک جمله کافی است؛ هوش مصنوعی با آن برچسب را درست انتخاب می‌کند.' }
				rows={ 2 }
				value={ draft.scope || '' }
				onChange={ set( 'scope' ) }
				disabled={ ! canEdit }
			/>
			{ isPostCat && (
				<TextControl
					__nextHasNoMarginBottom
					label="الگوهای جست‌وجوی رایج (اختیاری)"
					help="شکل جست‌وجوهای این دسته در گوگل؛ به‌جای کلمه متغیر [X] بنویسید."
					value={ draft.search_patterns || '' }
					onChange={ set( 'search_patterns' ) }
					disabled={ ! canEdit }
				/>
			) }
			{ canEdit && dirty && (
				<div className="pp-ai-modal__row">
					<Button variant="primary" size="small" onClick={ save } isBusy={ busy } disabled={ busy }>ذخیره</Button>
					<Button variant="tertiary" size="small" onClick={ () => setDraft( item ) } disabled={ busy }>انصراف</Button>
				</div>
			) }
		</div>
	);
}

/**
 * Taxonomy map: categories and tags of posts and products with the owner's notes.
 * Terms are read live from WordPress; each row saves itself.
 *
 * @param {Object}  props
 * @param {boolean} props.canEdit Manage capability.
 */
export default function TaxMapEditor( { canEdit } ) {
	const [ taxonomy, setTaxonomy ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ filter, setFilter ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	const load = useCallback( () => {
		get( `/taxmap?taxonomy=${ encodeURIComponent( taxonomy ) }&search=${ encodeURIComponent( query ) }&filter=${ filter }&page=${ page }` )
			.then( ( d ) => {
				setData( d );
				if ( ! taxonomy ) {
					setTaxonomy( d.taxonomy );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ taxonomy, query, filter, page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		const t = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 400 );
		return () => clearTimeout( t );
	}, [ search ] );

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const tab = data.tabs.find( ( t ) => t.key === data.taxonomy ) || data.tabs[ 0 ];
	const saved = ( item ) => setData( { ...data, items: data.items.map( ( i ) => ( i.id === item.id ? item : i ) ) } );

	return (
		<div className="pp-taxmap">
			<div className="pp-tabs" role="tablist">
				{ data.tabs.map( ( t ) => (
					<button key={ t.key } type="button" role="tab" aria-selected={ t.key === data.taxonomy } className={ `pp-tab${ t.key === data.taxonomy ? ' is-active' : '' }` } onClick={ () => {
						setTaxonomy( t.key );
						setPage( 1 );
					} }>
						{ t.label } <span className="pp-muted">({ num( t.summary.total ) })</span>
					</button>
				) ) }
			</div>
			{ tab && (
				<p className="pp-muted">
					{ num( tab.summary.total ) } مورد؛ { num( tab.summary.active ) } فعال، { num( tab.summary.scoped ) } با توضیح محدوده، { num( tab.summary.empty ) } بدون مطلب.
					{ 'post_tag' === data.taxonomy && ` هر مطلب حداکثر ${ num( data.max_tags ) } برچسب می‌گیرد؛ هوش مصنوعی فقط از برچسب‌های فعال استفاده می‌کند.` }
				</p>
			) }
			<div className="pp-taxmap__toolbar">
				<TextControl __nextHasNoMarginBottom placeholder="جست‌وجوی نام" value={ search } onChange={ setSearch } />
				<SelectControl __nextHasNoMarginBottom value={ filter } options={ FILTERS } onChange={ ( v ) => {
					setFilter( v );
					setPage( 1 );
				} } />
			</div>
			{ 0 === data.items.length && <p className="pp-muted">موردی پیدا نشد.</p> }
			{ data.items.map( ( item ) => <TermRow key={ item.id } item={ item } meta={ data } canEdit={ canEdit } onSaved={ saved } /> ) }
			{ data.pages > 1 && (
				<div className="pp-pagination">
					<Button variant="secondary" size="small" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
					<span>صفحه { num( page ) } از { num( data.pages ) }</span>
					<Button variant="secondary" size="small" disabled={ page >= data.pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
				</div>
			) }
		</div>
	);
}
