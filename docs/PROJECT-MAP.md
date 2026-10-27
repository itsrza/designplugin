# نقشه پروژه سرویا (Serviya) — مرجع کامل کد

> این سند خلاصه‌ی خوانده‌شدن **کل** مخزن است: افزونه‌ی `serviya/` (PHP + CSS + JS + مستندات) و فایل‌های ریشه.
> برای هر تغییر در پروژه‌ی جدید («میران») اول این نقشه را بخوانید تا بدانید کدام فایل مسئول کدام بخش است.

---

## ۱) فهرست موجودیت‌های مخزن

| مسیر | حجم | نقش |
|---|---|---|
| `README.md` | ۲ خط | معرفی کوتاه مخزن (افزونه طراحی فروشگاه/وبلاگ/محصول/مقاله) |
| `github-recovery-codes.txt` | ۱۵ کد | ⚠️ کدهای بازیابی ۲FA گیت‌هاب — جزو کد پروژه نیست (پایین‌تر هشدار را ببینید) |
| `serviya (7).zip` | ۱۳۴KB | آرشیو آماده‌ی نصب همان افزونه (نسخه ۳.۲.۳) |
| `serviya/` | ~۵۹۲KB | **کد واقعی افزونه** (از زیپ استخراج شد و حالا فایل‌به‌فایل در گیت است) |
| `tools/build.mjs` | — | مینیفای کردن دارایی‌ها (CSS/JS) به `*.min.*` |
| `tools/package.mjs` | — | ساخت زیپ قابل نصب در `release/` |
| `docs/PROJECT-MAP.md` | — | همین سند |
| `docs/MIRAN-PLAN.md` | — | برنامه و چک‌لیست تغییرات پروژه‌ی جدید |

### درخت افزونه

```text
serviya/
├── serviya.php                 # بوت‌استرپ: هدر افزونه، ثابت‌ها، تشخیص افزونه‌های قدیمی
├── README-fa.md                # راهنمای فارسی کاربر + تاریخچه نسخه‌ها (۳.۰.۱ → ۳.۲.۳)
├── modules/
│   ├── shop.php                # شورت‌کد [serviya_shop]   (۱۰۸۲ خط)
│   ├── blog.php                # شورت‌کد [serviya_blog]   ( ۶۰۴ خط)
│   ├── product.php             # شورت‌کد [serviya_product]( ۸۳۷ خط)
│   └── post.php                # شورت‌کد [serviya_post]   ( ۷۰۱ خط)
└── assets/
    ├── css/serviya-{shop,blog,product,post}.css  + .min.css
    └── js/serviya-{shop,blog,product,post}.js    + .min.js
```

---

## ۲) جریان بوت

`serviya/serviya.php`:

1. گارد `ABSPATH` (دسترسی مستقیم فایل = خروج).
2. ثابت‌ها: `SERVIYA_VERSION = '3.2.3'`، `SERVIYA_PATH`، `SERVIYA_URL`.
3. تابع `serviya_suite_is_legacy_plugin_active($basename)`: چک `active_plugins` + حالت multisite (`active_sitewide_plugins`).
4. بارگذاری ماژول‌ها:
   - `modules/shop.php` اگر `serviya-shop/serviya-shop.php` فعال نباشد (وگرنه استندبای + هشدار).
   - `modules/blog.php` اگر `serviya-blog/serviya-blog.php` فعال نباشد.
   - `modules/product.php` و `modules/post.php` همیشه (نسخه‌ی مستقل قدیمی ندارند).
5. در صورت وجود افزونه‌ی قدیمی: `admin_notices` با پیام فارسی و شرط `current_user_can('activate_plugins')`.

هیچ ماژول تنظیمات/تیبل اختصاصی ندارد؛ کل افزونه **بدون state** است و همه‌چیز از محتوا و ووکامرس خوانده می‌شود.

### قراردادهای مشترک (خیلی مهم برای تغییرات جدید)

