#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed102 - bot UI for the HWID device manager

  1) button on the service screen  (svcdev:<id>)
  2) devicesView / deviceDel / deviceClear helpers
  3) callback routes (svcdev, svcdevdel, svcdevclr)
"""
import io, json, os, re, shutil, subprocess, sys, tempfile

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed102").strip() or "fixed102"

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


BOT = "app/Bot/Bot.php"

# ------------------------------------------------ 1) button on the service card
DEV_BTN = """        /* 0.0.2 #dev-btn: مدیریت دستگاه‌های ثبت‌شده (HWID) — فقط پنل نسل جدید سنایی */
        if (class_exists('Devices') && Devices::supported($s)) {
            $rows[] = [Tg::btn('\U0001f4f1 دستگاه‌های من', 'svcdev:' . $id)];
        }
"""


def _btn(m):
    return m.group(0) + DEV_BTN


rep_rx(
    BOT,
    r"\$rows = \[\n            \$top,\n[^\n]*'svcspec:' \. \$id\)[^\n]*\n[^\n]*'svcrn:' \. \$id\)\],\n        \];\n",
    _btn,
    marker="0.0.2 #dev-btn",
)

# ------------------------------------------------------------- 2) the screens
METHODS = """    /* ============ 0.0.2: دستگاه‌های ثبت‌شده (HWID) — پنل 3x-ui ============ */

    private static function devicesView($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '⚠️ سرویس یافت نشد.'); return; }
        if (!class_exists('Devices') || !Devices::supported($s)) {
            Tg::send($chatId, 'ℹ️ این سرویس از مدیریت دستگاه پشتیبانی نمی‌کند.');
            return;
        }

        $items = Devices::listFor($s);
        $limit = Devices::limitOf($s);

        $txt = "\U0001f4f1 <b>دستگاه‌های من</b>\n"
            . '<code>─────────────────</code>' . "\n"
            . '\U0001f464 <code>' . h((string)$s['client_email']) . "</code>\n"
            . '\U0001f522 ثبت‌شده: ' . fa_num((string)count($items))
            . ($limit > 0 ? (' از ' . fa_num((string)$limit)) : ' (بدون محدودیت)') . "\n";

        $rows = [];
        if (!$items) {
            $txt .= "\nهنوز دستگاهی ثبت نشده است.";
        } else {
            $txt .= "\nبرای آزاد کردن ظرفیت، روی دستگاه بزنید:";
            foreach ($items as $d) {
                $label = mb_substr((string)$d['title'], 0, 26);
                $rows[] = [Tg::btn('\U0001f5d1 ' . $label . ' | ' . (string)$d['seen_txt'],
                    'svcdevdel:' . $id . ':' . (int)$d['id'])];
            }
            $rows[] = [Tg::btn('\U0001f9f9 حذف همهٔ دستگاه‌ها', 'svcdevclr:' . $id)];
        }
        $rows[] = [Tg::btn('\U0001f504 به‌روزرسانی', 'svcdev:' . $id)];
        $rows[] = [Tg::btn('⬅️ بازگشت', 'svc:' . $id)];

        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

    private static function deviceDel($chatId, $msgId, $cbId, int $id, int $dev): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        if (!class_exists('Devices') || !Devices::supported($s) || $dev <= 0) {
            Tg::answerCb($cbId, 'امکان حذف نیست.', true);
            return;
        }
        $ok = Devices::remove($s, $dev);
        Tg::answerCb($cbId, $ok ? '✅ دستگاه حذف شد.' : '❌ حذف انجام نشد.', !$ok);
        self::devicesView($chatId, $msgId, $id);
    }

    private static function deviceClear($chatId, $msgId, $cbId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::answerCb($cbId, 'سرویس یافت نشد.', true); return; }
        if (!class_exists('Devices') || !Devices::supported($s)) {
            Tg::answerCb($cbId, 'امکان حذف نیست.', true);
            return;
        }
        $n = Devices::clear($s);
        Tg::answerCb($cbId, $n > 0
            ? ('✅ ' . fa_num((string)$n) . ' دستگاه حذف شد.')
            : 'دستگاهی برای حذف نبود.');
        self::devicesView($chatId, $msgId, $id);
    }

"""


def _methods(m):
    return METHODS + m.group(0)


rep_rx(
    BOT,
    r"^    private static function sendSub\(\$chatId, \$cbId, int \$id\): void$",
    _methods,
    marker="private static function devicesView",
)

# ------------------------------------------------------------- 3) the routes
ROUTES = ("            case 'svcdev':     Tg::answerCb($cbId); self::devicesView($chatId, $msgId, (int)$arg); return;\n"
          "            case 'svcdevdel':  self::deviceDel($chatId, $msgId, $cbId, (int)$arg, (int)$arg2); return;\n"
          "            case 'svcdevclr':  self::deviceClear($chatId, $msgId, $cbId, (int)$arg); return;\n")


def _routes(m):
    return ROUTES + m.group(0)


rep_rx(
    BOT,
    r"^            case 'svcdel':     Tg::answerCb\(\$cbId\); self::delOptions\(\$chatId, \$msgId, \(int\)\$arg\); return;$",
    _routes,
    marker="case 'svcdev':",
)

# ------------------------------------------------------------ sanity + write
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

if BOT in NEW:
    t = CACHE[BOT]
    for needle in ("private static function showService", "private static function myService",
                   "case 'svcdel':", "case 'svcdevdel':"):
        if needle not in t:
            print("ABORTED - sanity check failed for %s (%s)" % (BOT, needle))
            sys.exit(1)
    if len(t) < 150000:
        print("ABORTED - %s shrank unexpectedly (%d chars)" % (BOT, len(t)))
        sys.exit(1)

write_all()

if WARN:
    print("warnings (optional patches skipped):")
    for w in WARN:
        print("  - " + w)

# ---------------------------------------------------------------- version.json
VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
entry = ("\u062f\u0633\u062a\u06af\u0627\u0647\u200c\u0647\u0627\u06cc \u0645\u0646: "
         "\u0645\u0634\u0627\u0647\u062f\u0647 \u0648 \u062d\u0630\u0641 \u062f\u0633\u062a\u06af\u0627\u0647\u200c\u0647\u0627\u06cc "
         "\u062b\u0628\u062a\u200c\u0634\u062f\u0647 (HWID) \u062f\u0631 \u0631\u0628\u0627\u062a \u0648 \u0645\u06cc\u0646\u06cc\u200c\u0627\u067e "
         "\u0628\u0631\u0627\u06cc \u067e\u0646\u0644\u200c\u0647\u0627\u06cc 3x-ui.")
cl = v.get("changelog")
if isinstance(cl, list) and all(isinstance(x, str) for x in cl) and entry not in cl:
    cl.insert(0, entry)
    v["changelog"] = cl
    print("changelog: entry added")
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: %d" % len(NEW))
for p in sorted(NEW):
    print("  - " + p)
