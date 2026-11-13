import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, SelectControl, Spinner } from '@wordpress/components';
import AiButton from './AiButton';
import InlineImport from './InlineImport';
import { get, post, errorMessage } from '../lib/api';
import { ago, num } from '../lib/format';
import { classifyCommand } from '../lib/aiCommands';

/**
 * Polls a batch until it stops running. One request at a time; the request may run the
 * current job itself when WP-Cron does not.
 *
 * @param {Object}   batch    Batch.
 * @param {Function} setBatch Setter.
 */
export function useBatchPolling( batch, setBatch ) {
	const alive = useRef( true );
	useEffect( () => () => {
		alive.current = false;
	}, [] );
	useEffect( () => {
		if ( ! batch || 'running' !== batch.status ) {
			return undefined;
		}
		const t = setTimeout( () => {
			get( `/ai/batches/${ batch.id }` )
				.then( ( b ) => alive.current && setBatch( b ) )
				.catch( () => alive.current && setBatch( { ...batch } ) );
		}, 3000 );
		return () => clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ batch ] );
}

/**
 * Progress and review of one batch.
 *
 * @param {Object}   props
 * @param {Object}   props.initial  Batch.
 * @param {Function} props.onChange Reload after import or discard.
 */
export function BatchCard( { initial, onChange } ) {
	const [ batch, setBatch ] = useState( initial );
	const [ open, setOpen ] = useState( false );
	useEffect( () => setBatch( initial ), [ initial ] );
	useBatchPolling( batch, setBatch );

	const pct = batch.total ? Math.round( ( batch.done / batch.total ) * 100 ) : 0;
	const stop = () => post( `/ai/batches/${ batch.id }/discard` ).then( onChange );

	return (
		<div className="pp-card pp-card--section">
			<strong>{ batch.title || batch.task_label }</strong> <span className={ `pp-badge${ 'ready' === batch.status ? ' pp-badge--warning' : '' }` }>{ batch.status_label }</span>
			<div className="pp-progress"><span style={ { width: `${ pct }%` } } /></div>
			<div className="pp-muted">
				{ num( batch.done ) } از { num( batch.total ) } درخواست انجام شد
				{ batch.counts.failed > 0 && ` · ${ num( batch.counts.failed ) } ناموفق` }
				{ batch.tokens > 0 && ` · ${ num( batch.tokens ) } توکن` }
				{ ' · ' }{ ago( batch.created_at ) }
			</div>
			{ batch.errors.length > 0 && <ul className="pp-checks">{ batch.errors.map( ( e, i ) => <li key={ i } className="pp-check pp-check--error">{ e }</li> ) }</ul> }
			<div className="pp-ai-modal__row">
				{ batch.counts.ready > 0 && 'running' !== batch.status && <Button variant="primary" onClick={ () => setOpen( true ) }>بررسی و ورود نتیجه‌ها</Button> }
				{ batch.counts.ready > 0 && 'running' === batch.status && <span className="pp-muted">بررسی پس از پایان همه درخواست‌ها؛ می‌توانید صفحه را ببندید.</span> }
				{ [ 'running', 'ready' ].includes( batch.status ) && <Button variant="tertiary" isDestructive onClick={ stop }>{ 'running' === batch.status ? 'توقف' : 'کنار گذاشتن' }</Button> }
			</div>
			{ open && (
				<Modal title={ `بررسی «${ batch.title }»` } onRequestClose={ () => {
					setOpen( false );
					onChange();
				} } className="pp-modal pp-ai-modal" size="large">
					<InlineImport batch={ batch.id } onClosed={ () => onChange() } />
				</Modal>
			) }
		</div>
	);
}

/**
 * Classification of existing content (taxonomies page).
 */
