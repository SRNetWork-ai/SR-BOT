# -*- coding: utf-8 -*-
# fixed119 - README: Docker section + docs link + typo fix; sanity for the docker stack
import io, os, re, sys, json, tempfile, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed119').strip() or 'fixed119'

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


def fix_typo(path, bad, good):
    try:
        src = load(path)
    except Exception as e:
        print('typo %s: cannot read (%s)' % (path, e))
        return
    n = src.count(bad)
    if n:
        CACHE[path] = src.replace(bad, good)
        NEW[path] = True
    print('typo %s: %s -> %s (%d)' % (path, bad, good, n))


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


# ------------------------------------------------------------ shell sanity
for p in ('install.sh', 'tools/sr-ui', 'docker/entrypoint.sh', 'docker/cron.sh'):
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

# ------------------------------------------------------------ compose sanity
compose = load('docker-compose.yml')
print('docker-compose.yml: tabs=%d services=%s' % (
    compose.count('\t'),
    ','.join([k for k in ('db:', 'app:', 'web:', 'cron:') if ('\n  ' + k) in compose]),
))
if compose.count('\t'):
    print('ABORTED - tabs are not allowed in YAML')
    sys.exit(1)

# ------------------------------------------------------------ README patches
DOCKER_SECTION = r'''## ☁️ اجرا با داکر (اختیاری)

اگر ترجیح می‌دهید همه‌چیز داخل کانتینر باشد (nginx + PHP-FPM + MariaDB + وظایف دوره‌ای):

```bash
git clone https://github.com/SRNetWork-ai/SR-BOT.git /opt/sr-bot && cd /opt/sr-bot
cp .env.docker.example .env      # حداقل DB_PASS و DB_ROOT_PASS را عوض کنید
docker compose up -d --build
```

سپس `http://SERVER_IP:8080/install/` را باز کنید؛ در نصاب وب **هاست دیتابیس را `db`** و پورت را `3306` بگذارید.

| سرویس | کار |
|---|---|
| `db` | MariaDB 11 با ولوم پایدار `dbdata` |
| `app` | PHP-FPM 8.3 با افزونه‌های `pdo_mysql, zip, gd, intl, bcmath, opcache` |
| `web` | nginx روی پورت `WEB_PORT` با وی‌هاست امن `docker/nginx.conf` |
| `cron` | اجرای `cron/tasks.php` هر `CRON_INTERVAL` ثانیه |

پوشهٔ پروژه داخل کانتینرها mount می‌شود؛ پس `config.php`، `storage/` و به‌روزرسانی از داخل پنل روی هاست باقی می‌مانند. دستورهای پرکاربرد:

```bash
docker compose ps
docker compose logs -f app web cron
docker compose exec -u www-data app php cli.php check
docker compose exec -u www-data app php cli.php migrate
```

وب‌هوک تلگرام به HTTPS نیاز دارد؛ یک پراکسی (nginx/caddy/Cloudflare) جلوی پورت `8080` بگذارید.
راهنمای کامل داکر: [docs/DOCKER.md](docs/DOCKER.md)

---

'''

rep_rx(
    'README.md',
    r'بدون نیاز به Composer، Node یا Docker',
    lambda m: 'بدون نیاز به Composer یا Node (اجرای اختیاری با Docker)',
    marker='اجرای اختیاری با Docker',
)

rep_rx(
    'README.md',
    r'no Composer/Node/Docker; runs on a VPS or shared hosting\.',
    lambda m: 'no Composer or Node, with an optional Docker Compose stack included; runs on a VPS or shared hosting.',
    marker='optional Docker Compose stack',
)

rep_rx(
    'README.md',
    r'^(## )([^\n]*) نصب روی هاست اشتراکی',
    lambda m: DOCKER_SECTION + m.group(0),
    marker='اجرا با داکر',
)

rep_rx(
    'README.md',
    r'^(├── version\.json +نسخه و بیلد)',
    lambda m: '├── docker/                Dockerfile، docker-compose.yml و اجرای کانتینری (اختیاری)\n' + m.group(1),
    marker='اجرای کانتینری',
)

rep_rx(
    'README.md',
    r'^- راهنمای نصب خودکار و دستور sr-ui: \[docs/INSTALL-SR-UI\.md\]\(docs/INSTALL-SR-UI\.md\)\n',
    lambda m: m.group(0) + '- راهنمای اجرای داکری: [docs/DOCKER.md](docs/DOCKER.md)\n',
    marker='راهنمای اجرای داکری',
)

# رفع غلط تایپی جامانده از بستهٔ قبلی
fix_typo('README.md', 'تنطیم', 'تنظیم')
fix_typo('docs/INSTALL-SR-UI.md', 'تنطیم', 'تنظیم')

write_all()

# ------------------------------------------------------------ sanity
SANITY = [
    ('README.md', 'اجرا با داکر'),
    ('README.md', 'docker compose up -d --build'),
    ('README.md', '[docs/DOCKER.md](docs/DOCKER.md)'),
    ('README.md', 'اجرای کانتینری'),
    ('README.md', 'اجرای اختیاری با Docker'),
    ('README.md', 'optional Docker Compose stack'),
    ('README.md', 'راهنمای اجرای داکری'),
    ('Dockerfile', 'docker-php-ext-install'),
    ('docker-compose.yml', 'mariadb:11'),
    ('docker/nginx.conf', 'fastcgi_pass app:9000;'),
    ('docker/entrypoint.sh', 'exec "$@"'),
    ('docker/cron.sh', 'cron/tasks.php'),
    ('docs/DOCKER.md', 'docker compose exec'),
    ('.dockerignore', 'config.php'),
    ('.env.docker.example', 'WEB_PORT'),
]
for path, needle in SANITY:
    try:
        ok = needle in load(path)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (path, needle, 'ok' if ok else 'MISSING'))

for path in ('README.md', 'docs/INSTALL-SR-UI.md'):
    try:
        left = load(path).count('تنطیم')
    except Exception:
        left = -1
    print('typo left %s : %d' % (path, left))

# ------------------------------------------------------------ version + changelog
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
ENTRY = 'اجرای اختیاری با داکر: Dockerfile، docker-compose.yml (nginx + PHP-FPM + MariaDB + cron)، راهنمای docs/DOCKER.md و بخش داکر در README'
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
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
