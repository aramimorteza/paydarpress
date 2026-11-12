import { Button } from '@wordpress/components';
import Page from '../components/Page';

const STEPS = [
	{ title: 'اسکن سایت', text: 'شناسایی نوشته‌ها، برگه‌ها، دسته‌ها، لینک‌ها و تصاویر — در پس‌زمینه و بدون کند کردن سایت.' },
	{ title: 'تکمیل پروفایل', text: 'بیشتر اطلاعات خودکار پیشنهاد می‌شود؛ فقط تأیید می‌کنید.' },
	{ title: 'گزارش سلامت', text: 'مهم‌ترین مشکلات محتوا و ۵ اقدام سریع پیشنهادی.' },
	{ title: 'خروجی برای ContentMaster', text: 'فایل JSON برای تولید محتوای دقیق‌تر با هوش مصنوعی.' },
];

/**
 * First-run welcome screen (architecture section 12.4).
 */
export default function Welcome() {
	const canManage = !! ( window.paydarpress && window.paydarpress.caps && window.paydarpress.caps.manage );

	return (
		<Page title="به پایدارپرس خوش آمدید" helpKey="welcome">
			<ol className="pp-steps">
				{ STEPS.map( ( step, index ) => (
					<li key={ step.title } className="pp-steps__item">
						<span className="pp-steps__num">{ ( index + 1 ).toLocaleString( 'fa-IR' ) }</span>
						<div>
							<strong>{ step.title }</strong>
							<p>{ step.text }</p>
						</div>
					</li>
				) ) }
			</ol>
			<div className="pp-welcome__actions">
				<Button variant="primary" disabled={ ! canManage } __next40pxDefaultSize>
					شروع اسکن
				</Button>
				<p className="pp-muted">
					{ canManage
						? 'اسکن سایت در مرحله بعدی توسعه فعال می‌شود.'
						: 'برای شروع اسکن به دسترسی مدیر نیاز است.' }
				</p>
			</div>
		</Page>
	);
}
