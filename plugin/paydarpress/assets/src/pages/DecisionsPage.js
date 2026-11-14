import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import AiButton from '../components/AiButton';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import { tagTopicsCommand, termCommand } from '../lib/aiCommands';

const config = window.paydarpress || {};

const ACTION_LABELS = { keep: 'نگه دار', merge: 'ادغام', delete: 'حذف', remove_posts: 'برداشتن از مطالب نامرتبط' };

/**
 * Search another term to merge into.
 *
 * @param {Object}   props
 * @param {string}   props.taxonomy Taxonomy.
 * @param {number}   props.exclude  Term to exclude.
 * @param {Function} props.onPick   Called with { id, name }.
 */
function TermPicker( { taxonomy, exclude, onPick } ) {
	const [ q, setQ ] = useState( '' );
	const [ items, setItems ] = useState( [] );
	useEffect( () => {
		if ( q.trim().length < 2 ) {
			setItems( [] );
			return undefined;
		}
		const t = setTimeout( () => get( `/taxmap?taxonomy=${ taxonomy }&search=${ encodeURIComponent( q ) }` ).then( ( d ) => setItems( d.items.filter( ( i ) => i.id !== exclude ).slice( 0, 8 ) ) ).catch( () => {} ), 350 );
		return () => clearTimeout( t );
	}, [ q, taxonomy, exclude ] );
	return (
		<div className="pp-picker">
			<TextControl __nextHasNoMarginBottom aria-label="جست‌وجوی مقصد ادغام" placeholder="جست‌وجوی مقصد ادغام" value={ q } onChange={ setQ } />
			{ items.length > 0 && <ul className="pp-picker__list">{ items.map( ( i ) => <li key={ i.id }><button type="button" onClick={ () => onPick( i ) }>{ i.name } ({ num( i.count ) })</button></li> ) }</ul> }
		</div>
	);
}

/**
 * One decision card.
 *
 * @param {Object}   props
 * @param {Object}   props.card     Card.
 * @param {boolean}  props.canEdit  Manage capability.
 * @param {boolean}  props.selected Bulk selection.
 * @param {Function} props.onSelect Toggle bulk selection.
 * @param {Function} props.onDone   Reload.
 * @param {number}   props.grown    Items needed to index again.
 * @param {boolean}  props.target   Opened from a task link.
 */