| موضوع | قاعده |
|---|---|
| پیشوند توابع PHP | `serviya_suite_<module>_*` — عمداً متفاوت از افزونه‌های قدیمی تا تداخل نکند |
| پیشوند هوک‌ها/فیلترها | `serviya_<module>_*` (`serviya_shop_per_page`, `serviya_shop_needs_assets`, `serviya_blog_per_page`, `serviya_blog_needs_assets`, `serviya_product_needs_assets`, `serviya_post_needs_assets`) |
| پیشوند کلاس‌های CSS | فروشگاه `ss-`، وبلاگ `b-`، محصول `sp-`، مقاله `svp-` |
| ریشه‌ی اسکوپ CSS | `.serviya-shop-widget` / `.serviya-blog` / `.serviya-product` / `.serviya-post` |
| دسته‌بندی دارایی‌ها | فایل جدا برای هر ماژول؛ فقط در صفحه‌ی مربوطه لود می‌شود (`needs_assets` + هوک `wp_enqueue_scripts` با اولویت ۱۰۰) |
| انتخاب مینیفای | `SCRIPT_DEBUG` روشن ⇒ فایل خوانا، خاموش ⇒ `.min` (با `file_exists` گارد می‌شود) |
| نسخه‌ی دارایی | `filemtime()` فایل ⇒ cache-busting خودکار |
| اعداد فارسی | توابع `*_fa_number` (با جداکننده هزارگان) و `*_fa_digits` (بدون جداکننده: تاریخ، تلفن) |
| امنیت خروجی | `esc_html` / `esc_attr` / `esc_url`؛ JSON با `wp_json_encode` و `JSON_HEX_TAG \| JSON_HEX_AMP`؛ SVG ثابت با کامنت `phpcs:ignore` |
| جهت | همه‌ی ریشه‌ها `dir="rtl"` |

---

## ۳) ماژول فروشگاه — `modules/shop.php`

**شورت‌کد:** `[serviya_shop per_page="12" title="فروشگاه"]` (per_page بین ۱ تا ۴۸).

**مسیر رندر:** PHP سمت سرور صفحه‌ی اول (SEO + کارکرد بدون JS) → JS با Store API صفحه‌های بعدی را می‌آورد.

- **پارامترهای URL** (مشترک بین PHP، فرم بدون‌JS و JS):
  `ss_q` (جستجو)، `ss_cat` (اسلاگ یا لیست)، `ss_sort` (`date|popularity|price|price-desc`)، `ss_stock`، `ss_sale`، `ss_page`، و `ss_{pa_taxonomy}` یا `ss_attr[pa_x][]`.
- `serviya_suite_shop_parse_state()` → `serviya_suite_shop_build_url()` (لینک‌سازی سازگار با فیلترها).
- `serviya_suite_shop_query_products()`: `WP_Query` روی `product`، حذف `exclude-from-catalog`، فیلتر `product_cat` و اتریبیوت‌ها، جستجو، مرتب‌سازی، `wc_get_product_visibility_term_ids`-محور (بدون API بیرونی).
- **دسته‌بندی:** همه‌ی `product_cat`ها با `hide_empty=false` گرفته می‌شوند و درخت با والدهایی که نوادگان‌شان محصول دارند حفظ می‌شود؛ نوار بالای صفحه drill-down دارد (انتخاب دسته ⇒ زیر‌دسته‌ها + «بازگشت» + «همهٔ X»).
- **کارت محصول** `serviya_suite_shop_render_card()`: همان مارک‌آپی که JS می‌سازد (کلاس‌ها باید بین PHP و JS یکسان بمانند)، لیبل ابعاد، لیبل تخفیف، کارت قیمت با `<del>` خط‌خورده.
- **مارک‌آپ رندر:** بردکرامب + H1 داخل آن، شمارش کالا، نوار دسته‌ها، فرم `ss-filters-form` با سایدبار (دریور موبایل `ss-drawer-*`)، تاگل‌ها، آکاردئون دسته/ویژگی‌ها، گرید، دکمه «مشاهده محصولات بیشتر»، و صفحه‌بندی واقعی داخل `<noscript>` برای خزش.
- **SEO:** فیلتر `wp_robots` — هر کلیدی که با `ss_` شروع شود ⇒ `noindex,follow`؛ URL تمیز و لندینگ تک‌دسته indexable می‌ماند. اسکیمای `ItemList` با `ld+json` چاپ می‌شود.
- **JS (`serviya-shop.js`، ~۱۳۰۰ خط):** فیلتر/جستجو/مرتب‌سازی/load-more با `fetch`، همگام‌سازی URL با `pushState/replaceState`، `AbortController` برای لغو درخواست قدیمی، کش ۶۰ ثانیه‌ای در حافظه + prefetch صفحه‌ی بعد، افزودن به سبد با `wc/store/v1/cart/add-item`، مدیریت دریور موبایل، تشخیص `data-ssr-state` برای وقتی کش صفحه HTML ناهمگام می‌دهد.

---

