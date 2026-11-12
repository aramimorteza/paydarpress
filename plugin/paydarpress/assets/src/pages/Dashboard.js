import Page from '../components/Page';
import EmptyState from '../components/EmptyState';

const WIDGETS = [
	{ key: 'health', title: 'امتیاز سلامت محتوا' },
	{ key: 'profile', title: 'تکمیل پروفایل' },
	{ key: 'findings', title: 'مهم‌ترین یافته‌ها' },
	{ key: 'orphans', title: 'محتوای یتیم' },
	{ key: 'refresh', title: 'پیشنهاد بازنویسی' },
	{ key: 'scan', title: 'آخرین اسکن' },
];

/**
 * Dashboard. Widgets are filled with data from milestone M3 onward.
 */
export default function Dashboard() {
	return (
		<Page title="پیشخوان" helpKey="dashboard">
			<div className="pp-card pp-card--highlight">
				<EmptyState
					title="هنوز سایت اسکن نشده است"
					text="برای دیدن وضعیت محتوای سایت، اولین اسکن را اجرا کنید."
					action={ <a className="button button-primary" href="#/welcome">شروع کار</a> }
				/>
			</div>
			<div className="pp-grid">
				{ WIDGETS.map( ( widget ) => (
					<section key={ widget.key } className="pp-card">
						<h2 className="pp-card__title">{ widget.title }</h2>
						<p className="pp-muted">پس از اسکن نمایش داده می‌شود.</p>
					</section>
				) ) }
			</div>
		</Page>
	);
}
