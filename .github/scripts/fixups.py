#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed104 - health insights from the new 3x-ui endpoints

  clientsSummary()  -> total / online / expiring / depleted / deactive
  ipLimitStatus()   -> fail2ban usable + enabled

Also dumps the new Xui3 methods and the admin panels page hooks so the next
batch can add maintenance buttons (orphan cleanup, bulk traffic reset).
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed104").strip() or "fixed104"

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    bag = WARN if optional else ERRORS
    try:
        s = load(path)
    except Exception as e:
        bag.append("%s: %s" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    rx = re.compile(pattern, flags)
    hits = rx.findall(s)
    if len(hits) != expect:
        bag.append("%s: regex for %s matched %d times (want %d)"
                   % (path, marker or pattern[:40], len(hits), expect))
        return
    CACHE[path] = rx.sub(fn, s, count=expect)
    NEW[path] = True
    print("patched %s by regex (%s)" % (path, marker or "-"))


def dump(tag, path, start, end):
    try:
        lines = load(path).splitlines()
    except Exception as e:
        print("== %s == missing: %s" % (tag, e))
        return
    print("== %s (%s lines %d-%d of %d) ==" % (tag, path, start, end, len(lines)))
    for i in range(start, min(end, len(lines)) + 1):
        raw = lines[i - 1]
        if len(raw) > 260:
            raw = raw[:260] + " ...TRUNC"
        print("  %d|%s" % (i, raw))


def write_all():
    php = shutil.which("php")
    blobs = {}
    for path in sorted(NEW):
        data = CACHE[path].encode("utf-8")
        if path.endswith(".php") and php:
            tmp = os.path.join(tempfile.gettempdir(), "syntax-check.php")
            with open(tmp, "wb") as fh:
                fh.write(data)
            r = subprocess.run([php, "-l", tmp], capture_output=True, text=True)
            if r.returncode != 0:
                print("php syntax error in %s:" % path)
                print("  " + (r.stdout + r.stderr).strip()[:400])
                sys.exit(1)
        blobs[path] = data
    for path, data in blobs.items():
        with open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
    print("php lint: " + ("on" if php else "php not installed - skipped"))


HEALTH = "app/Service/Health.php"

BLOCK = """        /* 0.0.2 #x3-health: آمار زندهٔ پنل‌های نسل جدید سنایی (3x-ui) */
        if ($live && class_exists('Xui3')) {
            $x3seen = 0;
            foreach ((DB::all('SELECT * FROM {p}panels WHERE active = 1 ORDER BY id') ?: []) as $x3p) {
                if ($x3seen >= 3) break;
                $x3type = class_exists('Xui') ? Xui::normType((string)($x3p['type'] ?? '')) : '';
                if ($x3type !== 'sanaei' || !Xui3::hasToken($x3p)) continue;
                $x3seen++;
                $x3name = trim((string)($x3p['name'] ?? '')) !== ''
                    ? (string)$x3p['name']
                    : ('#' . (int)($x3p['id'] ?? 0));
                try {
                    $x3drv = new Xui3($x3p);
                    $x3sum = $x3drv->clientsSummary();
                    if ($x3sum) {
                        $out[] = self::it(
                            'کاربران ' . $x3name,
                            'کل ' . fa_num((string)(int)($x3sum['total'] ?? 0))
                                . ' • آنلاین ' . fa_num((string)(int)($x3sum['online'] ?? 0)),
                            'ok',
                            'رو به انقضا: ' . fa_num((string)(int)($x3sum['expiring'] ?? 0))
                                . ' | اتمام حجم: ' . fa_num((string)(int)($x3sum['depleted'] ?? 0))
                                . ' | غیرفعال: ' . fa_num((string)(int)($x3sum['deactive'] ?? 0))
                        );
                    }
                    $x3ip = $x3drv->ipLimitStatus();
                    if ($x3ip) {
                        $x3on = !empty($x3ip['usable']) && !empty($x3ip['enabled']);
                        $out[] = self::it(
                            'محدودیت IP ' . $x3name,
                            $x3on ? 'فعال' : (!empty($x3ip['installed']) ? 'نصب است ولی خاموش' : 'نصب نیست'),
                            $x3on ? 'ok' : 'warn',
                            'برای جلوگیری از اشتراک‌گذاری کانفیگ، Fail2ban را در پنل روشن کنید.'
                        );
                    }
                } catch (Throwable $x3e) {
                    $out[] = self::it('پنل ' . $x3name, 'بررسی نشد', 'warn', $x3e->getMessage());
                }
            }
        }

"""


def _health(m):
    return BLOCK + m.group(0)


rep_rx(
    HEALTH,
    r"^\s*\$rows\s*=\s*DB::all\('SELECT name, last_error, user_limit, users_created FROM \{p\}panels",
    _health,
    marker="0.0.2 #x3-health",
)

# ------------------------------------------------------------ sanity + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if HEALTH in NEW:
    t = CACHE[HEALTH]
    for needle in ("public static function panels", "public static function all", "private static function it"):
        if needle not in t:
            print("ABORTED - sanity check failed for %s (%s)" % (HEALTH, needle))
            sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# -------------------------------------------------------- recon for next batch
dump("XUI3 NEW METHODS", "app/Panel/Xui3.php", 707, 760)
dump("XUI3 NEW METHODS 2", "app/Panel/Xui3.php", 761, 826)
dump("ADMIN PANELS ACT", "admin/pages/panels.php", 150, 172)
dump("ADMIN PANELS UI", "admin/pages/panels.php", 405, 436)

# ---------------------------------------------------------------- version.json
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(NEW))
for p in sorted(NEW):
    print("  - " + p)