## ۴) ماژول وبلاگ — `modules/blog.php`

**شورت‌کد:** `[serviya_blog per_page="9" phone="…" products_url="…"]`.

- **اندپوینت اختصاصی سبک:** `GET /wp-json/serviya/v1/posts` با پارامترهای `page`, `per_page`, `cat`, `q`؛ هدرهای `X-WP-Total` و `X-WP-TotalPages`. `permission_callback = __return_true` (داده‌ی عمومی، فقط DTO سبک برمی‌گردد).
  - نکته‌ی تاریخی: `sanitize_title` مستقیم به‌عنوان `sanitize_callback` باگ می‌داد (آرگومان دومش fallback است و آبجکت request را برمی‌گرداند) ⇒ تابع پوششی `serviya_suite_blog_sanitize_slug`.
- **پارامترهای URL:** `sb_cat`, `sb_q`, `sb_page`.
- **رندر:** مقاله‌ی ویژه (فقط صفحه ۱ بدون فیلتر) + گرید کارت‌ها + چیپ دسته‌ها + CTA تماس/محصولات + `ItemList`.
- **SEO:** هر کلید `sb_*` ⇒ `noindex,follow`.
- **JS (`serviya-blog.js`):** فیلتر/جستجو/load-more، کش ۶۰ ثانیه‌ای، prefetch، لغو درخواست، تلاش مجدد درجا؛ بدون JS هم فرم GET کار می‌کند.

---

## ۵) ماژول محصول — `modules/product.php`

**شورت‌کد:** `[serviya_product id phone whatsapp telegram sticky consult]`

فلسفه: **هیچ فرم/داده‌ای بازسازی نمی‌شود**؛ خروجی بومی ووکامرس/وودمارت با `do_action` گرفته و در پوسته‌ی سرویا چیده می‌شود:

1. `woocommerce_before_single_product`
2. `woocommerce_before_single_product_summary` (گالری) — اگر عکسی نبود: `woocommerce_show_product_images` سپس گالری fallback خود افزونه؛ اگر نشان حراج نبود: `sp-sale-badge`؛ لیبل ابعاد `sp-dim-badge` با `data-sp-dimensions`.
3. `woocommerce_single_product_summary` (عنوان، امتیاز، قیمت، توضیح کوتاه، فرم خرید) + پرکردن جاهای خالی با `woocommerce_template_single_*`.
4. `woocommerce_after_single_product_summary` (تب‌ها، upsell، related) + fallback اگر تب/محصولات مرتبط چاپ نشده باشد.
5. `woocommerce_after_single_product`.

نکات پیاده‌سازی:

- `$GLOBALS['post']`/`$GLOBALS['product']` سِت و در `finally` بازگردانی می‌شوند؛ رندر بازگشتی با `static $rendering` قفل می‌شود.
- **کارت قیمت** (`serviya_suite_product_price_card`): با regex اولین `p|span|div.price` را به `.sp-price-card` + `.sp-price-stack` + `.sp-price-label` («قیمت فروش»/«قیمت فروش ویژه») تبدیل می‌کند؛ بازه‌ی قیمت (چند `woocommerce-Price-amount` بدون `<del>/<ins>`) دست‌نخورده می‌ماند.
- **SKU** زیر `<h1>` به شکل چیپ؛ متای دسته/برچسب عمداً چاپ نمی‌شود.
- **شمارش معکوس** فقط اگر قالب/وودمارت چاپ نکرده باشد؛ ترتیب RTL: ثانیه ← دقیقه ← ساعت ← روز؛ تاریخ پایان متغیرها هم بررسی می‌شود.
- **برچسب موجودی** از فرمت نمایش موجودی ووکامرس (`get_option('woocommerce_stock_format')`) پیروی می‌کند و با `data-sp-stock-pill` برای به‌روزرسانی توسط JS نشانه‌گذاری می‌شود.
- **باکس مشاوره** (`phone/whatsapp/telegram` + `consult="0"` برای مخفی‌کردن) و **نوار چسبان** (`sticky="0"`؛ در JS اگر نوار چسبان وودمارت وجود داشته باشد حذف می‌شود).
- **JS (`serviya-product.js`):** به‌روزرسانی قیمت/موجودی/SKU/ابعاد با تغییر متغیر، شمارش معکوس fallback، همگام‌سازی قیمت نوار چسبان با اعداد فارسی، fallback بندانگشتی گالری.
- **دارایی‌ها:** CSS با دیپندنسی `woocommerce-general`/`woocommerce-layout`؛ اسکریپت‌های `wc-single-product`, `flexslider`, `photoswipe`, `zoom` صدا زده می‌شوند.

