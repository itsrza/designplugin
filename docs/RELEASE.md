# انتشار نسخه و لینک دانلود

قرار ما: **هر بار نسخه‌ی جدید آماده شد، لینک دانلود همان‌جا در گفتگو داده می‌شود.**

## قالب لینک

```text
https://github.com/itsrza/designplugin/raw/arena/8c639e3c-designplugin/release/miran-<version>.zip
```

- صفحه‌ی همه‌ی نسخه‌ها: `https://github.com/itsrza/designplugin/tree/arena/8c639e3c-designplugin/release`
- تگ/ریلیز نسخه: `https://github.com/itsrza/designplugin/releases/tag/v<version>`

> چرا فایل داخل ریپو منتشر می‌شود: آپلود asset در GitHub Release به دامنه‌ی `uploads.github.com` نیاز دارد که از محیط اجرای من در دسترس نیست. پس بسته‌ی زیپ در پوشه‌ی `release/` کامیت می‌شود (لینک `raw` مستقیم و پایدار) و در کنارش یک Release با توضیحات همان نسخه ساخته می‌شود.

## گام‌های انتشار (چک‌لیست)

1. **شماره نسخه** را هم‌زمان در سه جا بالا ببرید:
   - `miran/miran.php` → هدر `Version:` **و** `define('MIRAN_VERSION', '…')`
   - `package.json` → `"version"`
2. **چنج‌لاگ فارسی** را در `miran/README-fa.md` (بخش `## نسخه …`) اضافه کنید.
3. **بیلد و بررسی:**

   ```bash
   npm install          # اگر node_modules نبود
   npm run build        # بازسازی همه‌ی *.min.* از سورس
   npm run check        # lint PHP (php-parser) + همگامی min ها
   npm run package      # → release/miran-<version>.zip
   ```

4. **انتشار در گیت:**

   ```bash
   git add -A
   git commit -m "Release <version>: …"
   git push origin arena/8c639e3c-designplugin
   gh release create v<version> --title "Miran <version> — …" --notes "…"
   ```

5. **جدول نسخه‌ها** را در `release/README.md` به‌روز کنید (نسخه، فایل، حجم، یادداشت).
6. لینک `raw` نسخه‌ی جدید را در گفتگو بدهید.

## نگه‌داری

- هر زیپ حدود ۱۳۷KB است؛ نگه‌داشتن ۵–۶ نسخه‌ی آخر مشکلی ندارد. برای نسخه‌های قدیمی‌تر، فایل را از `release/` حذف و در جدول یادداشت کنید (تاریخچه در گیت می‌ماند).
- `legacy/serviya-3.2.3-original.zip` آرشیو مبدأ است و جزو نسخه‌های میران نیست.

## محتوای بسته

```text
miran/
  miran.php          هدر افزونه + ثابت‌ها + بارگذاری ماژول‌ها
  README-fa.md       راهنمای نصب/استفاده + چنج‌لاگ
  modules/           shop.php · blog.php · product.php · post.php
  assets/css|js/     miran-{shop,blog,product,post}.{css,js} + .min.*
```

بدون `node_modules`، بدون فایل‌های توسعه (`tools/`, `docs/`, `preview/`) و بدون آرشیو سرویا.
