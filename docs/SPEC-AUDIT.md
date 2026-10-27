# ممیزی کد موجود در برابر هایپرپرامپت «کلون سرویا»

> مرجع: متن هایپرپرامپت (بخش‌های ۱ تا ۱۰). مبنای مقایسه: کد `serviya/` نسخه ۳.۲.۳ در همین مخزن.
> هر ادعای «✓ منطبق» با ارجاع `فایل:خط` آمده است. هر «⚠» یعنی کد با متن سند فرق دارد یا بدون سایت زنده قابل اثبات نیست.

## خلاصه اجرایی

| موضوع | نتیجه |
|---|---|
| معماری افزونه (بخش ۲) | ✓ کامل منطبق — ساختار، نام‌ها، پیشوندها، جداسازی دارایی‌ها، `is_singular('post')`, sanitize امن REST، برش کاراکتری، escape |
| سیستم طراحی (بخش ۳) | ✓ همه‌ی توکن‌های رنگ سند در کد وجود دارند (به‌جز یک مورد که فقط در JS/blog به‌صورت literal است) |
| بردکرامب (بخش ۴) | ✓ کد دقیقاً راه‌حل «تله ۵px» را دارد (`margin:0` روی `li`، `nowrap`، ellipsis روی آیتم آخر) |
| فروشگاه (بخش ۵) | ✓ منطبق، با ۲ انحراف جزئی (عرض کانتینر، جهت لیبل درصد) |
| وبلاگ (بخش ۶) | ✓ منطبق (اندپوینت سبک، `sb_cat/sb_q/sb_page`، ۲۲۰wpm، بدون JS) |
| محصول (بخش ۷) | ✓ منطبق در همه‌ی موارد کلیدی؛ ۱ تابع مرده (nav قبلی/بعدی) که باید حذف شود |
| مقاله (بخش ۸) | ✓ منطبق؛ باکس مشاوره سایدبار حذف شده، `phone` حذف شده، سایدبار چسبان نیست |
| بیلد/تحویل (بخش ۲ انتها) | ✓ حالا بازتولیدپذیر است: `npm run build` + `npm run check` + `npm run package` |

**نتیجه:** کد فعلی همان نسخه‌ای است که سند توصیف می‌کند؛ کار باقی‌مانده «ری‌برند + داده» است، نه بازنویسی.

---

## ۱) ممیزی بند‌به‌بند سند

### بند ۲ — پشته فنی و معماری

| الزام سند | وضعیت | شاهد |
|---|---|---|
| ساختار پوشه‌ی افزونه یکپارچه | ✓ | `serviya/serviya.php` + `modules/{shop,blog,product,post}.php` + `assets/{css,js}/serviya-*.{css,js}` |
| ثابت‌های `SERVIYA_PATH/URL/VERSION` | ✓ | `serviya.php:17-19` |
| بدون فرم خرید دوم، تکیه بر هوک‌های ووکامرس | ✓ | `modules/product.php:560-837` (`do_action` روی ۵ هوک استاندارد + fallback) |
| بارگذاری CSS/JS فقط در صفحه‌ی خودش | ✓ | `shop.php:18,115` · `blog.php:18,84` · `product.php:30,91` · `post.php:30,79` (+ فیلترهای `*_needs_assets`) |
| `is_singular('post')` به‌جای `is_single()` | ✓ | `post.php:32` |
| sanitize اسکالر-امن برای REST | ✓ | `blog.php:118-120` + توضیح دلیل در `blog.php:110-117` |
| برش کاراکتری امن (نه `substr` بایتی) | ✓ | `post.php:113-127` · `blog.php:222-234` |
| escape خروجی/سنیتایز ورودی | ✓ | نمونه: `shop.php:829` (`esc_html` روی H1)، `wp_json_encode(...JSON_HEX_TAG|JSON_HEX_AMP)` در `shop.php:1000+` و `blog.php:590+`، کامنت‌های `phpcs:ignore` فقط برای SVG/اعداد |
| افزونه هیچ آپشنی در دیتابیس ذخیره نمی‌کند | ✓ | هیچ `add_option/update_option/register_setting` در کل افزونه نیست (فقط `get_option('active_plugins')` و `woocommerce_stock_format`) |
| گارد `popstate` برای تغییر صرف فرگمنت | ✓ | `assets/js/serviya-blog.js:524` و معادل آن در `serviya-shop.js` (مقایسه‌ی `pathname+search` بدون hash) |
| بازتولید `.min` از سورس + پاس‌کردن نسخه/چنج‌لاگ | ⚠️ قبلاً ابزار نداشت | حالا `tools/build.mjs` (این ریپو) + `npm run check` |
| تست lint رندر PHP | ⚠️ فقط سینتکس | `tools/lint-php.mjs` سینتکس را چک می‌کند؛ رندر واقعی نیاز به وردپرس زنده دارد |