---

## ۶) ماژول مقاله — `modules/post.php`

**شورت‌کد:** `[serviya_post id sidebar toc share author_box nav related recent blog_url]`

- فقط روی `post` کار می‌کند؛ برگه/محصول ⇒ خروجی خالی. رمزدار ⇒ `get_the_password_form`.
- محتوا با `apply_filters('the_content', ...)` و چندصفحه‌ای با `wp_link_pages`؛ دیدگاه‌ها با `comments_template()` بومی.
- هدر مقاله: چیپ دسته‌ها، H1، لید (excerpt)، متا (نویسنده + آواتار، تاریخ انتشار/به‌روزرسانی، زمان مطالعه ۲۲۰wpm، تعداد دیدگاه).
- سایدبار چپ: فهرست خودکار سرفصل‌ها (اگر حداقل ۲ سرفصل)، نوشته‌های اخیر، دسته‌ها با شمارش (لینک به `[serviya_blog]` با `?sb_cat=`)، ابر برچسب.
- پایین مقاله: برچسب‌ها، اشتراک‌گذاری + کپی لینک، باکس نویسنده، قبلی/بعدی، «مقالات دیگر» (هم‌دسته‌ها و در صورت کمبود تازه‌ترین‌ها).
- **JS (`serviya-post.js`):** نوار پیشرفت مطالعه، TOC خودکار + هایلایت بخش فعال، دکمه کپی لینک.
- نکته: `is_singular('post')` به‌جای `is_single()` (چون `is_single()` روی محصولات هم true بود و CSS/JS مقاله در صفحه محصول لود می‌شد).

---

## ۷) سیستم طراحی (توکن‌ها)

| توکن | مقدار | کاربرد |
|---|---|---|
| لایم برند | `#B7E600` | دکمه، تاکید، گرید هیرو |
| لایم لیبل ابعاد | `#B8E620` | لیبل ابعاد در فروشگاه و محصول (متن مشکی) |
| سرمه‌ای | `#18233D` | هدر، تیتر، هیرو وبلاگ |
| جوهر تیره | `#292D32` / `#2B3B66` | متن |
| پس‌زمینه نرم | `#F6F8F3` | سطح بخش‌ها (درسرویا) |
| خاکستری متن | `#8892A3` ، `#667085` | متن کم‌اهمیت |
| خط/بوردر | `#E5E7EB` ، `#E2E8F0` ، `#E6E9E0` | جداکننده |
| صورتی حراج | `#FF047E` | لیبل تخفیف |
| سرخ قیمت نهایی | `#EC3680` | قیمت نهایی کارت محصول |
| قرمز خطا | `#EF4044` / `#EF4056` | خطا |
| سبز موجودی | `#15803D` ، نارنجی کمبود `#B45309` | پیل موجودی |
| شعاع/شادو | `24px` ، `0 18px 50px rgba(24,35,61,.08)` | کارت‌ها |
| کانتینر | ۱۳۲۰px (فروشگاه/محصول) ، ۱۱۸۰px (وبلاگ) | عرض محتوا |

فونت: وزیرمتن (`vazirmatn`) با fallback `Tahoma` — از خود سایت/قالب تأمین می‌شود، افزونه فونت را خودش لود نمی‌کند.

---

## ۸) بیلد و انتشار

```bash
npm install          # نصب ابزارها (clean-css + terser + php-parser)
npm run lint:php     # بررسی سینتکس PHP بدون نیاز به باینری php (php-parser، گرامر PHP 8)
npm run build        # ساخت مجدد همه‌ی *.min.css / *.min.js
npm run build:check  # فقط بررسی به‌روز بودن فایل‌های min (کد خروج ۱ اگر عقب باشند)
npm run check        # lint:php + build:check  (قبل از هر کامیت)
npm run package      # بیلد + ساخت release/serviya-<version>.zip
```

وضعیت فعلی: هر ۵ فایل PHP بدون خطا پارس می‌شوند و همه‌ی minها با منبع هم‌گام‌اند (نتیجه‌ی `npm run check` سبز است).

- فایل‌های خوانا منبع حقیقت‌اند؛ هر تغییر در CSS/JS باید با `npm run build` مینیفای شود.
- نسخه‌ی زیپ از هدر `Version:` در `serviya/serviya.php` خوانده می‌شود؛ برای نسخه‌ی جدید همان‌جا و در ثابت `SERVIYA_VERSION` و `package.json` را هم‌زمان به‌روز کنید.
- `release/` در `.gitignore` است.

