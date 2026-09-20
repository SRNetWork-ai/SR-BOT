#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed101 - mini-app endpoints for the new HWID device manager

  svc_devices        list the devices registered for one service
  svc_device_del     free one device slot
  svc_devices_clear  drop every registered device

Also dumps the bot's callback router so the next batch can add the
"my devices" button to the service screen.
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed101").strip() or "fixed101"

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


# ======================================================= mini-app actions
MA = "miniapp/api.php"

CASES = '''    /* 0.0.2: مدیریت دستگاه‌های ثبت‌شده (HWID) — فقط پنل نسل جدید سنایی */
    case 'svc_devices': {
        $sid = (int)($in['id'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Devices') || !Devices::supported($s)) {
            ma_out(['ok' => true, 'supported' => false, 'count' => 0, 'items' => [], 'limit' => 0]);
        }
        $items = Devices::listFor($s);
        ma_out([
            'ok'        => true,
            'supported' => true,
            'count'     => count($items),
            'items'     => $items,
            'limit'     => Devices::limitOf($s),
        ]);
    }

    case 'svc_device_del': {
        $sid = (int)($in['id'] ?? 0);
        $dev = (int)($in['device'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Devices') || !Devices::supported($s)) ma_fail('این پنل از مدیریت دستگاه پشتیبانی نمی‌کند.');
        if ($dev <= 0) ma_fail('دستگاه نامعتبر است.');
        if (!Devices::remove($s, $dev)) ma_fail('حذف دستگاه انجام نشد.');
        $items = Devices::listFor($s);
        ma_out([
            'ok'      => true,
            'message' => 'دستگاه حذف شد.',
            'count'   => count($items),
            'items'   => $items,
            'limit'   => Devices::limitOf($s),
        ]);
    }

    case 'svc_devices_clear': {
        $sid = (int)($in['id'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Devices') || !Devices::supported($s)) ma_fail('این پنل از مدیریت دستگاه پشتیبانی نمی‌کند.');
        $n = Devices::clear($s);
        ma_out([
            'ok'      => true,
            'message' => $n > 0 ? ('همهٔ دستگاه‌ها حذف شدند (' . fa_num((string)$n) . ').') : 'دستگاهی برای حذف نبود.',
            'removed' => $n,
            'count'   => 0,
            'items'   => [],
            'limit'   => Devices::limitOf($s),
        ]);
    }

'''


def _ma(m):
    return CASES + m.group(0)


rep_rx(MA, r"^    case 'svc_dead': \{$", _ma, marker="'svc_devices'")

# ==================================================== sanity + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if MA in NEW:
    t = CACHE[MA]
    if len(t) < 120000 or "case 'svc_dead'" not in t:
        print("ABORTED - sanity check failed for %s (%d chars)" % (MA, len(t)))
        sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ================================================ recon for the next batch
dump("BOT ROUTER", "app/Bot/Bot.php", 1020, 1070)

# ================================================================ version
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
