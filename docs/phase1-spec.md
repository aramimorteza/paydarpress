# مشخصات فنی فاز 1 – PaydarPress

**نسخه:** 1.0  
**مبنا:** سند معماری نسخه 1.6 (`docs/architecture.md`)  
**هدف فاز:** موتور شناخت سایت، ممیزی سلامت محتوا، پروفایل سایت، خروجی JSON برای ContentMaster و رابط کاربری کامل — **بدون هیچ درخواست به API هوش مصنوعی**.

این سند مرجع پیاده‌سازی است. هر جا با سند معماری تفاوت دارد، تفاوت و دلیل آن در بخش 14 ثبت شده است.

---

## 1. محدوده

### داخل فاز 1

- PaydarWP Hub: منوی مشترک «پایدار وردپرس»، فونت وزیرمتن، Design Tokenها
- هسته افزونه: نصب، جداول، Migration، Capabilityها، تنظیمات
- صف پس‌زمینه با Action Scheduler
- Site Scanner: نوشته‌ها، برگه‌ها، محصولات (در صورت وجود WooCommerce)، دسته‌بندی‌ها، برچسب‌ها، منوها، رسانه شاخص
- Content Extractor: Gutenberg / Classic و Elementor
- SEO Adapter: Yoast، Rank Math، AIOSEO (فقط خواندن)
- گراف لینک داخلی
- Persian Text Normalizer
- Health Audit Engine (بخش‌های 4.6.1 تا 4.6.4، 4.8.4 بدون تبدیل فایل، 4.11 ممیزی نویسندگان، `NO_DIRECT_ANSWER` تقریبی)
- Crawler Access Check (فقط robots.txt و noindex؛ بررسی دسترسی از خارج در فاز بعد)
- Site Profile Builder و ویزارد راه‌اندازی
- خروجی JSON با پروفایل `contentmaster-lite`
- رابط کاربری: پیشخوان و ویجت‌ها، Onboarding، صفحات فاز 1، سیستم راهنما، حالت ساده / حرفه‌ای

### خارج از فاز 1

هر چیزی که به هوش مصنوعی، شبکه‌های اجتماعی، تولید تصویر، لایسنس یا Search Console API نیاز دارد. ورود از ContentMaster و CSV سرچ کنسول در فاز 1.5 انجام می‌شود.

---

## 2. تصمیمات فنی

| موضوع | تصمیم |
|---|---|
| PHP | حداقل 8.0 (بدون ویژگی‌های 8.1 به بعد مانند enum و readonly property) |
| WordPress | حداقل 6.5 (Action Scheduler 3.9 به آن نیاز دارد؛ نسخه 6.4 دیگر به‌روزرسانی امنیتی فعال ندارد) |
| Namespace | `PaydarWP\PaydarPress` (Hub: `PaydarWP\Hub`) |
| Autoload | PSR-4 از طریق Composer؛ پوشه `vendor/` در بسته نهایی قرار می‌گیرد |
| صف | Action Scheduler 3.9.3 درون بسته در `lib/action-scheduler` (بدون نیاز به WooCommerce؛ از مخزن رسمی GitHub) |
| REST | `paydarpress/v1` |
| رابط کاربری | React با `@wordpress/scripts`، `@wordpress/components`، `@wordpress/api-fetch` |
| ذخیره پروفایل | Option با `autoload = no` (دلیل در بخش 14) |
| کدنویسی | WordPress Coding Standards، `declare(strict_types=1)` در فایل‌های کلاس |
| تست | PHPUnit برای منطق مستقل (Normalizer، قواعد ممیزی، Extractor)؛ تست یکپارچه روی وردپرس واقعی |

---

## 3. ساختار پوشه‌ها

```text
plugin/paydarpress/
├── paydarpress.php            Bootstrap: هدر افزونه، ثابت‌ها، بررسی نیازمندی‌ها
├── uninstall.php              حذف داده‌ها فقط در صورت فعال بودن گزینه
├── composer.json
├── package.json
├── hub/                       PaydarWP Hub (با نسخه مستقل)
│   ├── hub.php                ثبت نسخه و انتخاب جدیدترین
│   ├── src/Hub.php
│   └── assets/fonts/          Vazirmatn WOFF2
├── src/
│   ├── Plugin.php             نقطه ورود و ثبت سرویس‌ها
│   ├── Core/                  Container ساده، Settings، Capabilities
│   ├── Database/              Installer، Migrations، Schema
│   ├── Queue/                 Wrapper بر Action Scheduler
│   ├── Text/                  PersianNormalizer
│   ├── Scanner/               ScanManager، ContentScanner، TaxonomyScanner، MenuScanner
│   ├── Extractors/            ContentExtractor (Interface)، BlockExtractor، ElementorExtractor
│   ├── Seo/                   SeoAdapter (Interface)، Yoast، RankMath، Aioseo، NullAdapter
│   ├── Links/                 LinkGraph، UrlResolver
│   ├── Audit/                 AuditEngine، Rule (Interface)، Rules/*، RefreshScore
│   ├── Profile/               ProfileRepository، ProfileInference
│   ├── Crawl/                 RobotsChecker
│   ├── Export/                InventoryExporter
│   ├── Rest/                  Controllerها
│   └── Admin/                 AdminPage، Assets، DashboardWidget، Help
├── assets/src/                کد React
├── assets/build/              خروجی build (در بسته نهایی)
├── help/fa_IR/*.json          محتوای راهنماها
├── languages/
└── tests/
```

