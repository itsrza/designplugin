# ری‌برند و راه‌اندازی سایت جدید (اجرای فازهای ۰ تا ۵ سند)

> این سند «چک‌لیست اجرایی» است، نه وعده. هر گام: چه کاری، کجا، چطور تست، چطور برگردانده شود.
> من به هاست/دیتابیس سایت شما دسترسی ندارم؛ بخش‌های داخل داشبورد/سرور را با دستور یا مسیر دقیق پیشخوان نوشته‌ام تا خودتان اجرا کنید و خروجی را برایم بفرستید. تغییرات کد افزونه را من انجام می‌دهم و ZIP می‌دهم.

## نقشه‌ی رنگ (اعمال‌شده در نسخه ۳.۳.۰)

این جدول همان چیزی است که با `tools/rebrand.mjs` روی همه‌ی ماژول‌ها اعمال شد؛ جزئیات و اندازه‌گیری کنتراست در [`BRAND.md`](BRAND.md).

| نقش | مقدار مرجع | مقدار میران | محل در کد |
|---|---|---|---|
| Accent / دکمه و بج (نقش روشن) | `#B7E600` | **`#D9A86C`** ✅ | `--srv-accent` · `--sp-accent` · `--svp-accent` + literalهای وبلاگ |
| Accent hover | `#A3CC00` | **`#C8924F`** ✅ | `--*-accent-hover` |
| Accent soft | `#F4FBE3` | **`#F9EFE1`** ✅ | `--*-accent-soft` |
| Dark (تیتر/سطح تیره) | `#18233D` | **`#46270B`** ✅ | `--*-navy` (هیرو، تب فعال، نوار چسبان) |
| Primary (متن/لینک) | `#2B3B66` | **`#7A4A16`** ✅ | `--*-ink` |
| متن بدنه | `#292D32` / `#343A44` | **`#3A2A1C` / `#4A3826`** ✅ | `--sp-text` و literalها |
| خاکستری متن | `#667085` / `#8892A3` | **`#6F5B47` / `#9B846C`** ✅ | `--*-muted` · `--*-gray` |
| آیکن/متای وبلاگ (زیتونی تیره) | `#7FA400` | **`#96632E`** ✅ | inline SVG در `blog.php` و `serviya-blog.js` |
| حاشیه | `#E2E8F0` / `#E5E7EB` | **`#EADFCF`** ✅ | `--*-border` و literalها |
| پس‌زمینه نرم | `#F6F8F3` / `#F5F5F5` | **`#F6F1E9`** ✅ | `--*-soft` |
| لیبل ابعاد | `#B8E620` | **`#D9A86C`** ✅ (متن مشکی) | `--sp-dim` + literal فروشگاه |
| قرمز خطا | `#EF4056` / `#EF4444` | بدون تغییر (معنایی) | `--*-red` |
| لیبل تخفیف | `#FF047E` | **بدون تغییر** ✅ (تصمیم کارفرما) | `--sp-sale` + literal فروشگاه |
| قیمت نهایی | `#EC3680` | **`#A5321A`** ✅ | `--sp-price-final` + literal نوار چسبان وودمارت |
| سبز/نارنجی موجودی | `#15803D` / `#B45309` | بدون تغییر (معنایی) | پیل موجودی |

نکته‌های مهم:

- سایه‌ها (`rgba(24,35,61,…)`) از Navy مشتق شده‌اند. اگر Navy عوض شد، سایه‌ها هم باید به `rgba(R,G,B,…)` جدید تبدیل شوند؛ ابزار این کار را با قاعده‌ی `rgbShadows: true` انجام می‌دهد.
- رنگ‌های **WoodMart** و **Elementor Global Colors** جدا هستند و با کد افزونه تغییر نمی‌کنند: `WoodMart ← Theme Settings ← Colors` و `Elementor ← Site Settings ← Global Colors`.
- محدوده‌ی «فقط متن/رنگ» است؛ **شناسه‌های فنی** (`[serviya_*]`، `serviya/v1`، `sb_cat/sb_q/sb_page`، `pp_*`/`ss_*`) بدون درخواست صریح شما دست نمی‌خورند (بند ۱ سند).

## متن‌ها و داده‌هایی که باید عوض شوند (فهرست دقیق فعلی)

