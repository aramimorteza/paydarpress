/**
 * Empty state with an explanation and a next step (architecture section 12.6).
 *
 * @param {Object} props
 * @param {string} props.title    Headline.
 * @param {string} props.text     Explanation.
 * @param {*}      props.action   Optional action element.
 */
export default function EmptyState( { title, text, action } ) {
	return (
		<div className="pp-empty">
			<p className="pp-empty__title">{ title }</p>
			{ text && <p className="pp-empty__text">{ text }</p> }
			{ action && <div className="pp-empty__action">{ action }</div> }
		</div>
	);
}
