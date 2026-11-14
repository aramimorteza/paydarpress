import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

const config = window.paydarpress || {};
const KIND_ICON = { missing: 'dismiss', unpublished: 'hidden', redirect: 'randomize', image: 'format-image', asset: 'media-default', external: 'external' };

/**
 * One broken link / image of a page with its one-click fix.
 *
 * @param {Object}   props
 * @param {number}   props.postId Source post.
 * @param {Object}   props.p      Problem.
 * @param {Function} props.onDone Called after a fix.
 */
function Problem( { postId, p, onDone } ) {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ busy, setBusy ] = useState( false );
	const [ msg, setMsg ] = useState( null );

	const run = ( action, target = 0 ) => {
		setBusy( true );
		post( '/broken/fix', { post: postId, url: p.url, action, target } )
			.then( ( r ) => {
				setMsg( { ok: true, text: r.message } );
				if ( onDone ) {
					onDone();
				}
			} )
			.catch( ( e ) => setMsg( { ok: false, text: errorMessage( e ) } ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<li className={ `pp-broken${ msg && msg.ok ? ' is-done' : '' }` }>
			<span className={ `dashicons dashicons-${ KIND_ICON[ p.kind ] || 'warning' }` } aria-hidden="true" />
			<div className="pp-broken__body">
				<div>
					{ p.anchor && 'image' !== p.kind ? <strong>«{ p.anchor }»</strong> : null }
					{ ' ' }<bdi dir="ltr" className="pp-url" title={ p.label }>{ p.label.replace( /^https?:\/\/[^/]+/, '' ) || '/' }</bdi>
				</div>
				<div className="pp-muted">{ p.why }{ p.fix.reason ? ` · پیشنهاد: ${ p.fix.reason }` : '' }{ p.others > 0 ? ` · ${ num( p.others ) } صفحه دیگر هم به این آدرس لینک داده‌اند` : '' }</div>
				{ msg && <div className={ msg.ok ? 'pp-ok' : 'pp-missing' }>{ msg.text }</div> }
			</div>
			{ canManage && ! ( msg && msg.ok ) && (
				<div className="pp-broken__actions">
					<Button variant="secondary" size="small" isBusy={ busy } disabled={ busy } onClick={ () => run( p.fix.action, p.fix.target_id || 0 ) }>{ p.fix.label }</Button>
					{ 'replace' === p.fix.action && <Button variant="tertiary" size="small" disabled={ busy } onClick={ () => run( 'remove' ) }>برداشتن</Button> }
					{ 'replace' === p.fix.action && p.others > 0 && <Button variant="tertiary" size="small" disabled={ busy } onClick={ () => run( 'redirect', p.fix.target_id ) } title="یک ریدایرکت ۳۰۱ همه صفحه‌هایی را که به این آدرس لینک داده‌اند درست می‌کند">ریدایرکت برای همه</Button> }
				</div>
			) }
		</li>
	);
}

/**
 * Broken links and images of one page.
 *
 * @param {Object}   props
 * @param {number}   props.postId   Post.
 * @param {Array}    props.problems Problems.
 * @param {Function} props.onDone   After a fix.
 */
export default function BrokenFixes( { postId, problems, onDone } ) {
	if ( ! problems || 0 === problems.length ) {
		return null;
	}
	return <ul className="pp-broken-list">{ problems.map( ( p ) => <Problem key={ `${ p.kind }-${ p.url }` } postId={ postId } p={ p } onDone={ onDone } /> ) }</ul>;
}

/**
 * Notice for when nothing was found.
 */
export function BrokenNone() {
	return <Notice status="success" isDismissible={ false }>لینک یا تصویر خرابی پیدا نشد.</Notice>;
}