### بند ۳ — سیستم طراحی

هر ۱۳ مقدار سند در کد موجود است:

| نقش | مقدار سند | محل در کد |
|---|---|---|
| Primary | `#B7E600` | `--srv-lime` (`serviya-shop.css:13`)، `--sp-lime` (`serviya-product.css:7`)، `--svp-lime` (`serviya-post.css:8`)، `serviya-blog.css:8` |
| Primary Hover | `#A3CC00` | `--sp-lime-hover` / `--svp-lime-hover` / `.ss-*:hover` |
| Lime Soft | `#F4FBE3` | `--sp-lime-soft`, `--svp-lime-soft` |
| Navy | `#18233D` | `--srv-navy`, `--sp-navy`, `--svp-navy`, `serviya-blog.css` |
| Ink | `#2B3B66` | `--sp-ink`, `--svp-ink` |
| خاکستری متن | `#667085` / `#8892A3` | `--sp-muted`/`--sp-gray`، `--svp-*`، `.ss-*` |
| حاشیه | `#E2E8F0` | `--sp-border`, `--svp-border` |
| نرم | `#F6F8F3` | `--sp-soft`, `--srv-soft`, `--svp-soft` |
| قرمز | `#EF4056` | `--sp-red`, `--svp-red` |
| لیبل تخفیف | `#FF047E` | `--sp-sale` (`serviya-product.css:18`) + literal در `serviya-shop.css:571` |
| قیمت نهایی | `#EC3680` | `--sp-price-final` (`serviya-product.css:19`) + literal برای نوار چسبان وودمارت (`serviya-product.css:~1866`) |
| لیبل ابعاد | `#B8E620` | `--sp-dim` (`serviya-product.css:21`) + literal `#B8E620` در `serviya-shop.css:589` |
| سایه بج | `0 8px 20px rgba(24,35,61,.14)` | `.sp-sale-badge`، `.sp-dim-badge`، `.ss-badge-*` |
| سایه کارت | `0 18px 50px rgba(24,35,61,.08)` | `--sp-shadow`, `--svp-shadow` |
| شعاع | ۳۰px کارت اصلی، ۱۸–۲۴px کارت | `.sp-main`, `.svp-article` = 30px؛ `--sp-radius/--svp-radius` = 24px |
| کانتینر | `min(1320px, 100% - 32px)` | محصول/مقاله ✓ (`serviya-product.css:45`، `serviya-post.css:37`) — **فروشگاه ⚠** و **وبلاگ ⚠** (پایین) |

### بند ۴ — بردکرامب

| الزام | وضعیت | شاهد |
|---|---|---|
| یک خط، `nowrap` | ✓ | `ol{flex-wrap:nowrap}` در هر سه ماژول (`serviya-shop.css:1159`، `serviya-product.css:90`، `serviya-post.css:88`) |
| آیتم‌ها `flex:0 0 auto`، آخر `flex:0 1 auto; min-width:0; overflow:hidden` | ✓ | همان بلوک‌ها + `li:last-child` |
| ellipsis عنوان صفحه فعلی | ✓ | `li:last-child{overflow:hidden}` + `text-overflow` روی `.ss-current/.sp-current/.svp-current` |
| تله `margin-bottom:10px` قالب، `margin:0` روی `li` | ✓ (رفع شده) | `li{...margin:0}` در سه فایل؛ سند همین را می‌خواهد |
| رنگ/وزن صفحه فعلی | ✓ | سرمه‌ای ۷۰۰/۸۰۰ در `.ss-current` (800) و `.sp-current/.svp-current` (700) |
| ساختار `<nav aria-label><ol><li>` + جداکننده `/` | ✓ | `shop.php:815-830` (`<nav class="ss-breadcrumbs" aria-label="مسیر صفحه">`)، `product.php:381-420`، `post.php:214-242` |