| متن/مقدار فعلی | محل در کد | جایگزین |
|---|---|---|
| `09169066373` | `modules/blog.php:426`، `modules/product.php:574`، `README-fa.md` | تلفن برند جدید |
| `989169066373` (واتس‌اپ) | `modules/product.php:575` | واتس‌اپ برند جدید |
| «مجله سرویا» | `modules/post.php` (عنوان بردکرامب/لینک «همه مقالات مجله سرویا»)، `modules/blog.php` (اسکیمای ItemList) | «مجله {برند}» |
| «تماس با سرویا» / «مشاوران سرویا» | `modules/blog.php` (بلوک CTA) | متن برند جدید |
| «بسته‌بندی» (متن اختصاصی کسب‌وکار قبلی) | `modules/blog.php` (CTA) | متن کسب‌وکار جدید |
| «افزونه یکپارچه سرویا…» | `serviya/serviya.php:4` (Description) | در صورت تمایل: نام برند جدید (بدون تغییر نام پوشه/توابع) |
| `SERVIYA_VERSION` / هدر `Version: 3.2.3` | `serviya/serviya.php:9,17` + `package.json` | نسخه‌ی جدید مثلاً 3.3.0 هنگام تحویل |
| نام/توضیح سایت، لوگو، فاوآیکون | پیشخوان (نه کد) | برند جدید |

> شماره‌ها را می‌توان به‌جای تغییر در کد، از طریق صفت شورت‌کد هم داد (`[serviya_blog phone="…"]`، `[serviya_product phone=… whatsapp=…]`). برای سایت جدید پیشنهاد من: هم پیش‌فرض کد را عوض کنیم و هم در شورت‌کد صریح بنویسیم.

---

## فاز ۰ — آماده‌سازی و ایمنی

**۰٫۱ بکاپ و استیجینگ**

```bash
# روی هاست (WP-CLI)، در ریشه‌ی سایت مرجع
wp db export backup-$(date +%F).sql
tar -czf files-$(date +%F).tar.gz wp-content
# بازیابی روی دامنه‌ی staging
wp db import backup-YYYY-MM-DD.sql
```

**۰٫۲ جایگزینی دامنه (سریالایز-سِیف)**

```bash
# اول dry-run، تعداد موارد را چک کنید
wp search-replace 'https://old.example' 'https://staging.example' --all-tables-with-prefix --precise --dry-run
wp search-replace 'https://old.example' 'https://staging.example' --all-tables-with-prefix --precise
wp search-replace 'old.example' 'staging.example' --all-tables-with-prefix --precise
```

- اگر WP-CLI ندارید: افزونه‌ی **Better Search Replace** (با تیک serialized data) — ولی ابتدا بکاپ.
- سپس: `Elementor ← Tools ← Regenerate CSS & Data`، `تنظیمات ← پیوندهای یکتا ← ذخیره`، پاک‌کردن کش صفحه/آبجکت/CDN.

**۰٫۳ ایزوله‌کردن استیج**

- `تنظیمات ← خواندن ← درخواست موتورهای جستجو برای ایندکس نکردن` را روشن کنید.
- دسترسی: پسورد روی `.htpasswd` یا افزونه‌ای مثل Password Protected.
- ووکامرس: `ووکامرس ← تنظیمات ← پرداخت‌ها` ⇒ درگاه‌ها در حالت تست/غیرفعال؛ ایمیل‌ها: `ووکامرس ← تنظیمات ← ایمیل` با SMTP جدید و «توقف ارسال» تا زمان لانچ.
- لایسنس‌ها: WoodMart و Elementor Pro معمولاً تک‌دامنه‌اند؛ برای دامنه‌ی جدید فعال‌سازی مجدد (یا لایسنس استیجینگ) لازم است.

**۰٫۴ فهرست‌برداری (خروجی: گزارش + جدول نگاشت، بدون کد)**

```bash
wp post list --post_type=page,post,product --fields=ID,post_title,post_name,post_status --format=table
wp term list product_cat --fields=term_id,name,slug,count,parent --format=table
wp term list category --fields=term_id,name,slug,count --format=table
wp wc product_attribute list --user=1 --fields=id,name,slug,type
wp plugin list --status=active --fields=name,version --format=table
wp menu list
wp user list --role=customer --format=count
wp wc shop_order list --user=1 --format=count
```

**۰٫۵ داده‌ی مشتری (خط قرمز بند ۱)**

قبل از لانچ، یکی از این دو مسیر را انتخاب و تأیید کنید:

```bash
# مسیر الف: حذف کامل سفارش‌ها و مشتریان (سایت کاملاً تازه)
wp post delete $(wp post list --post_type=shop_order --format=ids) --force
wp user delete $(wp user list --role=customer --field=ID) --reassign=1

# مسیر ب: ناشناس‌سازی (نگه‌داشتن آمار/ساختار)
wp db query "UPDATE wp_users SET user_login=CONCAT('user_',ID), user_nicename=CONCAT('user_',ID), user_email=CONCAT('user_',ID,'@example.invalid'), display_name=CONCAT('کاربر ',ID) WHERE ID NOT IN (1);"
wp db query "UPDATE wp_usermeta SET meta_value='' WHERE meta_key IN ('billing_first_name','billing_last_name','billing_company','billing_address_1','billing_address_2','billing_phone','billing_email','shipping_first_name','shipping_last_name','shipping_address_1','shipping_address_2','shipping_phone');"
wp db query "UPDATE wp_comments SET comment_author_email=CONCAT('c',comment_ID,'@example.invalid'), comment_author_IP='127.0.0.1' WHERE comment_ID>0;"
```