---

## 4. دیتابیس

پیشوند همه جداول: `{$wpdb->prefix}paydarpress_`. نسخه Schema در Option `paydarpress_db_version` ثبت می‌شود و Migrationها با مقایسه نسخه اجرا می‌شوند.

### 4.1 `paydarpress_inventory`

داده مشتق‌شده هر محتوا (یک ردیف برای هر نوشته، برگه یا محصول منتشرشده یا پیش‌نویس).

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `object_id` | BIGINT UNSIGNED | شناسه نوشته وردپرس |
| `object_type` | VARCHAR(40) | `post`، `page`، `product`، CPT |
| `status` | VARCHAR(20) | وضعیت وردپرس |
| `title_norm` | VARCHAR(255) | عنوان نرمال‌شده برای مقایسه |
| `url` | VARCHAR(500) | |
| `primary_term_id` | BIGINT UNSIGNED NULL | دسته اصلی |
| `focus_keyword` | VARCHAR(255) NULL | |
| `focus_keyword_norm` | VARCHAR(255) NULL | |
| `seo_title` | VARCHAR(255) NULL | |
| `meta_description` | TEXT NULL | |
| `is_noindex` | TINYINT(1) | |
| `is_cornerstone` | TINYINT(1) | |
| `canonical` | VARCHAR(500) NULL | |
| `word_count` | INT UNSIGNED | |
| `h2_count` | SMALLINT UNSIGNED | |
| `outline` | LONGTEXT NULL | JSON: آرایه H2 و H3 |
| `excerpt` | TEXT NULL | حداکثر 300 کاراکتر |
| `years_mentioned` | VARCHAR(255) NULL | JSON |
| `has_direct_answer` | TINYINT(1) | تشخیص تقریبی (بخش 7) |
| `has_featured_image` | TINYINT(1) | |
| `featured_alt_ok` | TINYINT(1) | |
| `inline_images` | SMALLINT UNSIGNED | |
| `inline_images_no_alt` | SMALLINT UNSIGNED | |
| `internal_out` | SMALLINT UNSIGNED | |
| `internal_in` | SMALLINT UNSIGNED | فقط از محتوای منتشرشده |
| `menu_in` | SMALLINT UNSIGNED | |
| `external_out` | SMALLINT UNSIGNED | |
| `author_id` | BIGINT UNSIGNED | |
| `refresh_score` | TINYINT UNSIGNED | 0 تا 100 |
| `content_hash` | CHAR(32) | برای اسکن افزایشی |
| `published_at` | DATETIME NULL | GMT |
| `modified_at` | DATETIME NULL | GMT |
| `scanned_at` | DATETIME | GMT |

Indexها: `UNIQUE(object_id)`، `(object_type, status)`، `(focus_keyword_norm)`، `(refresh_score)`، `(internal_in)`.

### 4.2 `paydarpress_links`

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `source_id` | BIGINT UNSIGNED | شناسه محتوای مبدأ (0 برای منو) |
| `source_type` | VARCHAR(20) | `content`، `menu` |
| `target_id` | BIGINT UNSIGNED NULL | شناسه مقصد پس از Resolve |
| `target_url` | VARCHAR(500) | |
| `anchor` | VARCHAR(255) | |
| `link_type` | VARCHAR(20) | `internal`، `external` |
| `target_state` | VARCHAR(20) | `ok`، `missing`، `redirect`، `unpublished`، `unknown` |

Indexها: `(source_id)`، `(target_id)`، `(link_type, target_state)`.