### تست دستی سریع (چون کامپایل PHP در این محیط نیست)

- بررسی سینتکس: `php -l serviya/serviya.php serviya/modules/*.php` روی سیستم محلی یا هاست.
- چک‌لیست کامل رفتاری در انتهای `serviya/README-fa.md` است (فروشگاه، وبلاگ، محصول ساده/متغیر، مقاله، یک H1 در هر صفحه، پاک‌کردن کش).

---

## ۹) بدهی فنی و نقاط حساس (قبل از تغییر بدانید)

1. **سبک کد ناهمگون:** `shop.php` و `blog.php` با ۴ فاصله؛ `product.php` و `post.php` با tab و PSR-12-ish. قبل از ریفکتور، یکسان‌سازی را جدا و در یک کامیت انجام دهید.
2. **شماره تلفن پیش‌فرض hardcode:** `09169066373` / `989169066373` هم در `product.php` هم در `blog.php`. برای پروژه‌ی جدید باید یک منبع واحد (ثابت یا فیلتر) شود.
3. **i18n واقعی وجود ندارد:** هدر افزونه `Text Domain: serviya` دارد ولی هیچ رشته‌ای با `__()`/`esc_html__()` پیچیده نشده و `load_plugin_textdomain` صدا زده نمی‌شود؛ همه‌ی متن‌ها فارسی سخت‌کدند.
4. **تکیه بر regex روی HTML ووکامرس** (`sp-price-card`، تزریق SKU بعد از `<h1>`، تزریق شمارش معکوس بعد از `p.price`): سریع ولی شکننده؛ با تغییر مارک‌آپ قالب/ووکامرس می‌شکند. هر جا ممکن است از فیلتر/هوک استفاده کنید.
5. **تشخیص نیاز به دارایی‌ها با `strpos` در `post_content` و `_elementor_data`:** برای شورت‌کدهای داخل ویجت‌های داینامیک/داخل تمپلیت‌های ذخیره‌شده در فایل قالب کار نمی‌کند؛ فیلتر `serviya_*_needs_assets` راه فرار است.
6. **نسخه تکراری:** `SERVIYA_VERSION` در `serviya.php` + هدر + `package.json`؛ باید هم‌زمان به‌روز شوند.
7. **بدون تست خودکار:** هیچ PHPUnit/Playwright ای در مخزن نیست؛ اضافه‌کردن حداقل lint (PHPCS/PHPStan) پیشنهاد می‌شود.
8. **`serviya (7).zip` تکراری** نسبت به پوشه‌ی `serviya/`: یا حذف شود یا به‌عنوان آرشیو نسخه‌ی منتشرشده در `release/` نگه داشته شود.

> ⚠️ **هشدار امنیتی:** فایل `github-recovery-codes.txt` در ریشه‌ی مخزن، ۱۵ کد بازیابی ۲FA حساب گیت‌هاب را لو می‌دهد. این کدها را در GitHub باطل/دوباره‌تولید کنید، فایل را از مخزن حذف کنید و اگر مخزن عمومی است، تاریخچه (`git filter-repo`) را هم پاک کنید. این کار آگاهانه انجام نشد چون خارج از محدوده‌ی «آماده‌سازی» و نیازمند تصمیم شماست.

---

## ۱۰) کدام فایل برای کدام تغییر؟

| اگر می‌خواهید… | فایل |
|---|---|
| اسم/نسخه/اسکلت افزونه | `serviya/serviya.php` |
| ظاهر و لایه‌بندی فروشگاه | `assets/css/serviya-shop.css` |
| رفتار فیلتر/سبد فروشگاه | `assets/js/serviya-shop.js` |
| مارک‌آپ و کوئری فروشگاه | `modules/shop.php` |
| ظاهر/رفتار وبلاگ | `assets/css|js/serviya-blog.*` + `modules/blog.php` |
| صفحه‌ی محصول (چیدمان، بج‌ها، قیمت) | `modules/product.php` + `assets/css/serviya-product.css` |
| متن/رنگ دکمه‌های محصول | CSS + `modules/product.php` |
| مقاله و سایدبار آن | `modules/post.php` + `assets/css/serviya-post.css` |
| متن‌های فارسی عمومی/تلفن | همان ماژول (و در آینده: فایل تنظیمات واحد) |
