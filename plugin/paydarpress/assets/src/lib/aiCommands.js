/**
 * "Create with AI" actions. Each one carries:
 * - `command`: the short command for a ContentMaster conversation (for users who have one);
 * - `prompt`: task + args for /prompt, which builds a complete, self-contained prompt for any
 *   chat assistant (ChatGPT, Claude, Gemini). Automatic mode sends the same prompt to the API.
 */

/** Finding codes that call for refreshing the page itself. */
const REFRESH_CODES = [ 'THIN_CONTENT', 'OUTDATED_YEAR', 'NO_DIRECT_ANSWER', 'NO_H2', 'GSC_NEAR_PAGE_ONE', 'GSC_CLICK_DROP', 'GSC_NOT_IN_SEARCH', 'DUPLICATE_FOCUS_KEYWORD', 'NO_INTERNAL_LINKS' ];

/** Finding codes that call for new supporting content. */
const SUPPORT_CODES = [ 'ORPHAN_POST', 'ORPHAN_PAGE', 'ORPHAN_PRODUCT', 'WEAK_INBOUND', 'MENU_ONLY' ];

/** Taxonomy finding codes that call for more articles in the term. */
const TERM_CODES = [ 'WEAK_CATEGORY', 'EMPTY_CATEGORY', 'THIN_TAG', 'EMPTY_TAG', 'INDEXED_THIN_ARCHIVE' ];

/** Taxonomy codes about the archive text itself. */
const TERM_TEXT_CODES = [ 'EMPTY_CATEGORY_DESC' ];

const IMPORT_HINT = 'پاسخ هوش مصنوعی یک بلوک JSON است؛ آن را در «ورود پاسخ هوش مصنوعی» بچسبانید. همه‌چیز پیش‌نویس یا پیش‌نمایش می‌شود و بدون تأیید شما چیزی منتشر نمی‌شود.';

/**
 * Builds the action for a finding, or null when AI cannot help with it.
 *
 * @param {Object} finding Finding from the API (code, title, object, details).
 */
export function commandForFinding( finding ) {
	const object = finding.object || {};
	if ( [ 'GSC_LOW_CTR', 'NO_META_DESCRIPTION' ].includes( finding.code ) && object.url ) {
		return metaCommand( object.title, object.url, object.id );
	}
	if ( REFRESH_CODES.includes( finding.code ) && object.url ) {
		return refreshCommand( object.title, object.url, [ finding.title ], object.id, object.type );
	}
	if ( SUPPORT_CODES.includes( finding.code ) && object.url ) {
		return supportCommand( object.title, object.url, object.id );
	}
	if ( TERM_TEXT_CODES.includes( finding.code ) && object.title ) {
		return termTextCommand( object.title, object.id );
	}
	if ( TERM_CODES.includes( finding.code ) && object.title ) {
		return termCommand( object.title, 'tag' === object.type || 'post_tag' === object.type ? 'tag' : 'category', object.id );
	}
	return null;
}

/**
 * Rewrite an existing post, page or product.
 *
 * @param {string}   title   Title.
 * @param {string}   url     URL.
 * @param {string[]} reasons Problems found by PaydarPress.
 * @param {number}   id      Post ID.
 * @param {string}   type    Post type (product changes the wording).
 */
export function refreshCommand( title, url, reasons = [], id = 0, type = 'post' ) {
	const product = 'product' === type;
	return {
		label: product ? 'بازنویسی توضیحات محصول با هوش مصنوعی' : 'بازنویسی با هوش مصنوعی',
		summary: `${ product ? 'بازنویسی توضیحات محصول' : 'بازنویسی' } «${ title }»`,
		command: `بروزرسانی: ${ safeDecode( url ) }${ reasons.length ? `\nمشکلاتی که پایدارپرس پیدا کرده: ${ reasons.join( '، ' ) }` : '' }`,
		prompt: id ? { task: 'rewrite', args: { post_id: id, reasons } } : null,
		hint: 'متن فعلی خودکار داخل پرامپت کامل است. بازنویسی به‌صورت پیش‌نویس جدا وارد می‌شود و نوشته فعلی فقط با دکمه «جایگزینی» در ویرایشگر عوض می‌شود.',
	};
}

/**
 * A new article that gives an orphan (or weakly linked) page natural inbound links.
 *
 * @param {string} title Target title.
 * @param {string} url   Target URL.
 * @param {number} id    Target ID.
 */
