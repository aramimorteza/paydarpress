import Page from '../components/Page';
import EmptyState from '../components/EmptyState';

/**
 * Temporary page for sections that are built in later milestones.
 *
 * @param {Object} props
 * @param {string} props.title   Page title.
 * @param {string} props.helpKey Help key.
 */
export default function Placeholder( { title, helpKey } ) {
	return (
		<Page title={ title } helpKey={ helpKey }>
			<EmptyState
				title="این بخش در حال ساخت است"
				text="پس از اولین اسکن سایت، اطلاعات این بخش اینجا نمایش داده می‌شود."
				action={ <a className="button button-primary" href="#/welcome">شروع کار</a> }
			/>
		</Page>
	);
}
