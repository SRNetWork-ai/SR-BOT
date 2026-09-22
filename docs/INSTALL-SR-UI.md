# نصب خودکار با `install.sh` و مدیریت سرور با `sr-ui`

این راهنما برای نصب روی **سرور مجازی/اختصاصی (VPS)** است. برای هاست اشتراکی (سی‌پنل/دایرکت‌ادمین) بخش «نصب روی هاست اشتراکی» در [README](../README.md) را ببینید.

---

## ۱) نصب تک‌خطی

روی سرور **تازه** و با کاربر **root**:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/main/install.sh)
```

یا اگر زیپ ریلیز را از قبل دانلود کرده‌اید:

```bash
unzip SR-BOT-*.zip -d /root/srb && cd /root/srb/SR-BOT-* && bash install.sh
```

سیستم‌عامل‌های پشتیبانی‌شده:

| خانواده | نسخه‌ها | مدیر بسته | کاربر وب |
|---|---|---|---|
| Ubuntu / Debian / Mint / Pop!_OS | Ubuntu 20.04–24.04 · Debian 11/12 | `apt` | `www-data` |
| CentOS Stream / RHEL / AlmaLinux / Rocky / Fedora / Oracle | 8 و 9 (و Fedora جدید) | `dnf` یا `yum` | `nginx` |

حداقل PHP مورد نیاز: **8.1** (اگر مخزن سیستم نسخهٔ قدیمی‌تری بدهد، نصاب هشدار می‌دهد).

---

## ۲) نصاب دقیقاً چه کاری می‌کند؟

| گام | جزئیات |
|---|---|
| تشخیص سیستم | خانوادهٔ توزیع، مدیر بسته و کاربر وب‌سرور |
| نصب پیش‌نیازها | `nginx`، `mariadb-server`، `cron/cronie`، `php-fpm`، `php-cli` و افزونه‌های `mysql/mysqlnd`، `mbstring`، `curl`، `zip`، `gd`، `xml`، `bcmath`، `intl` |
| بررسی PHP | مقایسهٔ نسخهٔ نصب‌شده با `min_php` پروژه |
| تشخیص PHP-FPM | پیدا کردن نام سرویس و سوکت (`/run/php/php*-fpm.sock` یا `/run/php-fpm/www.sock`؛ در نهایت `127.0.0.1:9000`) |
| راه‌اندازی سرویس‌ها | `enable` + `start` برای nginx، mariadb، php-fpm و cron |
| دیتابیس | ساخت دیتابیس `utf8mb4`، ساخت کاربر با **رمز تصادفی**، `GRANT` و `FLUSH` (اگر ورود root با سوکت ممکن نبود، رمز root را می‌پرسد) |
| دریافت سورس | دانلود شاخهٔ انتخابی از گیت‌هاب؛ **`config.php` موجود حفظ می‌شود** و پوشه‌های `storage/{logs,backups,tmp,uploads}` ساخته می‌شوند |
| دسترسی‌ها | پوشه‌ها `755`، فایل‌ها `644`، `storage` نوشتنی `775`، `config.php` با `640` و مالکیت کاربر وب |
| SELinux | ست‌کردن کانتکست `httpd_sys_rw_content_t` برای `storage` و اجازهٔ اتصال شبکه به PHP |
| وی‌هاست nginx | ساخت `sr-bot.conf` با بستن مسیرهای `app/`، `database/`، `cron/`، `tools/`، فایل `config.php`، `storage/{logs,backups,tmp}` و فایل‌های مخفی + گرفتن `nginx -t` قبل از ری‌لود |
| کران‌جاب | `/etc/cron.d/sr-bot` → اجرای `cron/tasks.php` هر ۵ دقیقه با کاربر وب |
| فایروال | باز کردن پورت وب روی `ufw` یا `firewalld` |
| SSL | در صورت دادن دامنه و تأیید، نصب certbot و گرفتن گواهی برای nginx |
| نصب `sr-ui` | کپی `tools/sr-ui` به `/usr/local/bin/sr-ui` (+ میان‌بر `srui`) |
| ذخیرهٔ اطلاعات | `/etc/sr-bot/sr-ui.conf` و `/root/sr-bot-install.txt` (هر دو با دسترسی `600`) |

### آنچه نصاب انجام **نمی‌دهد**

نصاب فایل `config.php` و **حساب مدیر اول** را نمی‌سازد. در پایان، آدرس نصاب وب را نشان می‌دهد:

```
http(s)://دامنه[:پورت]/install/
```

همان‌جا اطلاعات دیتابیس (از `/root/sr-bot-install.txt`)، توکن ربات و حساب مدیر را وارد می‌کنید؛ نصاب وب `config.php` را می‌سازد، جداول را ایجاد و وب‌هوک را تنظیم می‌کند و بعد خودش پوشهٔ `install/` را حذف می‌کند.

---

## ۳) نصب بی‌سؤال (Unattended)

```bash
SRB_NONINTERACTIVE=1 SRB_DOMAIN=bot.example.com SRB_SSL=1 \
  bash <(curl -fsSL https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/main/install.sh)