- `link_type` علاوه بر `internal` و `external` مقدار `asset` (فایل‌ها و تصاویر آپلودشده) را هم می‌پذیرد؛ این لینک‌ها در شمارش لینک داخلی حساب نمی‌شوند.
- لینک به نامک قدیمی یک نوشته (`_wp_old_slug`) که وردپرس خودش ریدایرکت می‌کند، با `target_state = redirect` و `target_id` همان نوشته ثبت می‌شود و در `internal_in` شمرده می‌شود.
- اگر اسکن در صفحه پایدارپرس باز باشد، هر درخواست وضعیت (`GET /scan/status?tick=1`) چند کار صف را همان‌جا اجرا می‌کند تا اسکن روی هاست‌هایی که WP-Cron یا درخواست Loopback کار نمی‌کند هم تمام شود.

### 4.3 `paydarpress_audit_findings`

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `code` | VARCHAR(50) | کد قاعده |
| `severity` | VARCHAR(10) | `high`، `medium`، `low` |
| `object_kind` | VARCHAR(20) | `content`، `term`، `author`، `site` |
| `object_id` | BIGINT UNSIGNED | |
| `data` | LONGTEXT NULL | JSON: جزئیات (مثلاً شناسه مقاله رقیب) |
| `state` | VARCHAR(20) | `open`، `reviewed`، `ignored`، `resolved` |
| `first_seen` | DATETIME | |
| `last_seen` | DATETIME | |

Index: `UNIQUE(code, object_kind, object_id)`، `(state, severity)`.

یافته‌ای که در اجرای بعدی تکرار نشود به `resolved` تغییر می‌کند. یافته `ignored` تا زمانی که شرایط آن ادامه دارد `ignored` می‌ماند.

### 4.4 `paydarpress_scans`

| ستون | نوع |
|---|---|
| `id` | BIGINT UNSIGNED PK AI |
| `type` | VARCHAR(20) — `full`، `incremental` |
| `status` | VARCHAR(20) — `running`، `completed`، `failed`، `cancelled` |
| `total` | INT UNSIGNED |
| `processed` | INT UNSIGNED |
| `started_at` / `finished_at` | DATETIME |
| `error` | TEXT NULL |

### 4.5 `paydarpress_activity`

| ستون | نوع |
|---|---|
| `id` | BIGINT UNSIGNED PK AI |
| `event` | VARCHAR(60) |
| `user_id` | BIGINT UNSIGNED |
| `object_kind` / `object_id` | VARCHAR(20) / BIGINT |
| `message` | TEXT |
| `created_at` | DATETIME |

سیاست نگهداری: حذف ردیف‌های قدیمی‌تر از `activity_retention_days` (پیش‌فرض 90) با یک کار روزانه.

### 4.6 Optionها

| Option | autoload | محتوا |
|---|---|---|
| `paydarpress_db_version` | yes | نسخه Schema |
| `paydarpress_settings` | no | تنظیمات عمومی و آستانه‌ها |
| `paydarpress_profile` | no | پروفایل سایت (بخش 9) |
| `paydarpress_stats` | no | آمار محاسبه‌شده پس از هر اسکن |
| `paydarpress_onboarding` | no | وضعیت مراحل شروع کار |

---

## 5. صف و اسکن

### 5.1 گروه و Actionها

گروه Action Scheduler: `paydarpress`

| Action | کار |
|---|---|
| `paydarpress/scan/start` | ایجاد ردیف در `scans`، شمارش محتوا، زمان‌بندی Batch اول |
| `paydarpress/scan/batch` | پردازش `batch_size` محتوا از `offset`؛ زمان‌بندی Batch بعدی |
| `paydarpress/scan/taxonomies` | محاسبه آمار دسته‌ها و برچسب‌ها |
| `paydarpress/scan/menus` | ثبت لینک‌های منو |
| `paydarpress/scan/resolve_links` | Resolve مقصد لینک‌ها و محاسبه `internal_in` |
| `paydarpress/audit/run` | اجرای قواعد ممیزی و محاسبه `refresh_score` |
| `paydarpress/stats/build` | ساخت `paydarpress_stats` |
| `paydarpress/item/rescan` | اسکن افزایشی یک محتوا (ثبت از Hook ذخیره) |
| `paydarpress/maintenance/daily` | پاک‌سازی داده‌های قدیمی |

### 5.2 تنظیمات عملکرد

| تنظیم | پیش‌فرض | حالت هاست اشتراکی |
|---|---|---|
| `batch_size` | 50 | 15 |
| حداکثر زمان هر Batch | 20 ثانیه (توقف زودتر و ادامه در Batch بعد) | 10 ثانیه |
| Batch هم‌زمان | 1 | 1 |

### 5.3 اسکن افزایشی

