import { useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import ScanPanel from '../components/ScanPanel';
import { AiConnection } from '../components/AiConnection';
import useScan from '../lib/useScan';
import { get, post, errorMessage } from '../lib/api';
import { num } from '../lib/format';

const config = window.paydarpress || {};

/**
 * Body of one setup step.
 *
 * @param {Object}   props
 * @param {Object}   props.step    Step from /onboarding.
 * @param {Function} props.onMark  Records a choice (manual, gsc_skip, taxmap).
 * @param {Function} props.reload  Reloads the checklist.
 * @param {boolean}  props.canEdit Manage capability.
 */
function StepBody( { step, onMark, reload, canEdit } ) {
	const scan = useScan();
	const [ busy, setBusy ] = useState( false );

	if ( 'scan' === step.key ) {
		return <ScanPanel state={ scan } doneLink="#/welcome?step=profile" doneLabel="قدم بعد: پروفایل سایت" />;
	}
	if ( 'profile' === step.key ) {
		return (
			<div className="pp-actions-row">
				<Button variant="primary" href="#/wizard" __next40pxDefaultSize>باز کردن راه‌اندازی پروفایل (۵ مرحله کوتاه)</Button>
				<span className="pp-muted">پس از «تأیید و پایان» به همین صفحه برگردید.</span>
			</div>
		);
	}
	if ( 'taxmap' === step.key ) {
		return (
			<div className="pp-actions-row">
				<Button variant="secondary" href="#/profile" __next40pxDefaultSize>دیدن و ویرایش نقشه</Button>
				{ canEdit && (
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						__next40pxDefaultSize
						onClick={ () => {
							setBusy( true );
							post( '/taxmap/confirm' ).then( reload ).finally( () => setBusy( false ) );
						} }
					>
						نقشه پیشنهادی را تأیید کن
					</Button>
				) }
				<span className="pp-muted">بعداً هم از «سایت من ← پروفایل» قابل تغییر است.</span>
			</div>
		);
	}
	if ( 'ai' === step.key ) {
		return (
			<>
				<p className="pp-muted">
					<strong>حالت خودکار:</strong> پایدارپرس با کلید API شما مستقیم از سرویس هوش مصنوعی پیش‌نویس می‌گیرد. درگاه‌های ایرانی (اول‌ای‌آی، گپ جی‌پی‌تی، متیس) از هاست ایران بدون واسط کار می‌کنند و با ریال شارژ می‌شوند.
					<br />
					<strong>حالت دستی:</strong> پرامپت کامل را کپی می‌کنید، در ChatGPT یا Claude یا Gemini می‌چسبانید و پاسخ را برمی‌گردانید؛ بدون کلید و هزینه API.
				</p>
				{ canEdit && <AiConnection onChange={ ( s ) => s.connection.configured && reload() } /> }
				{ canEdit && ! step.done && (
					<div className="pp-actions-row">
						<Button variant="secondary" onClick={ () => onMark( 'manual' ) }>فعلاً حالت دستی را می‌خواهم</Button>
					</div>
				) }
			</>
		);
	}
	if ( 'gsc' === step.key ) {
		return (
			<>
				<ol className="pp-muted">
					<li>در سرچ کنسول گوگل، «Performance» را باز کنید و بازه ۳ ماه را انتخاب کنید.</li>
					<li>دکمه «Export» ← «Download CSV» را بزنید (فایل zip).</li>
					<li>برای ۴۰۴ها: «Pages» ← «Not found (404)» ← «Export».</li>
					<li>فایل‌ها را در صفحه سرچ کنسول پایدارپرس بارگذاری کنید. هر ۷ روز یادآوری می‌شود.</li>
				</ol>
				<div className="pp-actions-row">
					<Button variant="primary" href="#/gsc" __next40pxDefaultSize>بارگذاری خروجی سرچ کنسول</Button>
					{ canEdit && ! step.done && <Button variant="tertiary" onClick={ () => onMark( 'gsc_skip' ) }>سایت هنوز در سرچ کنسول نیست؛ بعداً</Button> }
				</div>
			</>
		);
	}
	return (
		<div className="pp-actions-row">
			<Button variant="primary" href="#/tasks" __next40pxDefaultSize>رفتن به کارهای این هفته</Button>
			<span className="pp-muted">هر روز چند کار کوتاه و مهم؛ همه با تأیید شما.</span>
		</div>
	);
}

/**
 * First-run setup: six steps with live status (architecture section 12.4).
 */
export default function Welcome() {
	const canEdit = !! ( config.caps && config.caps.manage );
	const asked = ( window.location.hash.match( /[?&]step=([a-z_]+)/ ) || [] )[ 1 ] || '';
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ open, setOpen ] = useState( asked );

	const load = () => get( '/onboarding' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	useEffect( () => {
		load();
	}, [] );
	const mark = ( what ) => post( '/onboarding/mark', { what } ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );

	const first = data ? ( data.steps.find( ( s ) => ! s.done ) || data.steps[ data.steps.length - 1 ] ).key : '';
	const current = open || first;
	const all = data && data.done === data.total;

	return (
		<Page title="راه‌اندازی پایدارپرس" helpKey="welcome">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }
			{ data && (
				<>
					<div className="pp-setup__head">
						<div className="pp-bar"><span style={ { width: `${ Math.max( 3, ( data.done / data.total ) * 100 ) }%` } } /></div>
						<span>{ num( data.done ) } از { num( data.total ) } قدم</span>
					</div>
					{ all && (
						<Notice status="success" isDismissible={ false }>
							راه‌اندازی کامل شد. از این به بعد «امروز» کارهای روزانه را نشان می‌دهد.{ ' ' }
							<a href="#/tasks">رفتن به کارهای این هفته</a>
						</Notice>
					) }
					<ol className="pp-setup">
						{ data.steps.map( ( s, i ) => (
							<li key={ s.key } className={ `pp-setup__step${ s.done ? ' is-done' : '' }${ current === s.key ? ' is-open' : '' }` }>
								<button type="button" className="pp-setup__title" onClick={ () => setOpen( current === s.key ? '-' : s.key ) } aria-expanded={ current === s.key }>
									<span className="pp-steps__num">{ s.done ? '✓' : num( i + 1 ) }</span>
									<strong>{ s.title }</strong>
									{ s.done && <span className="pp-badge pp-badge--success">انجام شد</span> }
								</button>
								{ current === s.key && (
									<div className="pp-setup__body">
										<p>{ s.text }</p>
										<StepBody step={ s } onMark={ mark } reload={ load } canEdit={ canEdit } />
									</div>
								) }
							</li>
						) ) }
					</ol>
					<div className="pp-actions-row">
						{ canEdit && ! data.dismissed && (
							<Button variant="tertiary" onClick={ () => mark( 'dismiss' ).then( () => ( window.location.hash = '#/tasks' ) ) }>
								{ all ? 'بستن راه‌اندازی' : 'بقیه را بعداً انجام می‌دهم' }
							</Button>
						) }
						<a href="#/support" className="pp-muted">نیاز به راهنمایی دارید؟ آموزش و پشتیبانی</a>
					</div>
				</>
			) }
		</Page>
	);
}
