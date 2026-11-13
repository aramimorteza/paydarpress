import { useEffect, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import { AiUsage } from '../components/AiConnection';
import { get, errorMessage } from '../lib/api';

const config = window.paydarpress || {};

/** What automatic mode does; each card says where its button will live. */
const TASKS = [
	{ icon: '💡', title: 'پیشنهاد موضوع', text: 'از پروفایل، فهرست محتوا و «جست‌وجوهای بی‌مقاله» سرچ کنسول، موضوع تازه و غیرتکراری می‌سازد و به «موضوعات پیشنهادی» می‌برد.', where: 'موضوعات پیشنهادی', ready: false },
	{ icon: '✍️', title: 'نوشتن مقاله کامل', text: 'برای موضوع تأییدشده، مقاله را با قوانین ContentMaster می‌نویسد و از همان بررسی‌های «ورود مقالات» عبور می‌دهد: پیش‌نویس با کلمه کلیدی، متا و لینک داخلی.', where: 'موضوعات پیشنهادی', ready: false },
	{ icon: '♻️', title: 'بازنویسی مقالات قدیمی', text: 'برای نوشته‌های «پیشنهاد بازنویسی» و «نزدیک صفحه اول» نسخه به‌روز می‌سازد؛ نوشته منتشرشده دست نمی‌خورد تا شما مقایسه و تأیید کنید.', where: 'پیشنهاد بازنویسی و سرچ کنسول', ready: false },
	{ icon: '🔗', title: 'متن لینک و متا', text: 'متن لینک برای صف لینک داخلی، و عنوان و توضیحات متای تازه برای صفحات «رتبه خوب، کلیک کم».', where: 'صف لینک و سرچ کنسول', ready: false },
	{ icon: '🗂️', title: 'متن دسته‌بندی‌ها و تصاویر', text: 'توضیح صفحه دسته‌بندی مقالات و محصولات، و متن جایگزین (alt) برای تصاویری که ندارند یا متن بی‌معنی دارند.', where: 'دسته‌ها و برچسب‌ها، سلامت تصاویر', ready: false },
];

export default function AutoPage() {
	const canManage = !! ( config.caps && config.caps.manage );
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( canManage ) {
			get( '/ai' ).then( setState ).catch( ( e ) => setError( errorMessage( e ) ) );
		}
	}, [ canManage ] );

	if ( ! canManage ) {
		return (
			<Page title="تولید خودکار" helpKey="auto">
				<div className="pp-card"><EmptyState title="دسترسی ندارید" text="تولید خودکار فقط برای مدیر سایت در دسترس است." /></div>
			</Page>
		);
	}

	const configured = state && state.connection.configured;

	return (
		<Page title="تولید خودکار با هوش مصنوعی" helpKey="auto">
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ ! state && ! error && <Spinner /> }

			{ state && (
				<section className={ `pp-card pp-auto-status${ configured ? ' is-ready' : '' }` }>
					{ configured ? (
						<>
							<strong>اتصال آماده است</strong> — مدل <code dir="ltr">{ state.connection.model }</code>.{ ' ' }
							<a href="#/settings?tab=ai">تغییر اتصال یا سقف ماهانه</a>
						</>
					) : (
						<>
							<strong>هنوز به سرویس هوش مصنوعی وصل نشده‌اید.</strong> در «تنظیمات ← هوش مصنوعی» آدرس سرویس، کلید API و مدل را وارد کنید و «آزمایش اتصال» را بزنید.{ ' ' }
							<a className="button button-primary" href="#/settings?tab=ai">تنظیم اتصال</a>
							<p className="pp-muted">تا آن موقع، حالت دستی کامل کار می‌کند: «خروجی سایت» را در ContentMaster بدهید و مقالات را با «ورود پاسخ هوش مصنوعی» برگردانید.</p>
						</>
					) }
				</section>
			) }

			<section className="pp-card pp-card--section">
				<h2 className="pp-card__title">کارهایی که خودکار انجام می‌شود</h2>
				<p className="pp-help-text">
					همه کارها با همان قوانین ContentMaster و پروفایل سایت انجام می‌شوند و نتیجه همیشه <strong>پیش‌نویس</strong> است. انتشار فقط با تأیید شما و در زمانی که تعیین می‌کنید انجام می‌شود. دکمه هر کار کنار همان موردی است که لازمش دارد.
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
		</Page>
	);
}