function Card( { card, canEdit, selected, onSelect, onDone, grown, target } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( '' );
	const [ op, setOp ] = useState( '' );
	const [ tagPosts, setTagPosts ] = useState( card.matches.map( ( m ) => m.id ) );
	const [ untag, setUntag ] = useState( card.misfit.map( ( m ) => m.id ) );
	const [ merging, setMerging ] = useState( false );

	const act = ( op, data = {} ) => {
		setBusy( true );
		setError( '' );
		post( `/decisions/${ card.id }/${ op }`, data )
			.then( ( r ) => {
				setOp( op );
				setDone( r.message );
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const toggle = ( list, set, id, on ) => set( on ? [ ...list, id ] : list.filter( ( x ) => x !== id ) );
	const rec = card.recommend;

	return (
		<section className={ `pp-card pp-card--section pp-decision${ done ? ' is-done' : '' }${ target ? ' is-target' : '' }` }>
			<div className="pp-decision__head">
				{ canEdit && ! done && <CheckboxControl __nextHasNoMarginBottom aria-label="انتخاب برای کار گروهی" checked={ selected } onChange={ onSelect } /> }
				<strong>{ card.name }</strong>
				<span className="pp-muted">{ card.tax_label } · { num( card.count ) } { card.product ? 'محصول' : 'مطلب' }</span>
				<span className={ `pp-badge${ 'misfit' === card.reason ? ' pp-badge--warning' : '' }` }>{ card.reason_label }</span>
				{ card.url && <a href={ card.url } target="_blank" rel="noreferrer" className="pp-muted">مشاهده آرشیو</a> }
			</div>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ done ? (
				<div className="pp-ai-modal__row">
					<span className="pp-ok">{ done }</span>
					{ 'keep' === op && card.can_topics && card.count < grown && 'post_tag' === card.taxonomy && <AiButton command={ tagTopicsCommand( card.name, card.id ) } /> }
					{ 'keep' === op && card.can_topics && card.count < grown && 'category' === card.taxonomy && <AiButton command={ termCommand( card.name, 'category', card.id ) } /> }
					<Button variant="link" onClick={ onDone }>بعدی</Button>
				</div>
			) : (
				<>
					<ul className="pp-decision__facts">
						<li>
							<strong>جست‌وجو در گوگل: </strong>
							{ card.demand.impressions > 0 ? <>{ num( card.demand.impressions ) } نمایش، { num( card.demand.clicks ) } کلیک — مثل «{ card.demand.queries.join( '»، «' ) }»</> : <span className="pp-muted">در داده سرچ کنسول دیده نشد</span> }
						</li>
						<li><strong>مطالب مناسب که این را ندارند: </strong>{ card.matches.length ? num( card.matches.length ) : <span className="pp-muted">پیدا نشد</span> }</li>
						{ card.overlap.length > 0 && <li><strong>مشابه: </strong>{ card.overlap.map( ( o ) => `${ o.name } (${ num( o.count ) })` ).join( '، ' ) }</li> }
					</ul>
					<p className="pp-decision__rec"><span className="pp-badge pp-badge--info">پیشنهاد پایدارپرس: { ACTION_LABELS[ rec.action ] }</span> { rec.why }</p>

					{ card.misfit.length > 0 && (
						<div className="pp-decision__list">
							<strong>مطالبی که به نظر هوش مصنوعی به «{ card.name }» نمی‌خورند:</strong>
							{ card.misfit.map( ( m ) => <CheckboxControl key={ m.id } __nextHasNoMarginBottom label={ `${ m.title }${ m.reason ? ` — ${ m.reason }` : '' }` } checked={ untag.includes( m.id ) } onChange={ ( on ) => toggle( untag, setUntag, m.id, on ) } disabled={ ! canEdit } /> ) }
						</div>
					) }
					{ card.matches.length > 0 && (
						<div className="pp-decision__list">
							<strong>با «نگه دار» این مطالب هم «{ card.name }» می‌گیرند:</strong>
							{ card.matches.map( ( m ) => <CheckboxControl key={ m.id } __nextHasNoMarginBottom label={ m.title } checked={ tagPosts.includes( m.id ) } onChange={ ( on ) => toggle( tagPosts, setTagPosts, m.id, on ) } disabled={ ! canEdit } /> ) }
						</div>
					) }

					{ canEdit && (
						<div className="pp-ai-modal__row">
							{ card.misfit.length > 0 && <Button variant={ 'remove_posts' === rec.action ? 'primary' : 'secondary' } size="small" onClick={ () => act( 'remove_posts', { posts: untag } ) } isBusy={ busy } disabled={ busy || ! untag.length }>برداشتن از { num( untag.length ) } مطلب</Button> }
							<Button variant={ 'keep' === rec.action ? 'primary' : 'secondary' } size="small" onClick={ () => act( 'keep', { posts: tagPosts } ) } disabled={ busy }>نگه دار{ tagPosts.length ? ` و به ${ num( tagPosts.length ) } مطلب اضافه کن` : '' }</Button>
							{ card.overlap.slice( 0, 2 ).map( ( o ) => (
								<Button key={ o.id } variant={ 'merge' === rec.action ? 'primary' : 'secondary' } size="small" onClick={ () => act( 'merge', { into: o.id } ) } disabled={ busy }>ادغام در «{ o.name }»</Button>
							) ) }
							<Button variant="secondary" size="small" onClick={ () => setMerging( ! merging ) } disabled={ busy }>ادغام در…</Button>
							{ 'product_cat' === card.taxonomy && <Button variant="secondary" size="small" onClick={ () => act( 'hide' ) } disabled={ busy }>پنهان کردن</Button> }
							{ 'product_cat' === card.taxonomy && <Button variant="secondary" size="small" href={ `post-new.php?post_type=product` }>افزودن محصول</Button> }
							<Button variant={ 'delete' === rec.action ? 'primary' : 'tertiary' } isDestructive size="small" onClick={ () => window.confirm( `«${ card.name }» حذف شود؟ مطالب حذف نمی‌شوند و آدرس آرشیو ریدایرکت می‌شود.` ) && act( 'delete' ) } disabled={ busy }>حذف</Button>
							<Button variant="tertiary" size="small" onClick={ () => act( 'later' ) } disabled={ busy }>بعداً</Button>
						</div>
					) }
					{ merging && <TermPicker taxonomy={ card.taxonomy } exclude={ card.id } onPick={ ( t ) => act( 'merge', { into: t.id } ) } /> }
					{ card.product && <p className="pp-muted">دسته و برچسب محصول با مقاله پر نمی‌شود: محصول اضافه کنید، ادغام کنید یا پنهان کنید.</p> }
					{ ! card.noindex_ok && <p className="pp-muted">افزونه سئوی سایت noindex آرشیو را از طریق پایدارپرس نمی‌پذیرد؛ برای مورد نگه‌داشته‌شده، آرشیو کم‌مطلب را دستی noindex کنید.</p> }
				</>
			) }
		</section>
	);
}

export default function DecisionsPage() {
	const canEdit = !! ( config.caps && config.caps.manage );
	const term = Number( ( window.location.hash.match( /[?&]term=(\d+)/ ) || [] )[ 1 ] || 0 );
	const [ tax, setTax ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	const load = useCallback( () => {
		setSelected( [] );
		get( `/decisions?taxonomy=${ tax }&page=${ page }${ term ? `&term=${ term }` : '' }` ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ tax, page, term ] );
	useEffect( () => {
		load();
	}, [ load ] );

	const bulk = ( op ) => {
		if ( 'delete' === op && ! window.confirm( `${ num( selected.length ) } مورد حذف شود؟ مطالب حذف نمی‌شوند و آدرس آرشیوها ریدایرکت می‌شود.` ) ) {
			return;
		}
		setBusy( true );
		post( '/decisions/bulk', { op, ids: selected } )
			.then( ( r ) => {
				setNotice( `${ num( r.done ) } مورد انجام شد.` );
				load();
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Page title="تصمیم دسته و برچسب" helpKey="decisions">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ notice && <Notice status="success" onRemove={ () => setNotice( '' ) }>{ notice }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<section className="pp-card pp-card--section">
						<p className="pp-muted">
							دسته‌ها و برچسب‌های خالی، کم‌مطلب، یا مواردی که طبقه‌بندی گفته به برخی مطالبشان نمی‌خورند. هر مورد یک بار پرسیده می‌شود و تصمیم شما در نقشه دسته‌ها و برچسب‌ها ثبت می‌شود. چیزی بدون تأیید شما تغییر نمی‌کند؛ ادغام و حذف، آدرس آرشیو را ریدایرکت می‌کنند. برچسب یا دسته‌ای که نگه می‌دارید تا { num( data.grown ) } مطلب noindex می‌ماند و بعد خودکار ایندکس می‌شود.
						</p>
						<div className="pp-classify__options">
							<SelectControl __nextHasNoMarginBottom label="نوع" value={ tax } options={ [ { value: '', label: `همه (${ num( data.total ) })` }, ...Object.entries( data.taxonomies ).map( ( [ value, label ] ) => ( { value, label: `${ label } (${ num( data.counts[ value ] || 0 ) })` } ) ) ] } onChange={ ( v ) => {
								setTax( v );
								setPage( 1 );
							} } />
							{ canEdit && data.items.length > 0 && (
								<>
									<Button variant="link" onClick={ () => setSelected( data.items.map( ( i ) => i.id ) ) }>انتخاب همه این صفحه</Button>
									<Button variant="link" onClick={ () => setSelected( data.items.filter( ( i ) => 'delete' === i.recommend.action ).map( ( i ) => i.id ) ) }>فقط پیشنهادهای حذف</Button>
								</>
							) }
						</div>
						{ canEdit && selected.length > 0 && (
							<div className="pp-ai-modal__row">
								<strong>{ num( selected.length ) } انتخاب‌شده:</strong>
								<Button variant="secondary" size="small" isDestructive onClick={ () => bulk( 'delete' ) } disabled={ busy }>حذف</Button>
								<Button variant="secondary" size="small" onClick={ () => bulk( 'keep' ) } disabled={ busy }>نگه دار</Button>
								<Button variant="tertiary" size="small" onClick={ () => bulk( 'later' ) } disabled={ busy }>بعداً</Button>
							</div>
						) }
					</section>
					{ 0 === data.items.length && <div className="pp-card"><EmptyState title="موردی منتظر تصمیم نیست" text="دسته و برچسب‌های خالی یا کم‌مطلب و گزارش‌های طبقه‌بندی اینجا می‌آیند." /></div> }
					{ data.items.map( ( c ) => <Card key={ c.id } card={ c } canEdit={ canEdit } grown={ data.grown } target={ c.id === term } selected={ selected.includes( c.id ) } onSelect={ ( on ) => setSelected( on ? [ ...selected, c.id ] : selected.filter( ( x ) => x !== c.id ) ) } onDone={ load } /> ) }
					{ data.pages > 1 && (
						<div className="pp-pagination">
							<Button variant="secondary" size="small" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>قبلی</Button>
							<span>صفحه { num( page ) } از { num( data.pages ) }</span>
							<Button variant="secondary" size="small" disabled={ page >= data.pages } onClick={ () => setPage( page + 1 ) }>بعدی</Button>
						</div>
					) }
				</>
			) }
		</Page>
	);
}