> عدد «اختلاف ≤ ۰٫۶px» و «۰px اختلاف بالا» فقط روی سایت زنده با `getBoundingClientRect` قابل اثبات است — بخش «تست‌های الزامی روی استیجینگ» در پایین.

### بند ۵ — فروشگاه

| الزام | وضعیت | شاهد |
|---|---|---|
| `per_page` پیش‌فرض ۱۲، `title` | ✓ | `shop.php:553-560` (کلمپ ۱..۴۸) |
| رندر SSR + URL قابل اشتراک/خزش | ✓ | `shop.php:547+`، `build_url()` در `shop.php:236` |
| همه دسته‌ها در شروع + drill-down + بازگشت | ✓ | `shop.php:600-670` (فهرست تخت سلسله‌مراتبی، `strip_level_parent`، «همهٔ X») |
| فقط «مشاهده محصولات بیشتر»، هر کلیک ۱۲ | ✓ | `serviya-shop.js:20` (`PER_PAGE=12`) + `ss-load-more` |
| صفحه‌بندی عددی فقط در `<noscript>` | ✓ | `shop.php:1060-1080` |
| کارکرد بدون JS | ✓ | فرم GET `ss-filters-form` + `paginate_links` |
| H1 داخل آخرین آیتم بردکرامب | ✓ | `shop.php:829` |
| کارت: تصویر `contain` مربعی | ✓ | `serviya-shop.css:560` (`object-fit:contain`) |
| بج تخفیف بالا-چپ فیزیکی `#FF047E`، ابعاد بالا-راست `#B8E620`، کپسول، ۳۰/۲۵px، سایه | ✓ | `serviya-shop.css:564-602` و موبایل `935-960` |
| ناموجود خاکستری | ✓ | `.ss-card.is-out-of-stock` (کلاس `ss-card-unavailable`) |
| قیمت فارسی + ہزارگان | ✓ | `serviya_suite_shop_fa_number` + `toFaNumber` در JS |
| سبد از Store API + آپدیت شمارنده هدر | ✓ | `serviya-shop.js:872-1000` (`cart/add-item`, nonce، رویدادهای `wc_fragments_refresh`/`added_to_cart`) |
| ابعاد از `pa_dimensions` یا ابعاد بومی | ✓ | `shop.php:394-425` + fallback attribute در JS |
| آیکن آکاردئون `\f129` / `\f115` | ✓ | `serviya-shop.css` (`.ss-acc-icon::before`) |

⚠️ **انحراف ۱ — عرض کانتینر فروشگاه:** `max-width:1320px` + `padding:0 15px` ⇒ محتوای مؤثر ۱۲۹۰px، در حالی که سند `min(1320px, 100%-32px)` می‌خواهد (`serviya-shop.css:38-44`). اصلاح یک‌خطی، ولی چون روی همه‌ی اندازه‌های گرید اثر دارد، فقط با تأیید شما و همراه اندازه‌گیری.

⚠️ **انحراف ۲ — جهت لیبل درصد تخفیف:** JS `۹۴٪-` می‌سازد و `direction:ltr` روی بج دارد (`serviya-shop.css:578`؛ `serviya-shop.js` در `badge-discount`). سند `-94%` می‌خواهد. تأثیر بصری: جای علامت درصد/منفی.

### بند ۶ — وبلاگ

