import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import AiButton from '../components/AiButton';
import PostPicker from '../components/PostPicker';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';
import { clusterCommand, topicArticleCommand } from '../lib/aiCommands';

const config = window.paydarpress || {};

/**
 * One cluster: pillar, members, planned subtopics, link gaps.
 *
 * @param {Object}   props
 * @param {Object}   props.initial Cluster.
 * @param {Object}   props.meta    categories, key_pages.
 * @param {boolean}  props.canEdit Manage capability.
 * @param {Function} props.onChange Reload all.
 */
function ClusterCard( { initial, meta, canEdit, onChange } ) {
	const [ c, setC ] = useState( initial );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ picking, setPicking ] = useState( '' );
	useEffect( () => setC( initial ), [ initial ] );

	const call = ( path, data = {}, after = setC ) => {
		setBusy( true );
		setError( '' );
		return post( path, data )
			.then( after )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const save = ( patch ) => call( '/clusters', { id: c.id, ...patch } );

	return (
		<section className="pp-card pp-card--section pp-cluster">
			<div className="pp-cluster__head">
				<h2 className="pp-card__title">{ c.name }</h2>
				{ c.main_keyword && <span className="pp-badge">{ c.main_keyword }</span> }
				<span className="pp-muted">{ num( c.published ) } منتشرشده از { num( c.total ) } زیرموضوع</span>
			</div>
			<div className="pp-progress"><span style={ { width: `${ c.completion }%` } } /></div>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ notice && <Notice status="success" onRemove={ () => setNotice( '' ) }>{ notice }</Notice> }

			<div className="pp-taxmap__fields">
				<SelectControl __nextHasNoMarginBottom label="دسته" value={ String( c.category_id || 0 ) } options={ [ { value: '0', label: '—' }, ...meta.categories.map( ( x ) => ( { value: String( x.id ), label: x.name } ) ) ] } onChange={ ( v ) => save( { category_id: Number( v ) } ) } disabled={ ! canEdit || busy } />
				<SelectControl __nextHasNoMarginBottom label="صفحه کلیدی که ستون پشتیبانی می‌کند" value={ String( c.key_page_id || 0 ) } options={ [ { value: '0', label: '—' }, ...meta.key_pages.map( ( x ) => ( { value: String( x.id ), label: x.title } ) ) ] } onChange={ ( v ) => save( { key_page_id: Number( v ) } ) } disabled={ ! canEdit || busy } />
			</div>

			<p>
				<strong>مقاله ستون: </strong>
				{ c.pillar_id ? <>{ ( c.members.find( ( m ) => m.pillar ) || {} ).title }{ ! c.pillar_ok && <span className="pp-badge pp-badge--warning">منتشر نشده</span> }</> : <span className="pp-badge pp-badge--warning">تعیین نشده</span> }
				{ canEdit && <Button variant="link" onClick={ () => setPicking( 'pillar' === picking ? '' : 'pillar' ) }>{ c.pillar_id ? 'تغییر' : 'انتخاب' }</Button> }
			</p>
			{ 'pillar' === picking && <PostPicker label="مقاله جامع این خوشه را جست‌وجو کنید" onPick={ ( p ) => call( `/clusters/${ c.id }/pillar`, { post_id: p.id } ).then( () => setPicking( '' ) ) } /> }
			{ ! c.pillar_id && <p className="pp-muted">ستون، راهنمای جامع کلمه کلیدی اصلی است که به همه زیرموضوع‌ها لینک می‌دهد. اگر ندارید، «تکمیل نقشه با هوش مصنوعی» آن را هم پیشنهاد می‌دهد.</p> }

			{ c.members.length > 0 && (
				<table className="pp-table pp-table--compact">
					<thead><tr><th>مقاله</th><th>زیرموضوع</th><th>لینک به ستون</th><th>لینک از ستون</th><th></th></tr></thead>
					<tbody>
						{ c.members.map( ( m ) => (
							<tr key={ m.id }>
								<td><a href={ m.edit_url }>{ m.title }</a>{ m.pillar && <span className="pp-badge pp-badge--info">ستون</span> }{ 'publish' !== m.status && <span className="pp-badge">پیش‌نویس</span> }</td>
								<td className="pp-muted">{ m.subtopic }</td>
								<td>{ m.pillar ? '—' : ( m.to_pillar ? '✓' : <span className="pp-check--warning">ندارد</span> ) }</td>
								<td>{ m.pillar ? '—' : ( m.from_pillar ? '✓' : <span className="pp-check--warning">ندارد</span> ) }</td>
								<td>{ canEdit && ! m.pillar && <Button variant="link" isDestructive onClick={ () => call( `/clusters/remove-post/${ m.id }`, {}, onChange ) }>خارج کردن</Button> }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ c.link_gaps > 0 && (
				<p>
					{ num( c.link_gaps ) } مقاله با ستون لینک دوطرفه ندارد.{ ' ' }
					{ canEdit && <Button variant="secondary" size="small" onClick={ () => call( `/clusters/${ c.id }/links`, {}, ( r ) => setNotice( `${ num( r.queued ) } پیشنهاد لینک به «صف لینک داخلی» رفت؛ پس از تأیید شما درج می‌شوند.` ) ) } disabled={ busy || ! c.pillar_ok }>ارسال به صف لینک</Button> }
				</p>
			) }

			{ c.planned.length > 0 && (
				<>
					<h3>زیرموضوع‌های برنامه‌ریزی‌شده</h3>
					<ul className="pp-mini-list">
						{ c.planned.map( ( t ) => (
							<li key={ t.id }>{ t.title } { canEdit && <AiButton command={ topicArticleCommand( t ) } /> }</li>
						) ) }
					</ul>
				</>
			) }

			{ canEdit && (
				<div className="pp-ai-modal__row">
					<Button variant="secondary" size="small" onClick={ () => setPicking( 'member' === picking ? '' : 'member' ) }>افزودن مقاله موجود</Button>
					<AiButton command={ clusterCommand( c.name, { cluster_id: c.id } ) } />
					<Button variant="tertiary" size="small" isDestructive onClick={ () => window.confirm( 'خوشه حذف شود؟ مقاله‌ها دست نمی‌خورند.' ) && call( `/clusters/${ c.id }/delete`, {}, onChange ) }>حذف خوشه</Button>
				</div>
			) }
			{ 'member' === picking && <PostPicker label="مقاله‌ای که یک زیرموضوع این خوشه است" onPick={ ( p ) => call( `/clusters/${ c.id }/posts`, { post_id: p.id } ).then( () => setPicking( '' ) ) } /> }
		</section>
	);
}

export default function ClustersPage() {
	const canEdit = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ form, setForm ] = useState( { name: '', main_keyword: '', category_id: 0 } );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		get( '/clusters' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );
	useEffect( () => {
		load();
		window.addEventListener( 'paydarpress:imported', load );
		return () => window.removeEventListener( 'paydarpress:imported', load );
	}, [ load ] );

	const create = () => {
		setBusy( true );
		post( '/clusters', { ...form, name: form.name || form.main_keyword } )
			.then( () => {
				setForm( { name: '', main_keyword: '', category_id: 0 } );
				load();
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const accept = ( s ) => {
		setBusy( true );
		post( '/clusters/accept', { name: s.name, main_keyword: s.main_keyword, category_id: s.category_id, pillar_id: s.pillar_id, post_ids: s.posts.map( ( p ) => p.id ) } )
			.then( load )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Page title="خوشه‌های محتوایی" helpKey="clusters">
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<section className="pp-card pp-card--section">
						<p className="pp-muted">
							هر خوشه یک <strong>مقاله ستون</strong> (راهنمای جامع یک کلمه کلیدی اصلی) و چند <strong>مقاله خوشه</strong> دارد که هر کدام یک زیرموضوع را عمیق پوشش می‌دهند. مقاله‌های خوشه به ستون و ۱ تا ۲ هم‌خوشه لینک می‌دهند و ستون به همه آن‌ها و به صفحه کلیدی. این ساختار فقط برای مقاله‌هاست. موضوع‌ها و مقاله‌های تازه از همین‌جا جایشان را در خوشه می‌گیرند.
						</p>
						{ canEdit && (
							<div className="pp-classify__options">
								<TextControl __nextHasNoMarginBottom label="کلمه کلیدی اصلی" value={ form.main_keyword } onChange={ ( v ) => setForm( { ...form, main_keyword: v } ) } placeholder="مثلاً طلاق توافقی" />
								<TextControl __nextHasNoMarginBottom label="نام خوشه (اختیاری)" value={ form.name } onChange={ ( v ) => setForm( { ...form, name: v } ) } />
								<SelectControl __nextHasNoMarginBottom label="دسته" value={ String( form.category_id ) } options={ [ { value: '0', label: '—' }, ...data.categories.map( ( x ) => ( { value: String( x.id ), label: x.name } ) ) ] } onChange={ ( v ) => setForm( { ...form, category_id: Number( v ) } ) } />
								<Button variant="secondary" onClick={ create } disabled={ busy || ! ( form.main_keyword || form.name ).trim() }>ساخت خوشه خالی</Button>
								{ form.main_keyword.trim() && <AiButton command={ clusterCommand( form.main_keyword, { keyword: form.main_keyword, category_id: form.category_id } ) } small={ false } variant="primary" /> }
							</div>
						) }
					</section>

					{ data.suggestions.length > 0 && (
						<section className="pp-card pp-card--section">
							<h2 className="pp-card__title">پیشنهاد خوشه از مقاله‌های موجود</h2>
							<p className="pp-muted">بر اساس «کلمات کلیدی اصلی» پروفایل: مقاله‌هایی که عنوان یا کلمه کلیدی‌شان به یکی از آن‌ها می‌خورد و هنوز در خوشه‌ای نیستند. ستون پیشنهادی، مقاله‌ای است که بیشترین لینک داخلی را می‌گیرد.</p>
							{ data.suggestions.map( ( s ) => (
								<div key={ s.name } className="pp-taxmap__row">
									<strong>{ s.name }</strong> <span className="pp-muted">{ num( s.count ) } مقاله · ستون پیشنهادی: { ( s.posts.find( ( p ) => p.id === s.pillar_id ) || {} ).title }</span>
									<div className="pp-muted">{ s.posts.slice( 0, 8 ).map( ( p ) => p.title ).join( '، ' ) }{ s.posts.length > 8 ? '…' : '' }</div>
									{ canEdit && <Button variant="primary" size="small" onClick={ () => accept( s ) } disabled={ busy }>ساخت این خوشه</Button> }
								</div>
							) ) }
						</section>
					) }

					{ 0 === data.items.length && <div className="pp-card"><EmptyState title="هنوز خوشه‌ای نیست" text="با یک کلمه کلیدی اصلی شروع کنید؛ «ساخت نقشه خوشه با هوش مصنوعی» ستون و زیرموضوع‌ها را پیشنهاد می‌دهد و مقاله‌های موجود را جا می‌دهد." /></div> }
					{ data.items.map( ( c ) => <ClusterCard key={ c.id } initial={ c } meta={ data } canEdit={ canEdit } onChange={ load } /> ) }
				</>
			) }
		</Page>
	);
}
