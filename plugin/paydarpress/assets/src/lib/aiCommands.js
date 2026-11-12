/**
 * ContentMaster commands for "create with AI" buttons.
 *
 * Until the AI API is connected (phase 2), each button prepares a ready command for the
 * site's ContentMaster conversation; in phase 2 the same buttons will create drafts directly.
 */

/** Finding codes that call for refreshing the page itself. */
const REFRESH_CODES = [ 'THIN_CONTENT', 'OUTDATED_YEAR', 'NO_DIRECT_ANSWER', 'NO_H2', 'GSC_NEAR_PAGE_ONE', 'GSC_LOW_CTR', 'GSC_CLICK_DROP', 'GSC_NOT_IN_SEARCH', 'DUPLICATE_FOCUS_KEYWORD', 'NO_INTERNAL_LINKS' ];

/** Finding codes that call for new supporting content. */
const SUPPORT_CODES = [ 'ORPHAN_POST', 'ORPHAN_PAGE', 'ORPHAN_PRODUCT', 'WEAK_INBOUND', 'MENU_ONLY' ];

/** Taxonomy finding codes that call for more articles in the term. */
const TERM_CODES = [ 'WEAK_CATEGORY', 'EMPTY_CATEGORY', 'THIN_TAG', 'EMPTY_TAG', 'INDEXED_THIN_ARCHIVE' ];

/**
 * Builds the command for a finding, or null when AI cannot help with it.
 *
 * @param {Object} finding Finding from the API (code, title, object, details).
 */
export function commandForFinding( finding ) {
	const object = finding.object || {};
	if ( REFRESH_CODES.includes( finding.code ) && object.url ) {
		return refreshCommand( object.title, object.url, [ finding.title ] );
	}
	if ( SUPPORT_CODES.includes( finding.code ) && object.url ) {
		return supportCommand( object.title, object.url );
	}
	if ( TERM_CODES.includes( finding.code ) && object.title ) {
		return termCommand( object.title, 'tag' === object.type || 'post_tag' === object.type ? 'tag' : 'category' );
	}
	return null;
}

/**
 * Refresh an existing page.
 *
 * @param {string}   title   Page title.
 * @param {string}   url     Page URL.
 * @param {string[]} reasons Problems found by PaydarPress.
 */
export function refreshCommand( title, url, reasons = [] ) {
	return {
		label: 'بازنویسی با هوش مصنوعی',
		summary: `بروزرسانی «${ title }»`,
		command: `بروزرسانی: ${ decodeURI( url ) }${ reasons.length ? `\nمشکلاتی که پایدارپرس پیدا کرده: ${ reasons.join( '، ' ) }` : '' }`,
		hint: 'ContentMaster متن فعلی مقاله را از شما می‌خواهد؛ متن را از ویرایشگر کپی کنید و بفرستید. نتیجه را با «خروجی پایدارپرس» وارد کنید یا مستقیم در نوشته اعمال کنید.',
	};
}

/**
 * A new article that gives an orphan (or weakly linked) page natural inbound links.
 *
 * @param {string} title Target title.
 * @param {string} url   Target URL.
 */
export function supportCommand( title, url ) {
	return {
		label: 'تولید مقاله پشتیبان با هوش مصنوعی',
		summary: `مقاله جدید با لینک به «${ title }»`,
		command: `مقاله آزاد: یک موضوع جدید و غیرتکراری مرتبط با «${ title }» پیدا کن و مقاله کامل بنویس که در متن، به‌طور طبیعی به این صفحه لینک بدهد: ${ decodeURI( url ) }`,
		hint: 'پس از نوشتن، فرمان «خروجی پایدارپرس» را بزنید و متن را در «ورود از ContentMaster» بچسبانید تا پیش‌نویس ساخته شود.',
	};
}

/**
 * New articles for a weak category or tag.
 *
 * @param {string} name Term name.
 * @param {string} kind category|tag.
 */
export function termCommand( name, kind ) {
	return {
		label: 'تولید مقاله برای این ' + ( 'tag' === kind ? 'برچسب' : 'دسته' ),
		summary: `موضوعات جدید برای ${ 'tag' === kind ? 'برچسب' : 'دسته' } «${ name }»`,
		command: `موضوع جدید: ${ name }`,
		hint: 'ContentMaster ۱۰ موضوع غیرتکراری در این دسته پیشنهاد می‌دهد؛ با «مقاله [شماره]» بنویسید و با «خروجی پایدارپرس» وارد کنید.',
	};
}

/**
 * A new article for a search query that has impressions but no dedicated page.
 *
 * @param {string} query Search query.
 */
export function queryCommand( query ) {
	return {
		label: 'تولید مقاله برای این عبارت',
		summary: `مقاله برای عبارت «${ query }»`,
		command: `مقاله آزاد: ${ query }\n(این عبارت در سرچ کنسول سایت نمایش دارد؛ اگر مقاله‌ای در سایت همین موضوع را پوشش می‌دهد، به‌جای مقاله جدید پیشنهاد بروزرسانی بده.)`,
		hint: 'پس از نوشتن، با «خروجی پایدارپرس» آن را به‌صورت پیش‌نویس وارد کنید.',
	};
}