export function supportCommand( title, url, id = 0 ) {
	return {
		label: 'تولید مقاله پشتیبان با هوش مصنوعی',
		summary: `مقاله جدید با لینک به «${ title }»`,
		command: `مقاله آزاد: یک موضوع جدید و غیرتکراری مرتبط با «${ title }» پیدا کن و مقاله کامل بنویس که در متن، به‌طور طبیعی به این صفحه لینک بدهد: ${ safeDecode( url ) }`,
		prompt: id ? { task: 'article', args: { link_to: id } } : null,
		hint: IMPORT_HINT,
	};
}

/**
 * New topics for a weak category or tag.
 *
 * @param {string} name Term name.
 * @param {string} kind category|tag.
 * @param {number} id   Term ID.
 */
export function termCommand( name, kind, id = 0 ) {
	const label = 'tag' === kind ? 'برچسب' : 'دسته';
	return {
		label: `پیشنهاد موضوع برای این ${ label }`,
		summary: `موضوعات جدید برای ${ label } «${ name }»`,
		command: `موضوع جدید: ${ name }`,
		prompt: id && 'tag' !== kind ? { task: 'topics', args: { category_id: id } } : { task: 'topics', args: {} },
		hint: 'موضوع‌ها به «موضوعات پیشنهادی» می‌روند؛ از آنجا برای هر موضوع مقاله بخواهید.',
	};
}

/**
 * Introduction text for a category, tag or product category archive.
 *
 * @param {string} name Term name.
 * @param {number} id   Term ID.
 */
export function termTextCommand( name, id ) {
	return {
		label: 'نوشتن متن معرفی با هوش مصنوعی',
		summary: `متن معرفی «${ name }»`,
		command: `متن دسته: ${ name }\nیک متن معرفی ۱۵۰ تا ۳۰۰ کلمه‌ای برای بالای صفحه این دسته بنویس و در «خروجی پایدارپرس» در بخش term_descriptions بده.`,
		prompt: { task: 'term', args: { term_id: id } },
		hint: 'با ورود، متن پیش‌نمایش می‌شود و پس از تأیید شما در توضیحات همین دسته قرار می‌گیرد.',
	};
}

/**
 * New SEO title and meta description (good position, few clicks).
 *
 * @param {string} title Title.
 * @param {string} url   URL.
 * @param {number} id    Post ID.
 */
export function metaCommand( title, url, id ) {
	return {
		label: 'عنوان و متای تازه با هوش مصنوعی',
		summary: `عنوان و توضیحات متای «${ title }»`,
		command: `بروزرسانی: ${ safeDecode( url ) }\nفقط عنوان سئو و توضیحات متای تازه بده (رتبه خوب، کلیک کم) و در «خروجی پایدارپرس» در بخش seo_updates قرار بده.`,
		prompt: id ? { task: 'meta', args: { post_id: id } } : null,
		hint: 'با ورود، متن فعلی و جدید کنار هم نمایش داده می‌شوند و پس از تأیید در افزونه سئو ذخیره می‌شوند.',
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
		prompt: { task: 'article', args: { query } },
		hint: IMPORT_HINT,
	};
}

/**
 * Topic ideas for the whole site (Topics page).
 */
export function topicsCommand() {
	return {
		label: 'پیشنهاد موضوع با هوش مصنوعی',
		summary: 'پیشنهاد ۱۰ موضوع تازه برای سایت',
		command: 'موضوع جدید',
		prompt: { task: 'topics', args: { count: 10 } },
		hint: 'موضوع‌ها پس از ورود در همین صفحه می‌آیند؛ تکراری‌ها خودکار علامت می‌خورند.',
	};
}

/**
 * Full article for a suggested topic.
 *
 * @param {Object} topic Topic row (id, title).
 */
export function topicArticleCommand( topic ) {
	return {
		label: 'نوشتن مقاله',
		summary: `نوشتن مقاله «${ topic.title }»`,
		command: `مقاله: ${ topic.title }`,
		prompt: { task: 'article', args: { topic_id: topic.id } },
		hint: IMPORT_HINT,
	};
}

/**
 * Decodes a URL for display without throwing on malformed input.
 *
 * @param {string} url URL.
 */
function safeDecode( url ) {
	try {
		return decodeURI( url );
	} catch ( e ) {
		return url;
	}
}
