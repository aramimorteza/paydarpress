/**
 * "Create with AI" actions. Each one carries:
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
	if ( [ 'IMG_MISSING_ALT', 'IMG_GENERIC_ALT' ].includes( finding.code ) && object.id ) {
		return altCommand( object.title, object.id );
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

/**
 * Review of the category structure (suggestions only).
 */
export function structureCommand() {
	return {
		label: 'پیشنهاد ساختار دسته‌بندی با هوش مصنوعی',
		summary: 'بررسی ساختار دسته‌بندی سایت',
		prompt: { task: 'structure', args: {} },
		hint: 'پیشنهادها فقط به فهرست «پیشنهادهای ساختار» در همین صفحه اضافه می‌شوند؛ هیچ دسته‌ای خودکار تغییر نمی‌کند و تصمیم با شماست.',
	};
}

/**
 * Alt texts for images without a useful one, each written from its place in the article
 * (nearest heading, text before and after, caption).
 *
 * @param {string} title Post title (empty for a batch over several posts).
 * @param {number} id    Post ID (0: the posts with the most missing alts).
 */
export function altCommand( title = '', id = 0 ) {
	return {
		label: id ? 'متن جایگزین تصاویر با هوش مصنوعی' : 'نوشتن متن جایگزین با هوش مصنوعی',
		summary: id ? `متن جایگزین تصاویر «${ title }»` : 'متن جایگزین تصاویر بدون alt',
		prompt: { task: 'alt', args: id ? { post_id: id } : {} },
		hint: `برای هر تصویر، سرتیتر بخش، متن قبل و بعد و زیرنویس آن داخل پرامپت است تا متن جایگزین مخصوص همان جا نوشته شود. ${ id ? '' : 'هر بار حداکثر ۲۵ تصویر از نوشته‌هایی که بیشترین کمبود را دارند. ' }با ورود، متن تصویر در نوشته و اگر کتابخانه رسانه متنی نداشت، همان‌جا هم ذخیره می‌شود؛ متن جایگزینِ درست موجود دست نمی‌خورد.`,
	};
}

/**
 * Categories and tags for the next chunk of existing posts or products (manual mode; the
 * automatic mode runs all chunks as one batch).
 *
 * @param {string} kind  post|product.
 * @param {string} scope weak|all.
 * @param {string} label Kind label.
 */
export function classifyCommand( kind, scope, label ) {
	return {
		label: `طبقه‌بندی ۲۵ ${ label } بعدی (دستی)`,
		summary: `طبقه‌بندی ${ label } موجود`,
		prompt: { task: 'classify', args: { kind, scope } },
		hint: 'cleanup' === scope ? 'هر پرامپت ۲۵ مطلب بعدی را دارد. پیشنهاد حذف برچسب‌های نامرتبط با دلیل می‌آید و فقط برای مواردی که تیک بزنید اعمال می‌شود؛ بازگرداندن یکجا ممکن است.' : 'هر پرامپت ۲۵ مطلب بعدی را دارد (عنوان، سرتیترها و ۳۰۰ کلمه اول) همراه دسته‌ها و برچسب‌های فعال. پس از ورود، دسته بعدی آماده می‌شود. فقط افزودن انجام می‌شود؛ هیچ دسته یا برچسبی حذف نمی‌شود.',
	};
}

/**
 * Cluster map (pillar + subtopics) around a main keyword, or completion of an existing cluster.
 *
 * @param {string} name Keyword or cluster name.
 * @param {Object} args { keyword, category_id } or { cluster_id }.
 */
export function clusterCommand( name, args ) {
	return {
		label: args.cluster_id ? 'تکمیل نقشه خوشه با هوش مصنوعی' : 'ساخت نقشه خوشه با هوش مصنوعی',
		summary: `نقشه خوشه «${ name }»`,
		prompt: { task: 'cluster', args },
		hint: 'هوش مصنوعی مقاله ستون و ۸ تا ۱۵ زیرموضوع پیشنهاد می‌دهد و مقاله‌های موجود را به زیرموضوع‌ها نگاشت می‌کند. با ورود، خوشه ساخته یا تکمیل می‌شود، مقاله‌های موجود عضو می‌شوند و برای جاهای خالی موضوع (با دسته، برچسب و خوشه) ثبت می‌شود.',
	};
}

/**
 * Topics for a kept tag that has too few posts.
 *
 * @param {string} name Tag name.
 * @param {number} id   Tag ID.
 */
export function tagTopicsCommand( name, id ) {
	return {
		label: 'موضوع برای این برچسب با هوش مصنوعی',
		summary: `موضوع برای برچسب «${ name }»`,
		prompt: { task: 'topics', args: { tag_id: id, count: 3 } },
		hint: 'موضوع‌ها از سهمیه ماهانه سیاست محتوا هستند و با همین برچسب (و خوشه مرتبط، اگر باشد) به «موضوعات» می‌روند.',
	};
}

/**
 * English slugs for the next addresses of the address program.
 */
export function slugsCommand() {
	return {
		label: 'نامک انگلیسی با هوش مصنوعی',
		summary: 'نامک انگلیسی برای آدرس‌های فارسی',
		prompt: { task: 'slugs', args: {} },
		hint: 'برای ۴۰ آدرس بعدی نامک کوتاه انگلیسی پیشنهاد می‌شود. پس از ورود فقط در «برنامه بهبود» ذخیره می‌شود؛ آدرس‌ها هفته به هفته و با تأیید شما عوض می‌شوند.',
	};
}