- `save_post`، `deleted_post`، `trashed_post`، `transition_post_status`: فقط زمان‌بندی `paydarpress/item/rescan` با تأخیر 60 ثانیه (Unique بر اساس شناسه).
- `created_term`، `edited_term`، `delete_term`، `wp_update_nav_menu`: علامت‌گذاری «نیاز به اسکن Taxonomy یا منو».
- پس از اسکن افزایشی، لینک‌های همان محتوا Resolve و ممیزی فقط برای همان محتوا و محتوای مرتبط (مقاصد لینک‌ها) اجرا می‌شود.
- اگر `content_hash` تغییر نکرده باشد، پردازش متن انجام نمی‌شود.

---

## 6. استخراج محتوا

### 6.1 انتخاب Extractor

1. اگر `_elementor_edit_mode = builder` و `_elementor_data` معتبر باشد: `ElementorExtractor`
2. در غیر این صورت: `BlockExtractor` روی `post_content` (پس از `do_blocks` برای بلوک‌های داینامیک فقط در صورت نیاز؛ پیش‌فرض: بدون رندر، با `parse_blocks` و HTML خام)

### 6.2 خروجی Extractor

```php
[
  'text'      => string,   // متن ساده
  'headings'  => [ ['level' => 2, 'text' => '...'], ... ],
  'links'     => [ ['url' => '...', 'anchor' => '...'], ... ],
  'images'    => [ ['src' => '...', 'alt' => '...', 'attachment_id' => int|null], ... ],
]
```

### 6.3 Elementor

`_elementor_data` به‌صورت JSON بازگشتی پیمایش می‌شود. ویجت‌های پشتیبانی‌شده در فاز 1: `heading`، `text-editor`، `image`، `button`، `icon-list`، `accordion`، `toggle`. برای سایر ویجت‌ها، مقادیر متنی شناخته‌شده (`title`، `editor`، `description`، `text`) و لینک‌ها (`link.url`) استخراج می‌شوند.

### 6.4 شمارش کلمات

پس از نرمال‌سازی، با شکستن روی فاصله و حذف توکن‌های خالی. نیم‌فاصله جداکننده کلمه نیست.

---

## 7. قواعد ممیزی

آستانه‌ها در `paydarpress_settings['audit']` با مقادیر پیش‌فرض زیر:

| کلید | پیش‌فرض |
|---|---|
| `thin_words` | 300 |
| `weak_category_min` | 3 |
| `tag_overuse_max` | 8 |
| `weak_inbound_min` | 3 |
| `inline_images_min_words` | 800 |
| `stale_months` | 18 |

### 7.1 فهرست قواعد فاز 1

| کد | شدت | شرط |
|---|---|---|
| `ORPHAN_POST` | high | `object_type = post`، منتشرشده، indexable، `internal_in = 0` |
| `ORPHAN_PAGE` | high | `page`، منتشرشده، indexable، `internal_in = 0` و `menu_in = 0`، خارج از فهرست استثنا |
| `ORPHAN_PRODUCT` | medium | `product`، `internal_in = 0` |
| `MENU_ONLY` | low | `internal_in = 0` و `menu_in > 0` |
| `WEAK_INBOUND` | medium | صفحه کلیدی پروفایل یا Cornerstone با `internal_in < weak_inbound_min` |
| `NO_INTERNAL_LINKS` | medium | منتشرشده، `internal_out = 0` |
| `BROKEN_INTERNAL_LINK` | high | لینک داخلی با `target_state` برابر `missing` یا `unpublished` |
| `REDIRECTED_INTERNAL_LINK` | low | لینک داخلی با `target_state = redirect` |
| `NO_FOCUS_KEYWORD` | medium | بدون کلمه کلیدی (فقط اگر افزونه سئو فعال است) |
| `NO_META_DESCRIPTION` | medium | بدون توضیحات متا (فقط اگر افزونه سئو فعال است) |
| `DUPLICATE_FOCUS_KEYWORD` | high | `focus_keyword_norm` یکسان برای 2 محتوای منتشرشده یا بیشتر |
| `OUTDATED_YEAR` | high | سالی در عنوان یا متن که کوچک‌تر از سال جاری است (شمسی یا میلادی)، با شرط بخش 7.2 |
| `THIN_CONTENT` | medium | `word_count < thin_words` (فقط نوشته‌ها) |
| `NO_H2` | low | `h2_count = 0` و `word_count >= 300` |
| `NO_DIRECT_ANSWER` | low | بخش 7.3 |
| `IMG_NO_FEATURED` | medium | نوشته بدون تصویر شاخص |
| `IMG_MISSING_ALT` | medium | تصویر شاخص یا تصویر داخل متن بدون Alt |
| `IMG_GENERIC_ALT` | low | Alt مطابق الگوهای بی‌معنا (بخش 7.4) |
| `IMG_NO_INLINE` | low | `word_count >= inline_images_min_words` و `inline_images = 0` |
| `EMPTY_CATEGORY` | medium | دسته بدون محتوای منتشرشده |
| `WEAK_CATEGORY` | medium | `0 < count < weak_category_min` |
| `EMPTY_CATEGORY_DESC` | medium | توضیحات خالی یا کمتر از 50 کاراکتر |
| `SINGLE_CATEGORY_OVERLOAD` | medium | بیش از 60٪ نوشته‌ها در یک دسته، با حداقل 10 نوشته |
| `SIMILAR_CATEGORIES` | medium | نام نرمال‌شده یکسان یا یکی شامل دیگری |
| `EMPTY_TAG` | low | برچسب بدون محتوا |
| `THIN_TAG` | low | برچسب با دقیقاً یک محتوا |
| `NEAR_DUPLICATE_TAGS` | low | نام نرمال‌شده یکسان |
| `TAG_EQUALS_CATEGORY` | low | نام نرمال‌شده برچسب برابر یک دسته |
| `TAG_OVERUSE` | low | تعداد برچسب یک نوشته بیش از `tag_overuse_max` |
| `INDEXED_THIN_ARCHIVE` | medium | دسته یا برچسب `WEAK`/`THIN` که توسط افزونه سئو noindex نشده |
| `AUTHOR_NO_BIO` | low | نویسنده دارای مطلب منتشرشده بدون توضیحات کاربری |
| `ADMIN_AS_AUTHOR` | low | نام کاربری نویسنده `admin` یا نام نمایشی برابر نام کاربری لاتین |
| `CRAWLER_BLOCKED` | high | robots.txt یکی از خزنده‌های بخش 10 را برای کل سایت مسدود کرده است |
| `SITE_NOINDEX` | high | گزینه «جلوگیری از ایندکس» وردپرس فعال است |

