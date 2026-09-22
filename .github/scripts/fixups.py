# -*- coding: utf-8 -*-
# fixed118 - README: one-line installer section + sr-ui manager; recon CHANGELOG head
import io, os, re, sys, json, tempfile, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed118').strip() or 'fixed118'

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def dump(tag, path, start, end):
    try:
        lines = load(path).split('\n')
    except Exception as e:
        print('dump %s: cannot read %s (%s)' % (tag, path, e))
        return
    print('---- dump %s : %s (%d lines) ----' % (tag, path, len(lines)))
    i = max(1, start)
    last = min(len(lines), end)
    while i <= last:
        print('%4d %s' % (i, lines[i - 1]))
        i += 1
    print('---- end dump %s ----' % tag)


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    try:
        src = load(path)
    except Exception as e:
        ERRORS.append('%s: cannot read (%s)' % (path, e))
        return
    if marker and marker in src:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    n = len(rx.findall(src))
    if n != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker or pattern[:40], n, expect)
        if optional:
            WARN.append(msg)
        else:
            ERRORS.append(msg)
        return
    CACHE[path] = rx.sub(fn, src, count=expect)
    NEW[path] = True
    print('patched %s (%s)' % (path, marker or 'ok'))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    lint = 0
    for path in sorted(NEW):
        data = CACHE[path]
        if path.endswith('.php'):
            tmp = os.path.join(tempfile.gettempdir(), 'syntax-check.php')
            with io.open(tmp, 'w', encoding='utf-8') as fh:
                fh.write(data)
            r = subprocess.run(['php', '-l', tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print('php lint FAILED for ' + path)
                print(r.stdout + r.stderr)
                sys.exit(1)
            lint += 1
        with io.open(os.path.join(ROOT, path), 'w', encoding='utf-8') as fh:
            fh.write(data)
        print('wrote ' + path)
    print('php lint: %s' % ('on' if lint else 'n/a'))
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


# ------------------------------------------------------------ installer sanity
for p in ('install.sh', 'tools/sr-ui'):
    fp = os.path.join(ROOT, p)
    if not os.path.exists(fp):
        print('MISSING ' + p)
        sys.exit(1)
    r = subprocess.run(['bash', '-n', fp], capture_output=True, text=True)
    print('bash -n %s : %s' % (p, 'ok' if r.returncode == 0 else 'FAILED'))
    if r.returncode != 0:
        print(r.stdout + r.stderr)
        sys.exit(1)
    os.chmod(fp, 0o755)
    print('chmod 0755 %s (mode %s)' % (p, oct(os.stat(fp).st_mode & 0o777)))

# ------------------------------------------------------------ recon for next step
dump('chlog', 'CHANGELOG.md', 1, 46)

# ------------------------------------------------------------ README patches
EN_EXTRA = '\nA one-line installer (`install.sh`) provisions nginx, PHP-FPM, MariaDB, cron, firewall and SSL on a fresh Ubuntu/Debian/RHEL server, then installs `sr-ui` - an x-ui-style menu that manages the whole stack from the terminal (status, update, backup/restore, webhook, SSL, domain, logs, uninstall).\n'

NEW_SECTION = r'''## ⚡ نصب تک‌خطی و خودکار (پیشنهادی)

روی یک سرور تازه (Ubuntu 20/22/24، Debian 11/12، CentOS/Alma/Rocky 8 و 9، Fedora) فقط همین یک دستور را با کاربر root بزنید:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/main/install.sh)
```

نصاب به‌صورت خودکار: سیستم‌عامل و مدیر بستهٔ آن را تشخیص می‌دهد · nginx، PHP-FPM و افزونه‌های لازم، MariaDB و cron را نصب می‌کند · نسخهٔ PHP را بررسی می‌کند · سرویس و سوکت PHP-FPM را پیدا می‌کند · دیتابیس و کاربر آن را با رمز تصادفی می‌سازد · سورس را از همین مخزن می‌گیرد (اگر `config.php` قبلی باشد حفظ می‌شود) · دسترسی‌ها و SELinux را درست می‌کند · وی‌هاست امن nginx می‌نویسد (`app/`، `database/`، `cron/`، `tools/`، `config.php` و `storage/` بسته می‌شوند) · کران‌جاب پنج‌دقیقه‌ای می‌گذارد · پورت را روی ufw/firewalld باز می‌کند · در صورت دادن دامنه با certbot گواهی SSL می‌گیرد · و در پایان دستور مدیریت `sr-ui` را نصب می‌کند.

در پایان، آدرس `http(s)://دامنه[:پورت]/install/` را باز کنید تا نصاب وب فایل `config.php` و حساب مدیر را بسازد. رمز دیتابیس و خلاصهٔ نصب در `/root/sr-bot-install.txt` و تنطیمات سرور در `/etc/sr-bot/sr-ui.conf` ذخیره می‌شود.

### نصب بی‌سؤال (بدون پرسش)

```bash
SRB_NONINTERACTIVE=1 SRB_DOMAIN=bot.example.com SRB_SSL=1 \
  bash <(curl -fsSL https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/main/install.sh)
```

| متغیر | پیش‌فرض | کار |
|---|---|---|
| `SRB_DOMAIN` | خالی | دامنهٔ پنل؛ خالی = آی‌پی سرور |
| `SRB_PORT` | `80` | پورت وب |
| `SRB_SSL` | `0` | `1` = گرفتن گواهی با certbot |
| `SRB_ROOT` | `/var/www/sr-bot` | مسیر نصب |
| `SRB_DB_NAME` / `SRB_DB_USER` / `SRB_DB_PASS` | `srbot` / `srbot` / تصادفی | دیتابیس |
| `SRB_DB_PREFIX` | `vs_` | پیشوند جدول‌ها |
| `SRB_REPO` / `SRB_BRANCH` | `SRNetWork-ai/SR-BOT` / `main` | منبع سورس |
| `SRB_NONINTERACTIVE` | `0` | `1` = بدون هیچ پرسشی |

### مدیریت سرور با دستور `sr-ui`

بعد از نصب فقط بنویسید `sr-ui` تا منوی فارسی (شبیه منوی x-ui) باز شود، یا مستقیم:

```bash
sr-ui status            # وضعیت nginx / php-fpm / mysql / cron + نسخه و بیلد + کد HTTP سایت
sr-ui restart           # ری‌استارت nginx و php-fpm
sr-ui update            # بررسی و نصب نسخهٔ جدید از گیت‌هاب + بکاپ و اصلاح دسترسی‌ها
sr-ui backup            # بکاپ دیتابیس یا کامل
sr-ui restore           # بازگردانی از فهرست بکاپ‌ها
sr-ui migrate | check   # مایگریشن / بررسی نسخه
sr-ui webhook | token   # وضعیت و تنطیم وب‌هوک، تغییر توکن ربات
sr-ui ssl | domain      # گواهی SSL، تغییر دامنه و پورت
sr-ui logs | perms | db # لاگ‌ها، اصلاح دسترسی، کنسول دیتابیس
sr-ui uninstall         # حذف کامل (با بکاپ در /root/sr-bot-backups)
```

راهنمای کامل نصاب و همهٔ گزینه‌های `sr-ui`: [docs/INSTALL-SR-UI.md](docs/INSTALL-SR-UI.md)

---

'''

rep_rx(
    'README.md',
    r'runs on a VPS or shared hosting\. Interface language is Persian\.\n',
    lambda m: m.group(0) + EN_EXTRA,
    marker='x-ui-style menu',
)

rep_rx(
    'README.md',
    r'^(## )([^\n]*) نصب روی سرور \(VPS\)\n\nروی Ubuntu 20/22/24 یا Debian 11/12:\n',
    lambda m: NEW_SECTION + m.group(1) + m.group(2) + ' نصب دستی روی سرور (VPS)\n\nروی Ubuntu 20/22/24 یا Debian 11/12:\n',
    marker='نصب تک‌خطی و خودکار',
)

rep_rx(
    'README.md',
    r'^(├── install/ +نصاب وب)',
    lambda m: '├── install.sh             نصاب خودکار سرور (تک‌خطی) + نصب دستور sr-ui\n' + m.group(1),
    marker='نصاب خودکار سرور',
)

rep_rx(
    'README.md',
    r'^(├── tools/)( +)lint\.sh \(php -l\)',
    lambda m: m.group(1) + m.group(2) + 'sr-ui (مدیر سرور در خط فرمان)، lint.sh (php -l)',
    marker='مدیر سرور در خط فرمان',
)

rep_rx(
    'README.md',
    r'^- راهنمای انتشار نسخه: \[docs/RELEASE\.md\]\(docs/RELEASE\.md\)\n',
    lambda m: '- راهنمای نصب خودکار و دستور sr-ui: [docs/INSTALL-SR-UI.md](docs/INSTALL-SR-UI.md)\n' + m.group(0),
    marker='راهنمای نصب خودکار و دستور sr-ui',
)

write_all()

# ------------------------------------------------------------ sanity
SANITY = [
    ('README.md', 'نصب تک‌خطی و خودکار'),
    ('README.md', 'sr-ui status'),
    ('README.md', 'SRB_NONINTERACTIVE'),
    ('README.md', 'نصاب دستی روی سرور'),
    ('README.md', 'نصاب خودکار سرور'),
    ('README.md', 'مدیر سرور در خط فرمان'),
    ('README.md', 'x-ui-style menu'),
    ('README.md', '[docs/INSTALL-SR-UI.md](docs/INSTALL-SR-UI.md)'),
    ('docs/INSTALL-SR-UI.md', 'sr-ui uninstall'),
    ('install.sh', 'install_srui'),
    ('tools/sr-ui', 'c_uninstall'),
]
for path, needle in SANITY:
    try:
        ok = needle in load(path)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (path, needle, 'ok' if ok else 'MISSING'))

# ------------------------------------------------------------ version + changelog
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
ENTRY = 'مستندسازی نصاب خودکار: راهنمای docs/INSTALL-SR-UI.md، بخش نصب تک‌خطی در README و اجرایی ماندن install.sh و sr-ui در زیپ ریلیز'
cl = vj.get('changelog')
if isinstance(cl, list) and (len(cl) == 0 or isinstance(cl[0], str)):
    if ENTRY in cl:
        print('changelog: already present')
    else:
        cl.insert(0, ENTRY)
        print('changelog: entry added')
    vj['changelog'] = cl
else:
    print('changelog: skipped (shape=%s)' % type(cl).__name__)
    if isinstance(cl, list) and cl:
        print('changelog[0] repr: ' + repr(cl[0])[:300])
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