- بعد از هر کدام: `wp cache flush` و بازبینی سفارش نمونه/دیدگاه نمونه.
- پیشنهاد من: برای یک سایت نو، **مسیر الف**؛ اگر می‌خواهید ساختار گزارش‌ها بماند، **مسیر ب** + تغییر نام کاربران به فارسی (اختیاری).

---

## فاز ۱ — هویت بصری

1. جدول رنگ بالا را نهایی کنید.
2. اعمال در وودمارت: `WoodMart ← Theme Settings ← Colors` (Primary, Primary hover, Dark, Button…) و `Elementor ← Site Settings ← Global Colors`.
3. اعمال در کد افزونه (خودکار):

```bash
# config را از نمونه بسازید و مقادیر برند جدید را در آن بگذارید
cp rebrand.config.example.json rebrand.config.json
node tools/rebrand.mjs --dry-run     # فقط گزارش: کدام فایل، چند مورد
node tools/rebrand.mjs --write       # بکاپ + اعمال + بازسازی فایل‌های min
npm run check                         # lint PHP + بررسی min
npm run package                       # release/serviya-<version>.zip
```

4. لوگو/فاوآیکون/نام سایت: `تنظیمات ← عمومی` + `WoodMart ← Theme Settings ← General/Header`.
5. تست: یک صفحه محصول (بج‌ها، قیمت، تب‌ها)، یک صفحه فروشگاه (کارت‌ها)، یک مقاله. اسکرین‌شات بفرستید تا رنگ‌های باقی‌مانده (مثلاً literalهای جاافتاده) را پیدا کنم.

**برگرداندن:** ZIP قبلی + فایل‌های هدر/فوتر قبلی وودمارت؛ هر تغییر کد با `git revert` یک کامیت مستقل.

---

## فاز ۲ — متن‌ها و داده‌ها

1. **قبل از هر جایگزینی، فهرست را تأیید کنید.** جدول بالا (بخش «متن‌ها») مبنا است.
2. جایگزینی متن/برند در محتوا و المنتور:

```bash
wp search-replace 'سرویا' 'برند جدید' --all-tables-with-prefix --precise --dry-run
wp search-replace '09169066373' '02100000000' --all-tables-with-prefix --precise
```

   سپس `Elementor ← Tools ← Regenerate CSS & Data` و پاک‌کردن کش.
3. **محصولات/دسته‌ها:** تصمیم با شما (حذف داده‌ی نمونه یا جایگزینی). پیشنهاد برای سایت نو:

```bash
# پاک‌سازی داده نمونه (بکاپ بگیرید!)
wp post delete $(wp post list --post_type=product --format=ids) --force
wp term list product_cat --fields=term_id,slug --format=csv   # بعد از بازبینی، حذف موارد لازم
```

   ایمپورت: `محصولات ← همه محصولات ← درون‌ریزی` با CSV ووکامرس (ستون‌های `Slug` را **از ابتدا ثابت** تعریف کنید؛ بعداً تغییر اسلاگ = شکستن لینک دسته در مقاله).
4. **متغیرها:** برای هر متغیر قیمت و وضعیت موجودی ثبت شود (سند بند ۷) وگرنه دکمه‌ی خرید غیرفعال می‌ماند.
5. **وبلاگ:** برای هر نوشته «خلاصه» (excerpt) بنویسید — لید مقاله از آن می‌آید — و سرفصل‌ها را H2/H3 بزنید تا فهرست خودکار فعال شود (نیاز: ≥۲ سرفصل).

---

## فاز ۳ — صفحات خاص

1. `برگه‌ها ← همه برگه‌ها` ⇒ چهار برگه‌ی سرویس: هر کدام فقط ویجت Shortcode:
   - فروشگاه: `[serviya_shop per_page="12"]`
   - وبلاگ: `[serviya_blog per_page="9" phone="…" products_url="…"]`
   - صفحه‌ی محصول (قالب تکی): `[serviya_product]`
   - صفحه‌ی مقاله (قالب تکی): `[serviya_post]`
2. قالب محصول: `WoodMart ← Layouts ← Add new ← Single Product` (شرط: All products) با یک ویجت Shortcode، **بدون** ویجت گالری/عنوان/قیمت کنارش.
3. قالب مقاله: `قالب‌ها ← پوسته‌ساز ← افزودن ← تکی: نوشته‌ها`، چیدمان تمام‌عرض، عنوان/سایدبار قالب خاموش، فقط Shortcode `[serviya_post]`، شرط «همه نوشته‌ها».
4. خانه/درباره ما/تماس با ما/قوانین: محتوا، فرم تماس (تست ارسال + ایمیل)، نقشه، اطلاعات تماس یکدست در هدر/فوتر/اسکیما.
5. **یک H1 در هر صفحه:** اگر قالب عنوان چاپ می‌کند، برای همان صفحه مخفی کنید (`WoodMart ← Theme Settings` یا CSS اختصاصی). تست:

```js
console.log(document.querySelectorAll('h1').length, [...document.querySelectorAll('h1')].map(h=>h.textContent.trim()));
```

---

## فاز ۴ — سئو و سرعت

- `رنک‌مث/یواست` (یا افزونه‌ی فعلی): عنوان/توضیح صفحات کلیدی، اسلاگ تمیز، **ریدایرکت ۳۰۱** برای هر URL عوض‌شده.
- `noindex` استیجینگ فقط هنگام لانچ برداشته شود؛ نقشه‌ی سایت جدید در Search Console ثبت شود.
- سرعت: تصاویر WebP + ابعاد درست، وزیرمتن Variable WOFF2 با `preload` یک‌بار سراسری (افزونه‌ی وردپرس خودش فونت دوم از CDN لود نکند)، PHP 8.2+، OPcache، Brotli/Gzip، HTTP/2، Redis Object Cache در صورت پشتیبانی هاست.
- حذف اسکریپت/استایل افزونه‌های غیرلازم فقط در فروشگاه/وبلاگ و **بعد از تست سبد خرید**.

---

## فاز ۵ — QA نهایی (معیار عددی)

روی هر سه اندازه (۳۹۰px، ۷۶۸px، دسکتاپ) اجرا و عدد را ثبت کنید:

```js
// ۱) بردکرامب: اختلاف مرکز آیتم‌ها و تعداد خط
const items = [...document.querySelectorAll('.ss-breadcrumbs li, .sp-breadcrumb li, .svp-breadcrumb li')];
const h = new Set(items.map(li => Math.round(li.getBoundingClientRect().height)));
const c = items.map(li => { const b = li.getBoundingClientRect(); return b.top + b.height/2; });
console.log('breadcrumb lines:', h.size, 'max center diff:', (Math.max(...c)-Math.min(...c)).toFixed(2), 'px');

// ۲) بج‌های گالری محصول
const d = document.querySelector('.sp-dim-badge')?.getBoundingClientRect();
const s = document.querySelector('.sp-gallery .onsale')?.getBoundingClientRect();
console.log('badge top diff:', d && s ? (d.top - s.top).toFixed(2) : 'n/a', '| height diff:', d && s ? (d.height - s.height).toFixed(2) : 'n/a');

// ۳) سرریز افقی
console.log('overflow-x:', document.documentElement.scrollWidth - document.documentElement.clientWidth, 'px');
```

| چک | معیار قبولی |
|---|---|
| بردکرامب هر سه ماژول | تک‌خط، اختلاف مرکز ≤ ۰٫۶px |
| بج ابعاد/تخفیف | اختلاف بالا و ارتفاع = ۰px؛ ۳۴/۳۰px در محصول، ۳۰/۲۵px در فروشگاه |
| سرریز افقی | ۰px در ۳۹۰px |
| H1 | دقیقاً ۱ عدد در هر صفحه |
| کارت قیمت | del بالا + قیمت نهایی `#EC3680` پایین + برچسب چپ؛ بازه‌ی قیمت دست‌نخورده |
| شمارش معکوس | ترتیب ثانیه→روز، اعداد فارسی، بدون دوبار |
| متغیرها | انتخاب متغیر ⇒ قیمت/SKU/موجودی/نوار چسبان آپدیت |
| سبد/تسویه | افزودن از کارت و از صفحه محصول، شمارنده هدر، تسویه تا مرحله پرداخت (تست) |
| فروشگاه/وبلاگ | فیلتر، جستجو، «بیشتر»، back مرورگر، حالت بدون JS (DevTools → Disable JS) |
| مقاله | TOC، نوار پیشرفت، کپی لینک، دیدگاه، مقالات دیگر |
| فرم تماس | ارسال موفق + دریافت ایمیل |

---

## چه چیزی از شما لازم است

1. جدول رنگ تکمیل‌شده + برند/تلفن/واتس‌اپ/شبکه‌های اجتماعی + لوگو.
2. تصمیم روی `noindex`/استیجینگ و اینکه سایت جدید از صفر شروع می‌شود یا داده‌ها منتقل می‌شوند.
3. تصمیم درباره‌ی داده‌ی مشتری مرجع (حذف کامل یا ناشناس‌سازی).
4. اگر می‌خواهید عددها را من تفسیر کنم: خروجی سه اسکریپت بالا از هر سه اندازه + اسکرین‌شات صفحه‌ها.