### 7.2 تشخیص سال قدیمی

- استخراج اعداد چهاررقمی پس از نرمال‌سازی ارقام.
- سال شمسی: 1390 تا سال جاری شمسی منهای یک. سال میلادی: 2010 تا سال جاری میلادی منهای یک.
- فقط وقتی گزارش می‌شود که سال در **عنوان** آمده باشد، یا در متن همراه یکی از واژه‌های زمانی (`سال`، `امسال`، `نرخ`، `مبلغ`، `قیمت`، `هزینه`، `تعرفه`، `میزان`، `in`، `for`) در فاصله حداکثر 3 کلمه بیاید. هدف: کاهش خطای مثبت برای سال‌های تاریخی.
- سالی که در دو کلمه قبل از آن یکی از واژه‌های `مصوب`، `مصوبه`، `قانون`، `ماده` یا `اصلاحیه` آمده باشد، تاریخ قانون است و گزارش نمی‌شود (نمونه: «قانون حمایت خانواده مصوب 1391»). این قاعده پس از آزمایش روی مقالات واقعی وکیل کرج اضافه شد.
- عددی که پس از `شماره`، `کد`، `پلاک`، `مدل` و مشابه آن بیاید، یا پیش از یک واحد (`متر`، `تومان`، `ریال`، `نفر`، `عدد`…) قرار گیرد، سال نیست و گزارش نمی‌شود.

### 7.3 تشخیص تقریبی پاسخ مستقیم

یک پاراگراف از دو پاراگراف اول متن که بین 25 تا 90 کلمه باشد و پیش از اولین H2 قرار گیرد، به‌عنوان پاسخ مستقیم در نظر گرفته می‌شود. این قاعده تقریبی است و شدت آن `low` است.

### 7.4 Alt بی‌معنا

پس از نرمال‌سازی: خالی، برابر نام فایل (با یا بدون پسوند)، مطابق `^(img|image|dsc|photo|screenshot)[\s_-]*\d*$`، یا یکی از `تصویر`، `عکس`، `image`، `photo`، `picture`.

### 7.5 امتیاز نیاز به بروزرسانی

مجموع وزن‌ها با سقف 100:

| عامل | وزن |
|---|---|
| `OUTDATED_YEAR` | 30 |
| ماه‌های گذشته از آخرین ویرایش بیش از `stale_months` | 20 |
| `THIN_CONTENT` | 15 |
| `DUPLICATE_FOCUS_KEYWORD` | 15 |
| `NO_DIRECT_ANSWER` | 5 |
| `NO_INTERNAL_LINKS` | 5 |
| `BROKEN_INTERNAL_LINK` | 5 |
| `NO_H2` | 5 |
| تصویر ناقص (`IMG_NO_FEATURED` یا `IMG_MISSING_ALT`) | 5 |