```

| متغیر | پیش‌فرض | کار |
|---|---|---|
| `SRB_DOMAIN` | خالی | دامنهٔ پنل؛ خالی = آی‌پی سرور |
| `SRB_PORT` | `80` | پورت وب |
| `SRB_SSL` | `0` | `1` = گرفتن گواهی با certbot (دامنه لازم است) |
| `SRB_ROOT` | `/var/www/sr-bot` | مسیر نصب |
| `SRB_DB_NAME` | `srbot` | نام دیتابیس |
| `SRB_DB_USER` | `srbot` | کاربر دیتابیس |
| `SRB_DB_PASS` | تصادفی | رمز دیتابیس |
| `SRB_DB_PREFIX` | `vs_` | پیشوند جدول‌ها |
| `SRB_REPO` | `SRNetWork-ai/SR-BOT` | مخزن سورس |
| `SRB_BRANCH` | `main` | شاخه |
| `SRB_NONINTERACTIVE` | `0` | `1` = بدون هیچ پرسشی |

---

## ۴) مسیرها و فایل‌های مهم

| مسیر | توضیح |
|---|---|
| `/var/www/sr-bot` | ریشهٔ پروژه (قابل تغییر با `SRB_ROOT`) |
| `/etc/sr-bot/sr-ui.conf` | تنظیمات سرور برای `sr-ui` (مسیر، دامنه، پورت، کاربر وب، سرویس FPM، اطلاعات دیتابیس) — دسترسی `600` |
| `/root/sr-bot-install.txt` | خلاصهٔ نصب + رمز دیتابیس — دسترسی `600` |
| `/etc/cron.d/sr-bot` | کران‌جاب پنج‌دقیقه‌ای |
| `/etc/nginx/sites-available/sr-bot.conf` یا `/etc/nginx/conf.d/sr-bot.conf` | وی‌هاست |
| `/var/log/nginx/sr-bot.access.log` · `sr-bot.error.log` | لاگ وب‌سرور |
| `/usr/local/bin/sr-ui` (+ `srui`) | دستور مدیریت |
| `/root/sr-bot-backups` | بکاپ خودکاری که هنگام حذف کامل گرفته می‌شود |

---

## ۵) دستور مدیریت `sr-ui`

فقط بنویسید:

```bash
sr-ui
```

منوی فارسی باز می‌شود (۱۶ گزینه + خروج). برای استفادهٔ غیرتعاملی هم می‌توانید مستقیم زیردستور بدهید:

| دستور | کار |
|---|---|
| `sr-ui status` | وضعیت nginx / php-fpm / mysql / cron، مسیر نصب، آدرس، نسخه و بیلد، وجود `config.php`، کد HTTP سایت |
| `sr-ui start` · `stop` · `restart` | مدیریت سرویس‌های وب |
| `sr-ui check` | بررسی نسخهٔ جدید روی گیت‌هاب |
| `sr-ui update` | بررسی، تأیید، دریافت و نصب نسخهٔ جدید + بکاپ خودکار + اصلاح دسترسی + ری‌استارت FPM |
| `sr-ui migrate` | اجرای مایگریشن‌های باقی‌مانده |
| `sr-ui backup` | بکاپ دیتابیس یا کامل |
| `sr-ui restore` | بازگردانی از فهرست بکاپ‌ها |
| `sr-ui webhook` (یا `hook`) | نمایش/تنظیم/حذف وب‌هوک تلگرام |
| `sr-ui token <TOKEN>` | تغییر توکن ربات و تنظیم مجدد وب‌هوک |
| `sr-ui ssl` | گرفتن/تمدید گواهی SSL |
| `sr-ui domain <دامنه>` | تغییر دامنه یا پورت در وی‌هاست و تنظیمات |
| `sr-ui cron` | بررسی و بازنویسی کران‌جاب |
| `sr-ui logs` | آخرین خطاهای nginx و لاگ‌های `storage/logs` |
| `sr-ui perms` | اصلاح مالکیت و دسترسی فایل‌ها |
| `sr-ui db` | ورود به کنسول MySQL همین پروژه |
| `sr-ui info` · `url` · `version` | اطلاعات نصب، آدرس پنل، نسخه/بیلد |
| `sr-ui uninstall` | حذف کامل (با تأیید تایپی `yes`) — بکاپ در `/root/sr-bot-backups`، حذف وی‌هاست/کران/فایل‌ها و به‌دلخواه `DROP DATABASE` |
| `sr-ui help` | فهرست دستورها |

---

## ۶) به‌روزرسانی

سه راه دارید و هر سه یک منبع را می‌خوانند (مخزن تنظیم‌شده در پنل → به‌روزرسانی → منبع):

1. پنل وب → **به‌روزرسانی** → «بررسی نسخهٔ جدید»
2. `sr-ui update` روی سرور
3. `php cli.php update` (همان کاری که `sr-ui` انجام می‌دهد)

در هر سه حالت `config.php` و پوشهٔ `storage/` دست‌نخورده می‌مانند و پیش از نصب، بکاپ گرفته می‌شود.

---

## ۷) عیب‌یابی

| نشانه | راه‌حل |
|---|---|
| `nginx -t` خطا می‌دهد | نصاب وی‌هاست را فعال نمی‌کند تا تست پاس شود؛ خروجی خطا را ببینید و در صورت تداخل، وی‌هاست پیش‌فرض (`default`) را غیرفعال کنید |
| صفحهٔ سفید یا خطای ۵۰۲ | سرویس PHP-FPM یا مسیر سوکت؛ `sr-ui status` و بعد `sr-ui restart` |
| خطای دسترسی نوشتن در `storage/` | `sr-ui perms` |
| نصاب وب باز نمی‌شود | پوشهٔ `install/` بعد از نصب حذف می‌شود؛ برای نصب مجدد همان پوشه را از زیپ برگردانید |
| ربات جواب نمی‌دهد | `sr-ui webhook` (دامنه باید SSL معتبر داشته باشد) |
| مصرف کاربران صفر می‌ماند | `sr-ui cron` |
| رمز دیتابیس را گم کردم | `sr-ui info` یا فایل `/root/sr-bot-install.txt` |
| تلگرام در دسترس نیست | سرور ایران معمولاً به `api.telegram.org` دسترسی ندارد؛ از سرور خارج یا پروکسی استفاده کنید |

برای بررسی سینتکس سورس هم: `bash tools/lint.sh`
