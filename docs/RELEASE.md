# راهنمای انتشار نسخه (Release Guide)

این سند می‌گوید یک بیلد تازه چطور از مخزن گیت‌هاب به دست کاربرها می‌رسد و ربات چطور آن را پیدا می‌کند.

## شماره‌گذاری

| چیز | مقدار | کجا |
|---|---|---|
| نسخهٔ نمایشی | `0.0.1-beta` (قفل‌شده — `version_locked: true`) | `version.json → version` |
| **بیلد** | `fixedNN` — عدد بالاتر = جدیدتر | `version.json → build` |
| تگ گیت | `vMAJOR.MINOR.PATCH[-beta.N]` مثل `v0.1.0-beta.1` | git tag |

به‌روزرسان داخل ربات **فقط بیلد** را مقایسه می‌کند (`Updater::isNewer`)؛ پس برای هر انتشار باید `build` در `version.json` بالا برود. `version`، `version_locked` و `pinned` را تغییر ندهید.

## چک‌لیست انتشار

1. **CHANGELOG.md** — بلوک تازه بالای فایل با همین قالب (ربات همین بلوک را در تاپیک «به‌روزرسانی» گروه گزارشات می‌فرستد):

   ```markdown
   ## fixed81 — عنوان کوتاه بیلد

   - تغییر ۱
   - تغییر ۲
   ```

2. **version.json** — فقط سه کلید: `build` (مثل `fixed81`)، `released` (تاریخ میلادی `YYYY-MM-DD`) و افزودن خطوط تازه به ابتدای `changelog`.
3. اگر مایگریشن دارید: فایل `database/migrations/00NN_name.sql` با شمارهٔ بعدی.
4. `bash tools/lint.sh` → بدون خطا.
5. کامیت و push به `main`:

   ```bash
   git add -A
   git commit -m "release: fixed81"
   git push origin main
   ```

   همین لحظه ربات‌هایی که روی شاخهٔ `main` هستند در بررسی بعدی (کران‌جاب) نسخهٔ تازه را می‌بینند.

6. **تگ و ریلیز گیت‌هاب** (اختیاری اما توصیه‌شده):

   ```bash
   git tag v0.1.0-beta.2
   git push origin v0.1.0-beta.2
   ```

   ورک‌فلوی `.github/workflows/release.yml` خودکار زیپ قابل نصب + `SHA256SUMS` می‌سازد و یک GitHub Release (با بلوک CHANGELOG همین بیلد) منتشر می‌کند. تگ‌های دارای `beta`/`rc`/`alpha` به عنوان **Pre-release** علامت می‌خورند.

## ربات چطور به‌روز می‌شود؟

- **پنل وب ← به‌روزرسانی ← تب «منبع»**: مخزن (`owner/repo` یا آدرس کامل گیت‌هاب)، شاخه (خالی = شاخهٔ پیش‌فرض مخزن)، پوشهٔ داخلی (اگر `version.json` در زیرپوشه است)، توکن (فقط مخزن خصوصی).
- **بررسی نسخهٔ جدید**: `version.json` مخزن خوانده و `build` مقایسه می‌شود. دکمهٔ «جزئیات بررسی» جدول تشخیص (آدرس دقیق، کد HTTP، شاخهٔ پیش‌فرض کشف‌شده) را نشان می‌دهد.
- **نصب**: بکاپ → دریافت zip شاخه → جایگزینی فایل‌ها (به‌جز `config.php` و `storage/`) → مایگریشن → اسکن دکمه‌های ربات → اعلان در تاپیک «به‌روزرسانی».
- **نصب از فایل**: همان zip ریلیز گیت‌هاب را در تب «نصب از فایل» بدهید.
- **خط فرمان**: `php cli.php check` / `php cli.php update`.

## مخزن خصوصی

در GitHub ← Settings ← Developer settings ← **Fine-grained tokens** یک توکن با دسترسی فقط روی همین مخزن و مجوز `Contents: Read` بسازید و در تب «منبع» وارد کنید. بدون توکن، گیت‌هاب برای مخزن خصوصی کد `404` می‌دهد و پیام «فایل version.json در مخزن نیست» دیده می‌شود.

## پیشنهاد تنظیمات مخزن

- Branch protection روی `main` (حداقل: CI سبز باشد).
- Topics: `telegram-bot` `v2ray` `xray` `3x-ui` `marzban` `pasarguard` `php` `mysql` `vpn-shop`.
- Private vulnerability reporting فعال (Security ← Policy).
- LICENSE: هنوز انتخاب نشده — قبل از عمومی کردن مخزن تصمیم بگیرید (MIT / GPL-3.0 / اختصاصی).
