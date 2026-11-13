import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { AiUsage } from '../components/AiConnection';
import InlineImport from '../components/InlineImport';
import { JobProgress, useJobPolling } from '../components/AutoRun';
import { get, post, errorMessage } from '../lib/api';
import { ago, num } from '../lib/format';

const config = window.paydarpress || {};

/** What automatic mode does; each card says where its button will live. */
const TASKS = [
	{ icon: '💡', title: 'پیشنهاد موضوع', text: 'از پروفایل، فهرست محتوا و «جست‌وجوهای بی‌مقاله» سرچ کنسول، موضوع تازه و غیرتکراری می‌سازد و به «موضوعات پیشنهادی» می‌برد.', where: 'موضوعات پیشنهادی و امروز', ready: true },
	{ icon: '✍️', title: 'نوشتن مقاله کامل', text: 'برای موضوع تأییدشده، مقاله را با قوانین ContentMaster می‌نویسد و از همان بررسی‌های «ورود مقالات» عبور می‌دهد: پیش‌نویس با کلمه کلیدی، متا و لینک داخلی.', where: 'موضوعات پیشنهادی', ready: true },
	{ icon: '♻️', title: 'بازنویسی مقالات قدیمی', text: 'برای نوشته‌های «پیشنهاد بازنویسی» و «نزدیک صفحه اول» نسخه به‌روز می‌سازد؛ نوشته منتشرشده دست نمی‌خورد تا شما مقایسه و تأیید کنید.', where: 'بازنویسی، سرچ کنسول و امروز', ready: true },
	{ icon: '🔎', title: 'عنوان و توضیحات متا', text: 'عنوان سئو و توضیحات متای تازه برای صفحاتی که رتبه خوب اما کلیک کم دارند؛ تا وارد نکنید، متای فعلی عوض نمی‌شود و نتیجه‌اش در «نتیجه تغییرات» سنجیده می‌شود.', where: 'سرچ کنسول و امروز', ready: true },
	{ icon: '🗂️', title: 'متن دسته‌بندی‌ها و پیشنهاد ساختار', text: 'متن معرفی صفحه دسته‌بندی مقالات و محصولات، و بررسی ساختار دسته‌ها (ادغام، تقسیم، دسته تازه) که فقط پیشنهاد است و تصمیمش با شماست.', where: 'دسته‌ها و برچسب‌ها', ready: true },
];

const STATUS_CLASS = { queued: 'info', running: 'info', ready: 'warning', failed: 'danger', imported: 'success' };

/**
 * One job row; polls while it runs.
 *
 * @param {Object}   props
 * @param {Object}   props.initial Job.
 * @param {Function} props.onOpen  Open review.
 * @param {Function} props.onChange Reload list.
 */
function JobRow( { initial, onOpen, onChange } ) {
	const [ job, setJob ] = useState( initial );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => setJob( initial ), [ initial ] );
	useJobPolling( job, setJob, setError );

	const op = ( name ) => {
		setBusy( true );
		post( `/ai/jobs/${ job.id }/${ name }` )
			.then( onChange )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<tr>
			<td>
				<strong>{ job.title }</strong>
				<div className="pp-muted">{ job.task_label } · { ago( job.created_at ) }{ job.model ? ` · ${ job.model }` : '' }</div>
				<JobProgress job={ job } />
				{ 'failed' === job.status && job.error && <div className="pp-check pp-check--error">{ job.error }</div> }
				{ error && <div className="pp-check pp-check--error">{ error }</div> }
			</td>
			<td><span className={ `pp-badge pp-badge--${ STATUS_CLASS[ job.status ] || 'info' }` }>{ job.status_label }</span></td>
			<td className="pp-num">{ job.output_tokens ? num( job.input_tokens + job.output_tokens ) : '—' }</td>
			<td className="pp-actions-cell">
				{ 'ready' === job.status && <Button variant="primary" size="small" onClick={ () => onOpen( job ) }>بررسی و ورود</Button> }
				{ 'failed' === job.status && <Button variant="secondary" size="small" onClick={ () => op( 'retry' ) } isBusy={ busy } disabled={ busy }>دوباره</Button> }
				{ ( 'failed' === job.status || 'ready' === job.status ) && <Button variant="tertiary" size="small" onClick={ () => op( 'discard' ) } disabled={ busy }>حذف</Button> }
			</td>
		</tr>
	);
}