اقدام پیشنهادی: `DUPLICATE_FOCUS_KEYWORD` → «ادغام یا تفکیک»؛ امتیاز 50 به بالا → «بازنویسی»؛ 20 تا 49 → «بروزرسانی سبک»؛ کمتر از 20 → بدون اقدام. داده Search Console از فاز 1.5 به این امتیاز اضافه می‌شود.

---

## 8. Persian Text Normalizer

```php
PersianNormalizer::normalize( string $text ): string
```

مراحل به ترتیب: Decode موجودیت‌های HTML → `ي ى` به `ی`، `ك` به `ک`، `ة` به `ه`، `ؤ` به `و`، `إ أ` به `ا` → ارقام فارسی و عربی به لاتین → حذف اعراب (U+064B تا U+065F، U+0670) و کشیده (U+0640) → یکسان‌سازی نیم‌فاصله‌ها (U+200C، U+200B، U+00AC) به U+200C → حذف فاصله کنار نیم‌فاصله → فاصله‌های چندگانه به یک فاصله → `mb_strtolower` → trim.

تابع کمکی `normalizeForKey()` نیم‌فاصله را به فاصله تبدیل می‌کند تا «می‌شود» و «میشود» و «می شود» یکسان شوند.

---

## 9. پروفایل سایت

ساختار Option `paydarpress_profile` (هر فیلد: `value`، `source`، `confirmed`):

| فیلد | منبع پیش‌فرض |
|---|---|
| `site_name`، `domain`، `language`، `seo_plugin`، `page_builder`، `woocommerce` | detected |
| `industry_module` | inferred (بخش 9.1) |
| `geographic_scope` | inferred از تنظیمات Local SEO افزونه سئو در صورت وجود، در غیر این صورت خالی |
| `fact_sensitivity` | inferred از `industry_module` |
| `key_pages` | inferred (بخش 9.1) |
| `cta_options` | inferred (بخش 9.1) |
| `categories_scope` | manual |
| `primary_audience`، `tone_of_voice`، `forbidden_topics`، `industry_rules`، `primary_content_goal` | manual |
| `brand_glossary` | manual |

### 9.1 قواعد استنباط

- **صفحات کلیدی:** برگه‌های منتشرشده که در منوی اصلی (location اول قالب) هستند، به‌علاوه برگه‌هایی که اسلاگ یا عنوان نرمال‌شده آن‌ها شامل یکی از این واژه‌هاست: `contact، about، services، service، pricing، price، consultation، booking، تماس، درباره، خدمات، تعرفه، قیمت، مشاوره، رزرو، نوبت`. برگه‌های سیستمی WooCommerce و حریم خصوصی مستثنا هستند.
- **CTA:** از میان صفحات کلیدی، برگه‌هایی که شامل `contact، consultation، booking، تماس، مشاوره، رزرو، نوبت` هستند، یا شورت‌کد / بلوک Contact Form 7، WPForms، Gravity Forms یا فرم Elementor دارند.
- **ماژول صنعتی:** WooCommerce فعال ← `product-manufacturing`؛ واژه‌های `وکیل، وکالت، حقوقی، دادگاه، طلاق` در نام سایت، توضیحات یا دسته‌ها ← `legal`؛ `آموزش زبان، انگلیسی، آیلتس` ← `language-learning`؛ در غیر این صورت `custom`.
- **حساسیت:** `legal` ← `YMYL`، سایر موارد ← `normal`.

### 9.2 ویزارد

پنج مرحله مطابق بخش 4.5 سند معماری. هر مرحله قابل رد شدن است. `missing_fields` در زمان خروجی از فیلدهای خالی یا تأییدنشده ساخته می‌شود.

---

## 10. Crawler Access Check

- خواندن robots.txt از طریق `wp_remote_get( home_url('/robots.txt') )` با Timeout 5 ثانیه؛ در صورت خطا، استفاده از خروجی `do_robots` داخلی.
- Parse قواعد `User-agent` و `Disallow` و بررسی `Disallow: /` برای: `Googlebot`، `Bingbot`، `GPTBot`، `OAI-SearchBot`، `ChatGPT-User`، `ClaudeBot`، `PerplexityBot`، `Google-Extended`، و `*`.
- نتیجه برای هر خزنده: `allowed`، `blocked`، `partial`، همراه توضیح اثر.
- بررسی `blog_public`.
- اجرا: هنگام اسکن کامل و با دکمه «بررسی مجدد».

---

## 11. REST API

