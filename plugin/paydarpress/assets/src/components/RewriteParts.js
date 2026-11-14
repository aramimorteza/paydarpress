import { useState } from '@wordpress/element';
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

/**
 * Default choices for a rewrite plan: existing tags added, removals done, new tags not
 * created, category added, poor address replaced.
 *
 * @param {Object} plan Plan from the preview.
 */
export function defaultChoice( plan ) {
	return {
		add: [ ...( plan.add || [] ) ],
		new: [],
		remove: ( plan.remove || [] ).map( ( r ) => r.name ),
		category: !! plan.category,
		slug: !! plan.slug,
	};
}

/**
 * Default choices for every rewrite in a preview, keyed by item index.
 *
 * @param {Array} articles Preview articles.
 */
export function defaultPlans( articles ) {
	const out = {};
	( articles || [] ).forEach( ( a ) => {
		if ( a.plan ) {
			out[ a.index ] = defaultChoice( a.plan );
		}
	} );
	return out;
}

/**
 * What else a rewrite changes on the original: address, tags, category. Each change is ticked.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Preview article (with plan, rewrite_url).
 * @param {Object}   props.choice   Current choice.
 * @param {Function} props.onChange New choice.
 */
export function RewritePlan( { item, choice, onChange } ) {
	const plan = item.plan;
	if ( ! plan || ! choice ) {
		return null;
	}
	const flip = ( key, name, on ) => onChange( { ...choice, [ key ]: on ? [ ...choice[ key ], name ] : choice[ key ].filter( ( n ) => n !== name ) } );
	const label = 'product_tag' === plan.taxonomy ? 'برچسب محصول' : 'برچسب';
	const after = plan.current.length - choice.remove.length + choice.add.length + choice.new.length;

	return (
		<div className="pp-rewrite-plan">
			<p className="pp-rewrite-plan__url">
				<strong>آدرس:</strong>{ ' ' }
				{ plan.slug ? (
					<CheckboxControl
						__nextHasNoMarginBottom
						checked={ choice.slug }
						onChange={ ( on ) => onChange( { ...choice, slug: on } ) }
						label={ <>آدرس فعلی { plan.slug.why }؛ تغییر به <code dir="ltr">{ plan.slug.to }</code> با ریدایرکت ۳۰۱ از آدرس قبلی</> }
					/>
				) : (
					<span>بدون تغییر — <code dir="ltr">{ decodeURI( item.rewrite_url || '' ) }</code></span>
				) }
			</p>
			{ plan.taxonomy && (
				<div className="pp-rewrite-plan__tags">
					<strong>{ label }‌ها</strong> <span className="pp-muted">({ num( plan.current.length ) } فعلی ← { num( after ) } پس از جایگزینی، سقف { num( plan.max ) })</span>
					{ 0 === plan.add.length + plan.new.length + plan.remove.length && <span className="pp-muted"> — پیشنهادی برای تغییر نیامد؛ برچسب‌های فعلی می‌مانند.</span> }
					{ plan.remove.map( ( r ) => (
						<CheckboxControl key={ `r-${ r.name }` } __nextHasNoMarginBottom checked={ choice.remove.includes( r.name ) } onChange={ ( on ) => flip( 'remove', r.name, on ) } label={ <>حذف «{ r.name }»{ r.reason && <span className="pp-muted"> — { r.reason }</span> }</> } />
					) ) }
					{ plan.add.map( ( n ) => (
						<CheckboxControl key={ `a-${ n }` } __nextHasNoMarginBottom checked={ choice.add.includes( n ) } onChange={ ( on ) => flip( 'add', n, on ) } label={ `افزودن «${ n }»` } />
					) ) }
					{ plan.new.map( ( n ) => (
						<CheckboxControl key={ `n-${ n }` } __nextHasNoMarginBottom checked={ choice.new.includes( n ) } onChange={ ( on ) => flip( 'new', n, on ) } label={ `ساخت برچسب تازه «${ n }» و افزودن` } />
					) ) }
					{ after > plan.max && <p className="pp-check pp-check--warning">{ num( after ) } برچسب از سقف { num( plan.max ) } بیشتر است؛ برچسب‌های کم‌ربط را برای حذف تیک بزنید.</p> }
				</div>
			) }
			{ plan.category && (
				<CheckboxControl __nextHasNoMarginBottom checked={ choice.category } onChange={ ( on ) => onChange( { ...choice, category: on } ) } label={ `افزودن دسته «${ plan.category.name }» (دسته‌های فعلی می‌مانند)` } />
			) }
		</div>
	);
}

/**
 * One imported rewrite in the result: replace now or keep the draft.
 *
 * @param {Object} props
 * @param {Object} props.article Result article.
 */
export function RewriteResult( { article } ) {
	const [ state, setState ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ url, setUrl ] = useState( '' );

	const apply = () => {
		setState( 'busy' );
		setError( '' );
		post( `/rewrites/${ article.id }/apply` )
			.then( ( r ) => {
				setUrl( r.url );
				setState( 'applied' );
			} )
			.catch( ( e ) => {
				setError( errorMessage( e ) );
				setState( '' );
			} );
	};

	return (
		<li className="pp-rewrite-result">
			<div>
				<strong>بازنویسی «{ article.original || article.title }»</strong>
				{ article.plan && article.plan.length > 0 && <ul className="pp-muted">{ article.plan.map( ( l ) => <li key={ l }>{ l }</li> ) }</ul> }
			</div>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ 'applied' === state && (
				<p className="pp-ok">
					جایگزین شد. نسخه قبلی در «بازنگری‌ها» هست.{ ' ' }
					<a href={ url } target="_blank" rel="noreferrer">مشاهده صفحه ↗</a>
				</p>
			) }
			{ 'kept' === state && (
				<p className="pp-muted">
					پیش‌نویس ماند؛ نسخه منتشرشده تغییری نکرده. هر وقت آماده بود از ویرایشگر «جایگزینی نسخه اصلی» را بزنید.{ ' ' }
					<a href={ article.edit_url } target="_blank" rel="noreferrer">باز کردن پیش‌نویس ↗</a>
				</p>
			) }
			{ ( '' === state || 'busy' === state ) && (
				<div className="pp-ai-modal__row">
					{ article.can_apply ? (
						<Button variant="primary" isBusy={ 'busy' === state } disabled={ 'busy' === state } onClick={ apply }>همین الان جایگزین کن</Button>
					) : (
						<span className="pp-muted">پیش از جایگزینی باید متن در ویرایشگر خوانده و «بازبینی شد» زده شود (سیاست محتوای سایت).</span>
					) }
					<Button variant="secondary" disabled={ 'busy' === state } onClick={ () => setState( 'kept' ) }>فعلاً پیش‌نویس بماند</Button>
					<a href={ article.edit_url } target="_blank" rel="noreferrer">پیش‌نویس را ببینم ↗</a>
					{ article.original_url && <a href={ article.original_url } target="_blank" rel="noreferrer">نسخه فعلی ↗</a> }
				</div>
			) }
		</li>
	);
}