export default function ClassifyPanel() {
	const canManage = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );
	const [ kind, setKind ] = useState( '' );
	const [ scope, setScope ] = useState( 'weak' );
	const [ plan, setPlan ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		get( `/classify?kind=${ kind }&scope=${ scope }` )
			.then( ( p ) => {
				setPlan( p );
				if ( ! kind ) {
					setKind( p.kind );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	}, [ kind, scope ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		const h = () => load();
		window.addEventListener( 'paydarpress:imported', h );
		return () => window.removeEventListener( 'paydarpress:imported', h );
	}, [ load ] );

	if ( ! plan ) {
		return error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : <Spinner />;
	}

	const start = () => {
		setBusy( true );
		setError( '' );
		post( '/ai/batch', { task: 'classify', kind: plan.kind, scope } )
			.then( load )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const undo = ( id ) => {
		setBusy( true );
		post( `/classify/undo/${ id }` )
			.then( load )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};
	const running = plan.active.some( ( b ) => 'running' === b.status );

	return (
		<section className="pp-card pp-card--section pp-classify">
			<h2 className="pp-card__title">طبقه‌بندی محتوای موجود با هوش مصنوعی</h2>
			<p className="pp-muted">
				هوش مصنوعی عنوان، سرتیترها و ۳۰۰ کلمه اول هر مطلب را می‌خواند و از دسته‌ها و برچسب‌های فعال «نقشه دسته‌ها و برچسب‌ها» پیشنهاد می‌دهد. <strong>فقط افزودن</strong>؛ هیچ دسته یا برچسبی حذف نمی‌شود. موارد نامرتبط فقط برای تصمیم شما ثبت می‌شوند. برچسب تازه فقط با تیک شما ساخته می‌شود و هر مرحله یکجا قابل بازگشت است.
			</p>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ ! plan.structure_checked && <Notice status="warning" isDismissible={ false }>پیشنهاد: پیش از طبقه‌بندی، «بررسی ساختار دسته‌ها» (بالای همین صفحه) را انجام دهید تا مطالب در دسته‌ای قرار نگیرند که بعداً ادغام می‌شود.</Notice> }
			{ plan.category_in_url && <Notice status="info" isDismissible={ false }>دسته در آدرس مطالب شماست؛ اگر دسته اصلی مطلبی عوض شود، آدرسش طبق وردپرس عوض می‌شود و آدرس قبلی خودکار با ۳۰۱ به آدرس جدید می‌رود.</Notice> }

			<div className="pp-classify__options">
				{ Object.keys( plan.kinds ).length > 1 && (
					<SelectControl __nextHasNoMarginBottom label="نوع محتوا" value={ plan.kind } options={ Object.entries( plan.kinds ).map( ( [ value, label ] ) => ( { value, label } ) ) } onChange={ setKind } />
				) }
				<SelectControl
					__nextHasNoMarginBottom
					label={ <>دامنه <span className="pp-default">پیش‌فرض: کم‌طبقه‌بندی‌شده</span></> }
					value={ scope }
					options={ [ { value: 'weak', label: 'کم‌طبقه‌بندی‌شده (بدون دسته واقعی یا کمتر از ۲ برچسب)' }, { value: 'all', label: 'همه مطالب منتشرشده' } ] }
					onChange={ setScope }
				/>
			</div>
			<p>
				{ num( plan.items ) } { plan.unit } نیاز به بررسی دارد؛ { num( plan.requests ) } درخواست هوش مصنوعی ({ num( plan.chunk ) } مطلب در هر درخواست).
				{ 'product' === plan.kind && ' محصولات پیش از ورود باید یکی‌یکی تأیید شوند (اثر روی منو و فیلتر فروشگاه).' }
			</p>
			{ canManage && plan.items > 0 && (
				<div className="pp-ai-modal__row">
					{ plan.ai && <Button variant="primary" onClick={ start } isBusy={ busy } disabled={ busy || running }>طبقه‌بندی خودکار ({ num( plan.requests ) } درخواست)</Button> }
					<AiButton command={ classifyCommand( plan.kind, scope, plan.unit ) } small={ false } variant={ plan.ai ? 'secondary' : 'primary' } />
				</div>
			) }
			{ plan.items === 0 && <p className="pp-ok">همه { plan.label } این دامنه بررسی شده‌اند.</p> }

			{ plan.active.map( ( b ) => <BatchCard key={ b.id } initial={ b } onChange={ load } /> ) }

			{ plan.flags > 0 && <p className="pp-muted">{ num( plan.flags ) } مورد «دسته یا برچسب نامرتبط با مطلب» برای تصمیم شما ثبت شده است.</p> }
			{ plan.log.length > 0 && (
				<details className="pp-classify__log">
					<summary>مراحل اعمال‌شده ({ num( plan.log.length ) })</summary>
					<ul className="pp-mini-list">
						{ plan.log.map( ( l ) => (
							<li key={ l.id }>
								{ ago( l.at ) }: { num( l.items ) } مطلب{ l.created ? `، ${ num( l.created ) } برچسب تازه` : '' }{ ' ' }
								{ canManage && <Button variant="link" isDestructive onClick={ () => undo( l.id ) } disabled={ busy }>بازگردانی کامل این مرحله</Button> }
							</li>
						) ) }
					</ul>
				</details>
			) }
		</section>
	);
}