| الزام | وضعیت | شاهد |
|---|---|---|
| `per_page=9`, `phone`, `products_url` | ✓ | `blog.php:417-433` (شماره پیش‌فرض hardcode: `blog.php:426`) |
| SSR مقاله ویژه + ۸ کارت + دسته‌ها | ✓ | `blog.php:443-452` |
| اندپوینت `GET /wp-json/serviya/v1/posts` سبک | ✓ | `blog.php:294-321` + `DTO` در `blog.php:256` |
| `sb_cat/sb_q/sb_page` در URL | ✓ | `blog.php:122-170`، `serviya-blog.js` (`URLSearchParams`) |
| کش، prefetch، لغو درخواست، retry | ✓ | `serviya-blog.js` (`RESULTS_TTL=60000`) |
| بدون JS کار کند | ✓ | فرم GET + `<noscript>` صفحه‌بندی |
| زمان مطالعه ۲۲۰wpm + اعداد فارسی | ✓ | `blog.php:238-241`، `post.php:129-133` |
| لینک دسته مقاله → وبلاگ با `?sb_cat=` | ✓ | `post.php:181-186` |

⚠️ **انحراف ۳ — عرض کانتینر وبلاگ:** `min(1180px, calc(100% - clamp(24px,6vw,56px)))` (`serviya-blog.css:14`) در حالی که سند برای «همه سکشن‌ها» ۱۳۲۰px می‌گوید. اگر عمدی است (طراحی باریک‌تر وبلاگ) بماند؛ اگر نه، اصلاح شود.

### بند ۷ — محصول

| الزام | وضعیت | شاهد |
|---|---|---|
| نصب با یک ویجت Shortcode (دستورالعمل در README) | ✓ | `serviya/README-fa.md` بخش محصول |
| گزینه‌های `id, phone, whatsapp, telegram, sticky, consult` | ✓ | `product.php:568-580` |
| ناوبری قبلی/بعدی چاپ نمی‌شود | ✓ (تابع مرده) | `serviya_suite_product_adjacent_nav()` در `product.php:422` **تعریف شده ولی هیچ‌جا صدا زده نمی‌شود** ⇒ حذف توصیه می‌شود |
| کارت سفید ۳۰px، گرید `minmax(0,...)`، `min-width:0` | ✓ | `serviya-product.css:241-270` |
| ≤۱۰۸۰px: تک‌ستون + `overflow-x:clip` + گالری `position:relative` | ✓ | `serviya-product.css:1890-1918` (با کامنت دقیقاً همان دلیل سند) |
| بندانگشتی زیر تصویر اصلی | ✓ | `@media (min-width:1025px)` بلاک `serviya-product.css:320+` |
| بج ابعاد بالا-راست `#B8E620` / تخفیف بالا-چپ `#FF047E`، ۳۴/۳۰px، سایه یکسان | ✓ | `serviya-product.css:418-468` + موبایل `1958-1976` |
| لنگر هر دو به لبه داخلی گالری (`calc(var(--sp-gallery-pad) + 8px)` و onsale `top:8px`) | ✓ | همان بلوک‌ها |
| چیپ موجودی (یکپارچه، تابع فرمت ووکامرس) + برند + دسته | ✓ | `product.php:206-243`، `product.php:735-760` |
| خط موجودی تکراری پایین توضیح پنهان | ✓ | `serviya-product.css` (`.stock` داخل summary مخفی) |
| یک H1 + چیپ SKU زیر آن | ✓ | `product.php:700-730` |
| حذف متای دسته/برچسب و `.wd-visits-count` | ✓ | `serviya-product.css:858` (مخفی)، متا چاپ نمی‌شود (`product.php:717-720` کامنت) |
| کارت قیمت: del بالا / قیمت نهایی `#EC3680` پایین / برچسب چپ | ✓ | `product.php:526-555` + CSS `.sp-price-card` |
| خط روی قیمت قبلی با gradient + line-through | ✓ | `serviya-product.css` (`.sp-price-card del bdi`) |
| بازه‌ی قیمت دست‌نخورده | ✓ | `product.php:544-547` (بازگشت بدون تغییر) |
| اسکن اعداد فارسی + MutationObserver | ✓ | `serviya-product.js:51-100` |
| «تومان» سمت چپ با `order:2` و `translateY(-.32em)`، در نوار چسبان `-.05em` | ✓ | `serviya-product.css:316, 333, 774-775` و بلاک نوار چسبان |
| شمارش معکوس fallback با ترتیب ثانیه→روز | ✓ | `product.php:351-379` (ترتیب آرایه) + `serviya-product.js:336-360` |
| تب‌ها کپسولی، بدون خط زیر/بالا | ✓ | `serviya-product.css:1352-1435` (حذف `::before/::after` و `wd-nav-wrapper`) |
| FBT/Upsell/Related با همان بج‌ها | ✓ | `product.php:772-800` + CSS `.related/.upsells .onsale` |
| نوار چسبان وودمارت ⇒ حذف نوار افزونه | ✓ | `serviya-product.js:99-128` |
| قیمت نهایی در نوار چسبان ۲۱px (وودمارت) / ۱۷px (سرویا) و `#EC3680` | ✓ | `serviya-product.css:1810-1870` |