همه مسیرها در `paydarpress/v1`، با `permission_callback` بر اساس Capability و Nonce استاندارد REST.

| متد و مسیر | Capability | کار |
|---|---|---|
| `GET /dashboard` | `paydarpress_view` | آمار و داده ویجت‌ها |
| `POST /scan` | `paydarpress_manage` | شروع اسکن کامل |
| `GET /scan/status` | `paydarpress_view` | وضعیت آخرین اسکن |
| `GET /inventory` | `paydarpress_view` | فهرست محتوا با فیلتر، جستجو، مرتب‌سازی و صفحه‌بندی |
| `GET /inventory/{id}` | `paydarpress_view` | جزئیات یک محتوا و یافته‌هایش |
| `GET /findings` | `paydarpress_view` | یافته‌ها با فیلتر کد، شدت، وضعیت، گروه |
| `POST /findings/{id}/state` | `paydarpress_manage` | تغییر وضعیت یافته |
| `GET /taxonomies` | `paydarpress_view` | دسته‌ها و برچسب‌ها با آمار و یافته‌ها |
| `GET /orphans` | `paydarpress_view` | محتوای یتیم با مقالات پیشنهادی برای لینک‌دهی (بر اساس دسته و برچسب مشترک) |
| `GET /refresh-candidates` | `paydarpress_view` | فهرست پیشنهاد بازنویسی |
| `GET /crawlers` | `paydarpress_view` | نتیجه Crawler Access Check |
| `GET /profile` / `POST /profile` | `paydarpress_view` / `paydarpress_manage` | خواندن و ذخیره پروفایل |
| `POST /profile/infer` | `paydarpress_manage` | اجرای مجدد استنباط |
| `GET /settings` / `POST /settings` | `paydarpress_manage` | تنظیمات |
| `POST /export` | `paydarpress_export` | ساخت فایل JSON و بازگرداندن آدرس دانلود موقت |
| `POST /help/dismiss` | `read` | ثبت بسته شدن یک راهنما برای کاربر جاری |

### Capabilityها

| Capability | Administrator | Editor |
|---|---|---|
| `paydarpress_view` | ✓ | ✓ |
| `paydarpress_manage` | ✓ | |
| `paydarpress_export` | ✓ | |

---

## 12. خروجی JSON (Schema 1.0، پروفایل `contentmaster-lite`)

ساختار مطابق `docs/site-inventory.example.json` با این قوانین:

- فایل در `wp-content/uploads/paydarpress/exports/` با نام تصادفی ساخته می‌شود؛ پوشه با `index.php` و `.htaccess` (Deny) محافظت می‌شود و دانلود فقط از طریق Endpoint دارای Nonce انجام می‌شود. فایل پس از 24 ساعت حذف می‌شود.
- `wp_json_encode` با `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT`.
- محتوای پیش‌نویس فقط در صورت انتخاب کاربر.
- بخش `links` فقط خلاصه (`links_summary`) در هر محتوا؛ گراف کامل در پروفایل `full` (فاز بعد).
- فیلد `flags` هر محتوا شامل کدهای یافته‌های باز.
- `warnings` شامل یافته‌های سطح سایت و `PROFILE_INCOMPLETE`.
- بدون ایمیل، نام کاربری، اطلاعات کاربران، نظرات یا کلیدها.
- برای سایت‌های بیش از 500 محتوا، ساخت فایل در صف انجام می‌شود.

---

## 13. رابط کاربری فاز 1

### 13.1 صفحات

| مسیر داخلی | صفحه |
|---|---|
| `#/` | پیشخوان |
| `#/welcome` | خوش‌آمد و اولین اسکن |
| `#/wizard` | ویزارد پروفایل |
| `#/profile` | پروفایل سایت |
| `#/inventory` | فهرست محتوا |
| `#/taxonomies` | دسته‌بندی‌ها و برچسب‌ها |
| `#/audit` | ممیزی (همه یافته‌ها، با تب گروه‌ها) |
| `#/orphans` | محتوای یتیم و لینک‌های داخلی |
| `#/images` | سلامت تصاویر |
| `#/refresh` | پیشنهاد بازنویسی |
| `#/visibility` | دیده‌شدن در گوگل و هوش مصنوعی (فاز 1: خزنده‌ها و noindex) |
| `#/export` | خروجی JSON |
| `#/activity` | گزارش فعالیت |
| `#/settings` | تنظیمات (عمومی، ممیزی، عملکرد و صف، پیشرفته، راهنما) |

منوهای فازهای بعد در ناوبری نمایش داده نمی‌شوند.

### 13.2 ویجت‌های پیشخوان فاز 1