export default function AutoPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ open, setOpen ] = useState( null );

	const load = useCallback( () => {
		get( '/ai/jobs' ).then( setData ).catch( ( e ) => setError( errorMessage( e ) ) );
	}, [] );

	useEffect( () => {
		if ( canManage ) {
			load();
		}
	}, [ canManage, load ] );

	if ( ! canManage ) {
		return (
			<Page title="تولید خودکار" helpKey="auto">
				<div className="pp-card"><EmptyState title="دسترسی ندارید" text="تولید خودکار فقط برای مدیر سایت در دسترس است." /></div>
			</Page>
		);
	}

	const state = data && data.state;
	const configured = state && state.connection.configured;

	return (
		<Page title="تولید خودکار با هوش مصنوعی" helpKey="auto">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! data && ! error && <Spinner /> }

			{ state && (
				<section className={ `pp-card pp-auto-status${ configured ? ' is-ready' : '' }` }>
					{ configured ? (
						<>
							<strong>اتصال آماده است</strong> — مدل <code dir="ltr">{ state.connection.model }</code>.{ ' ' }
							<a href="#/settings?tab=ai">تغییر اتصال یا سقف ماهانه</a>
							<p className="pp-muted">دکمه «… با هوش مصنوعی» کنار هر مورد (موضوعات، بازنویسی، سرچ کنسول، دسته‌ها و «امروز») حالا گزینه «ساخت خودکار» دارد. نتیجه‌ها اینجا جمع می‌شوند.</p>
						</>
					) : (
						<>
							<strong>هنوز به سرویس هوش مصنوعی وصل نشده‌اید.</strong> در «تنظیمات ← هوش مصنوعی» آدرس سرویس، کلید API و مدل را وارد کنید و «آزمایش اتصال» را بزنید.{ ' ' }
							<a className="button button-primary" href="#/settings?tab=ai">تنظیم اتصال</a>
							<p className="pp-muted">تا آن موقع، حالت دستی کامل کار می‌کند: پرامپت کامل را کپی کنید و پاسخ را در همان پنجره بچسبانید.</p>
						</>
					) }
				</section>
			) }

			{ data && data.items.length > 0 && (
				<section className="pp-card pp-card--flush">
					<h2 className="pp-card__title pp-card__title--pad">کارهای اخیر{ data.ready > 0 ? ` — ${ num( data.ready ) } پاسخ منتظر بررسی` : '' }</h2>
					<table className="pp-table">
						<thead><tr><th>کار</th><th>وضعیت</th><th className="pp-num">توکن</th><th></th></tr></thead>
						<tbody>
							{ data.items.map( ( j ) => <JobRow key={ j.id } initial={ j } onOpen={ setOpen } onChange={ load } /> ) }
						</tbody>
					</table>
				</section>
			) }

			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">کارهایی که خودکار انجام می‌شود</h2>
				<p className="pp-help-text">
					همه کارها با همان قوانین ContentMaster و پروفایل سایت انجام می‌شوند و نتیجه همیشه <strong>پیش‌نویس</strong> یا پیشنهاد است. انتشار فقط با تأیید شما و با سرعت «سیاست محتوا» انجام می‌شود؛ در سایت‌های حساس، پیش از انتشار «تأیید بازبینی» لازم است.
				</p>
				<div className="pp-auto-tasks">
					{ TASKS.map( ( t ) => (
						<div key={ t.title } className="pp-auto-task">
							<div className="pp-auto-task__icon" aria-hidden="true">{ t.icon }</div>
							<div>
								<strong>{ t.title }</strong> { ! t.ready && <span className="pp-badge">به‌زودی</span> }
								<p>{ t.text }</p>
								<div className="pp-muted">دکمه در: { t.where }</div>
							</div>
						</div>
					) ) }
				</div>
			</section>

			{ state && <AiUsage state={ state } /> }

			{ open && (
				<Modal title={ `بررسی «${ open.title }»` } onRequestClose={ () => { setOpen( null ); load(); } } className="pp-modal pp-ai-modal" size="medium">
					<InlineImport job={ open.id } onClosed={ ( how ) => { if ( 'discarded' === how ) { setOpen( null ); } load(); } } />
				</Modal>
			) }
		</Page>
	);
}