### بند ۸ — مقاله

| الزام | وضعیت | شاهد |
|---|---|---|
| گزینه‌ها (بدون `phone`) | ✓ | `post.php:486-498` |
| نوار پیشرفت مطالعه | ✓ | `post.php` (`data-svp-progress`) + `serviya-post.js:20-40` |
| بردکرامب: خانه ← مجله ← دسته ← عنوان کوتاه‌شده ۴۲ کاراکتر | ✓ | `post.php:214-242` (`trunc(...,42)`) |
| چیپ دسته، یک H1، لید از excerpt | ✓ | `post.php:530-560` |
| متا: نویسنده+آواتار، تاریخ فارسی، «به‌روزرسانی» بعد از ۱ روز، زمان مطالعه، دیدگاه | ✓ | `post.php:534-565` |
| `the_content` بومی + `wp_link_pages` | ✓ | `post.php:595-620` |
| فرم رمز، عدم چاپ در برگه/محصول، گارد recursion، بازگرداندن `$GLOBALS['post']` | ✓ | `post.php:492-525`، `try/finally` در `post.php:686-700` |
| برچسب، اشتراک + کپی لینک، باکس نویسنده، قبلی/بعدی | ✓ | `post.php:366-440` |
| دیدگاه بومی `comments_template()` | ✓ | `post.php:626-636` |
| سایدبار چپ فیزیکی (ستون دوم در RTL) | ✓ | `serviya-post.css` گرید `svp-layout` |
| TOC خودکار فقط با JS و ≥۲ سرفصل | ✓ | `serviya-post.js:60-120` |
| سایدبار بدون اسکرول داخلی/چسبان، ریشه شفاف | ✓ | `serviya-post.css` (`background: transparent` روی ریشه، `--svp-soft` استفاده نمی‌شود برای ستون) |
| بدون باکس تماس در سایدبار | ✓ | `post.php:286-300` کامنت صریح |
| موبایل: سایدبار زیر مقاله | ✓ | `@media (max-width: …)` در `serviya-post.css` |
| «مقالات دیگر» هم‌دسته + تکمیل با تازه‌ترین‌ها | ✓ | `post.php:438-478` |

### بند ۹ — برنامه اجرایی

این بند درباره‌ی **سایت** است، نه کد. در `docs/REBRAND.md` به همین ترتیب (فاز ۰ تا ۵) با دستورهای WP-CLI و چک‌لیست اجرایی پیاده شده است.

### بند ۱۰ — تضمین عدم پس‌رفت

از سند (خلاصه): بردکرامب تک‌خط، هم‌ترازی بج‌ها، کارت قیمت، شمارش معکوس، متغیرها، سبد/تسویه، دیدگاه، فروشگاه/وبلاگ، فرم تماس. همه در `docs/REBRAND.md` به‌صورت چک‌لیست تست با معیار عددی آمده‌اند.

---

## ۲) چیزهایی که بدون سایت زنده قابل تأیید نیستند

اینها را باید روی استیجینگ با مرورگر اندازه گرفت (من به سایت و دیتابیس شما دسترسی ندارم):