امتیاز سلامت، تکمیل پروفایل، شروع کار، مهم‌ترین یافته‌ها، محتوای یتیم، پیشنهاد بازنویسی، آخرین اسکن. ویجت‌های وابسته به هوش مصنوعی در فاز 1 نمایش داده نمی‌شوند.

**امتیاز سلامت:** `round(100 × e^(−a/8))` که `a` میانگین وزن یافته‌های باز به ازای هر محتوای منتشرشده است (وزن high=5، medium=2، low=0.5). نمونه: a=0 → ۱۰۰، a=2.5 → ۷۳، a=5 → ۵۴، a=12.5 → ۲۱. فرمول خطی اولیه برای سایت آزمایشی وکیل کرج عدد صفر می‌داد که پیشرفت پس از رفع مشکلات را نشان نمی‌داد؛ به همین دلیل با این منحنی جایگزین شد.

### 13.3 الزامات

مطابق بخش 12 سند معماری: RTL، وزیرمتن داخلی فقط در صفحات افزونه، ارقام فارسی در نمایش، تاریخ شمسی (با `Intl.DateTimeFormat('fa-IR-u-ca-persian')`)، باکس راهنمای هر صفحه از `help/fa_IR/*.json`، حالت ساده / حرفه‌ای در User Meta `paydarpress_ui_mode`.

---

## 14. تفاوت با سند معماری

| مورد | سند معماری | فاز 1 | دلیل |
|---|---|---|---|
| ذخیره پروفایل | جدول `paydarpress_profile` | Option بدون autoload | پروفایل یک رکورد است؛ جدول مزیتی ندارد |
| `paydarpress_jobs` | جدول اختصاصی | جداول Action Scheduler + `paydarpress_scans` | جلوگیری از دوباره‌کاری |
| `paydarpress_links` | گراف کامل | همان، با ستون `target_state` | پشتیبانی از لینک شکسته و ریدایرکت |
| Media Inventory | جدول مجزا | ستون‌های تصویر در `inventory` | کافی برای ممیزی فاز 1 |
| تشخیص ریدایرکت | از افزونه‌های ریدایرکت | Rank Math Redirections و Redirection در صورت وجود؛ در غیر این صورت `unknown` | |
| قالب خروجی JSON | یک قالب | `contentmaster-lite` (کامل) و `contentmaster-compact` (فشرده)؛ فشرده به‌طور پیش‌فرض برای بیش از ۳۰۰ نوشته/محصول؛ خروجی بدون pretty-print | خروجی کامل سایت ۱٬۵۱۷ نوشته‌ای ۲ مگابایت بود (بیش از ظرفیت معمول چت)؛ فشرده ۴۸۴ کیلوبایت |
| ساعات خلوت | اجرای کارهای سنگین در ساعات خلوت | فقط پاک‌سازی روزانه به ساعت شروع بازه منتقل می‌شود؛ اسکن دستی و اسکن تدریجی پس از ذخیره همیشه فوری | اسکن تدریجی سبک است و تأخیرش داده را کهنه می‌کند |
| ابزار پشتیبانی | — | صفحه «پیشرفته»: وضعیت صف، اجرای دستی کارهای معوق، اطلاعات سیستم بدون داده شخصی | تشخیص مشکل WP-Cron در هاست‌های اشتراکی |

---

## 15. مراحل پیاده‌سازی (Milestones)

| مرحله | خروجی | معیار پذیرش |
|---|---|---|
| M1 | اسکلت، Hub، منو، جداول، Capabilityها، صفحه خالی با ناوبری | فعال‌سازی بدون خطا؛ منوی «پایدار وردپرس ← پایدارپرس»؛ جداول ساخته شوند؛ غیرفعال‌سازی داده را حذف نکند |
| M2 | Normalizer، Extractorها، SEO Adapterها، Scanner، گراف لینک | اسکن کامل یک سایت نمونه؛ ردیف‌های `inventory` و `links` صحیح |
| M3 | Audit Engine، Refresh Score، Crawler Check، آمار | یافته‌های صحیح روی داده نمونه وکیل کرج (7 مقاله بدون لینک داخلی و `OUTDATED_YEAR` مقاله نفقه) |
| M4 | پروفایل، استنباط، ویزارد، خروجی JSON | خروجی منطبق بر Schema و قابل خواندن توسط ContentMaster |
| M5 | رابط کاربری کامل، ویجت‌ها، راهنماها، Onboarding | همه صفحات بخش 13 |
| M6 | عملکرد، تست هاست اشتراکی، بسته نهایی | معیارهای 26 تا 28 سند معماری؛ zip قابل نصب |
