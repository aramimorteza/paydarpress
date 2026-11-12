import Page from '../components/Page';
import EmptyState from '../components/EmptyState';
import ScanPanel from '../components/ScanPanel';
import useScan from '../lib/useScan';

const WIDGETS = [
	{ key: 'health', title: 'امتیاز سلامت محتوا' },
	{ key: 'profile', title: 'تکمیل پروفایل' },
	{ key: 'findings', title: 'مهم‌ترین یافته‌ها' },
	{ key: 'orphans', title: 'محتوای یتیم' },
	{ key: 'refresh', title: 'پیشنهاد بازنویسی' },
];

/**
 * Dashboard. Health widgets are filled from milestone M3 (audit engine) onward.
 */
export default function Dashboard() {
	const scan = useScan();
	const hasScan = !! scan.scan;

	return (
		<Page title="پیشخوان" helpKey="dashboard">
			{ scan.loaded && ! hasScan ? (
				<div className="pp-card pp-card--highlight">
					<EmptyState
						title="هنوز سایت اسکن نشده است"
						text="برای دیدن وضعیت محتوای سایت، اولین اسکن را اجرا کنید."
						action={ <a className="button button-primary" href="#/welcome">شروع کار</a> }
					/>
				</div>
			) : (
				<section className="pp-card pp-card--highlight">
					<h2 className="pp-card__title">اسکن سایت</h2>
					<ScanPanel state={ scan } />
				</section>
			) }
			<div className="pp-grid">
				{ WIDGETS.map( ( widget ) => (
					<section key={ widget.key } className="pp-card">
						<h2 className="pp-card__title">{ widget.title }</h2>
						<p className="pp-muted">در مرحله بعدی (ممیزی سلامت) فعال می‌شود.</p>
					</section>
				) ) }
			</div>
		</Page>
	);
}