1. اختلاف مرکز عمودی آیتم‌های بردکرامب ≤ ۰٫۶px (تست: `.ss-current` در برابر `.ss-breadcrumbs li a`).
2. اختلاف لبه‌بالا و ارتفاع دو بج گالری = ۰px (تست: `.sp-dim-badge` و `.onsale`).
3. اینکه WoodMart در نسخه‌ی شما همان کلاس‌ها/مارک‌آپ را چاپ می‌کند (`ul.tabs`, `.wd-sticky-btn`, `.woocommerce-product-gallery`) — کد روی همین کلاس‌ها تنظیم شده.
4. تعداد H1 در هر صفحه = ۱ (بستگی به این دارد که قالب عنوان خودش را چاپ نکند — بند ۹ فاز ۳ سند).
5. کارکرد سبد/تسویه با درگاه تست و افزونه‌های قیمت‌گذاری نقش‌محور.
6. سرعت واقعی (Core Web Vitals) و کارکرد کش/CDN.

ابزار پیشنهادی برای اندازه‌گیری (در کنسول مرورگر، خروجی‌اش را برای من بفرستید تا اختلاف‌ها را تفسیر کنم):

```js
const r = (s) => document.querySelector(s)?.getBoundingClientRect();
const mid = (s) => { const b = r(s); return b ? b.top + b.height / 2 : null; };
const items = [...document.querySelectorAll('.ss-breadcrumbs li')].map(li => {
  const b = li.getBoundingClientRect();
  return { text: li.innerText.trim(), center: +(b.top + b.height / 2).toFixed(2) };
});
console.table(items);
const dim = r('.sp-dim-badge'), sale = r('.sp-gallery .onsale');
if (dim && sale) console.log('top diff:', (dim.top - sale.top).toFixed(2),
  '| height diff:', (dim.height - sale.height).toFixed(2), 'px');
console.log('H1 count:', document.querySelectorAll('h1').length);
```

---

## ۳) بدهی فنی و پیشنهاد (به‌ترتیب اولویت)

| # | مورد | فایل | پیشنهاد |
|---|---|---|---|
| ۱ | تابع مرده‌ی ناوبری محصول | `product.php:422-453` | حذف (سند صریحاً می‌گوید چاپ نشود) |
| ۲ | عدد/شماره‌ی hardcode برند | `product.php:574-575`, `blog.php:426`, `README-fa.md` | منبع واحد (فیلتر `serviya_contact_defaults`) یا صفحه‌ی تنظیمات — در فاز ۲ پروژه‌ی جدید |
| ۳ | عرض کانتینر ناهمگون | `serviya-shop.css:38`, `serviya-blog.css:14` | تصمیم برندینگ، سپس یکسان‌سازی (با تأیید) |
| ۴ | جهت لیبل درصد | `serviya-shop.css:578`, JS | یکسان‌سازی با `-۹۴٪` |
| ۵ | تکیه بر regex روی HTML ووکامرس (کارت قیمت، SKU، شمارش معکوس) | `product.php:533-555` و اطراف | در صورت تغییر قالب، جایگزینی با فیلترهای رسمی |
| ۶ | سبک کد ناهمگون (۴ فاصله/tab) | `shop.php`,`blog.php` vs `product.php`,`post.php` | یکسان‌سازی در یک کامیت جدا (بدون تغییر رفتار) |
| ۷ | i18n واقعی ندارد | کل افزونه | فاز ۴ سند (اختیاری) |

---

## ۴) وضعیت دارایی‌های min در این ریپو

- همه‌ی `*.min.css` / `*.min.js` با `npm run build` از سورس **بازتولید** شدند.
- اثبات هم‌ارزی CSS با نسخه‌ی داخل زیپ اصلی: **مجموعه‌ی سلکتورها بایت‌به‌بایت یکسان است** (shop 217/217، blog 128/128، product 266/266، post 175/175؛ صفر سلکتور گم‌شده یا اضافه).
- JSها دوباره با terser مینیفای شده‌اند (حجم ~۸۵ بایت بیشتر، فقط به‌خاطر بنر نسخه و تفاوت نام‌گذاری متغیرها). فایل‌ها با `node --check` تست شدند.
- بعد از هر تغییر در سورس: `npm run build` و سپس `npm run check`.
