import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import AiButton from './AiButton';
import { structureCommand } from '../lib/aiCommands';
import { get, post } from '../lib/api';
import { num } from '../lib/format';

const config = window.paydarpress || {};
const PRIORITY = { high: [ 'مهم', 'danger' ], medium: [ 'متوسط', 'warning' ], low: [ 'کم', 'info' ] };

/**
 * Shop focus and the AI's category-structure suggestions (a to-do list; the owner decides).
 */
export default function StructurePanel() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ showClosed, setShowClosed ] = useState( false );

	const load = () => get( '/structure/plan' ).then( setData ).catch( () => {} );
	useEffect( () => {
		load();
		window.addEventListener( 'paydarpress:imported', load );
		return () => window.removeEventListener( 'paydarpress:imported', load );
	}, [] );

	if ( ! data ) {
		return null;
	}
	const set = ( id, status ) => post( '/structure/plan', { id, status } ).then( ( r ) => setData( { ...data, items: r.items } ) );
	const open = data.items.filter( ( i ) => 'open' === i.status );
	const closed = data.items.filter( ( i ) => 'open' !== i.status );
	const shop = data.shop || {};

	return (
		<>
			{ shop.active && (
				<section className="pp-card pp-card--section pp-stage">
					<h2 className="pp-card__title">فروشگاه: { shop.label } { 'auto' === shop.chosen && <span className="pp-badge">تشخیص خودکار</span> }</h2>
					<p className="pp-muted">{ shop.reason }{ shop.traffic && ` کلیک از گوگل: صفحات محصول ${ num( shop.traffic.products ) }، صفحات دسته ${ num( shop.traffic.archives ) }.` }</p>
					<p>{ shop.advice }</p>
					<p className="pp-muted">«کارهای این هفته» بر همین اساس متن دسته‌های محصول یا توضیحات محصولات پرفروش را پیشنهاد می‌دهد. برای تغییر: <a href="#/settings?tab=policy">تنظیمات ← سیاست محتوا</a>.</p>
				</section>
			) }

			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">پیشنهادهای ساختار دسته‌بندی</h2>
				<p className="pp-muted">
					هوش مصنوعی پروفایل، کلمات کلیدی اصلی، دسته‌ها، نمونه عنوان‌ها و داده سرچ کنسول را می‌بیند و فقط تغییرات لازم را پیشنهاد می‌دهد: ادغام، تقسیم، دسته تازه، تغییر نام نمایشی یا noindex. پیشنهادها اینجا فهرست می‌شوند و <strong>هیچ دسته‌ای خودکار تغییر نمی‌کند</strong>؛ هر کدام را خودتان انجام دهید یا رد کنید.
				</p>
				{ canManage && <AiButton command={ structureCommand() } small={ false } /> }
				{ open.length > 0 && (
					<ul className="pp-plan">
						{ open.map( ( i ) => (
							<li key={ i.id } className="pp-plan__item">
								<div className="pp-plan__head">
									<span className={ `pp-badge pp-badge--${ ( PRIORITY[ i.priority ] || PRIORITY.medium )[ 1 ] }` }>{ ( PRIORITY[ i.priority ] || PRIORITY.medium )[ 0 ] }</span>
									<strong>{ i.action_label }:</strong>{ ' ' }
									{ i.name && ( i.edit_url ? <a href={ i.edit_url } target="_blank" rel="noreferrer">«{ i.name }»</a> : `«${ i.name }»` ) }
									{ i.into_name && <> ← «{ i.into_name }»</> }
									{ i.new_name && <> ← «{ i.new_name }»</> }
									{ 'product_cat' === i.taxonomy && <span className="pp-muted"> (دسته محصول)</span> }
								</div>
								<p>{ i.reason }</p>
								{ i.howto && <p className="pp-muted">چطور: { i.howto }</p> }
								{ canManage && (
									<div className="pp-plan__actions">
										{ i.new_url && <Button variant="secondary" size="small" href={ i.new_url } target="_blank">ساخت دسته</Button> }
										<Button variant="secondary" size="small" onClick={ () => set( i.id, 'done' ) }>انجام دادم</Button>
										<Button variant="tertiary" size="small" onClick={ () => set( i.id, 'rejected' ) }>رد می‌کنم</Button>
									</div>
								) }
							</li>
						) ) }
					</ul>
				) }
				{ 0 === open.length && data.items.length > 0 && <p className="pp-ok">پیشنهاد بازی نمانده است.</p> }
				{ closed.length > 0 && (
					<>
						<Button variant="link" onClick={ () => setShowClosed( ! showClosed ) }>{ showClosed ? 'بستن' : `${ num( closed.length ) } پیشنهاد انجام‌شده یا ردشده` }</Button>
						{ showClosed && (
							<ul className="pp-mini-list">
								{ closed.map( ( i ) => (
									<li key={ i.id }>{ 'done' === i.status ? '✓' : '✗' } { i.action_label } «{ i.name || i.new_name }» { canManage && <Button variant="link" onClick={ () => set( i.id, 'open' ) }>بازگرداندن</Button> }</li>
								) ) }
							</ul>
						) }
					</>
				) }
			</section>
		</>
	);
}
