#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed109 - Happ link UI

app/Bot/Bot.php : service-card button + happView() + router case 'svchapp'
miniapp/api.php : action 'svc_links'
"""
import io, json, os, re, subprocess, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed109").strip() or "fixed109"

CACHE = {}
NEW = {}
ERRORS = []
WARN = []


def load(path):
    if path in NEW:
        return NEW[path]
    if path not in CACHE:
        with io.open(os.path.join(ROOT, path), encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep_rx(path, pattern, fn, marker=None, expect=1, optional=False, flags=re.M):
    try:
        s = load(path)
    except Exception as e:
        (WARN if optional else ERRORS).append("%s: missing (%s)" % (path, e))
        return
    if marker and marker in s:
        print("skip (already applied): %s / %s" % (path, marker))
        return
    n = len(re.findall(pattern, s, flags))
    if n != expect:
        (WARN if optional else ERRORS).append(
            "%s: regex for %s matched %d times (want %d)" % (path, marker or pattern[:44], n, expect)
        )
        return
    NEW[path] = re.sub(pattern, fn, s, count=0, flags=flags)
    print("patched %s by regex (%s)" % (path, marker or pattern[:44]))


def write_all():
    if ERRORS:
        print("ABORTED - anchors not found:")
        for e in ERRORS:
            print("  - " + e)
        sys.exit(1)
    if WARN:
        print("warnings (optional patches skipped):")
        for w in WARN:
            print("  - " + w)
    tmp = os.environ.get("TMPDIR", "/tmp")
    linted = False
    for path, content in NEW.items():
        data = content.encode("utf-8")
        if path.endswith(".php"):
            probe = os.path.join(tmp, "syntax-check.php")
            with open(probe, "wb") as fh:
                fh.write(data)
            r = subprocess.run(["php", "-l", probe], capture_output=True, text=True)
            linted = True
            if r.returncode != 0:
                print("php lint FAILED for %s:" % path)
                print(r.stdout + r.stderr)
                sys.exit(1)
        with open(os.path.join(ROOT, path), "wb") as fh:
            fh.write(data)
        print("wrote " + path)
    print("php lint: " + ("on" if linted else "off"))
    print("changed files: %d" % len(NEW))


HAPP_BTN = r'''        /* 0.0.2 #happ-btn: لینک اختصاصی Happ — فقط پنل نسل جدید سنایی */
        if (class_exists('Links') && Links::supported($s)) {
            $rows[] = [Tg::btn('⚡ افزودن به Happ', 'svchapp:' . $id)];
        }
'''

HAPP_VIEW = r'''    /* ============ 0.0.2 #happ-links: لینک Happ و لینک‌های خارجی ============ */

    private static function happView($chatId, $msgId, int $id): void
    {
        $s = self::myService($id);
        if (!$s) { Tg::send($chatId, '⚠️ سرویس یافت نشد.'); return; }
        if (!class_exists('Links') || !Links::supported($s)) {
            Tg::send($chatId, 'ℹ️ این سرویس لینک Happ ندارد.');
            return;
        }

        $happ = Links::happ($s);
        $ext  = Links::external($s);

        $txt = "⚡ <b>افزودن به Happ</b>\n"
            . '<code>─────────────────</code>' . "\n";
        if ($happ !== '') {
            $txt .= "لینک زیر را کپی کنید و در اپلیکیشن Happ بزنید روی «+» ← Import from clipboard:\n\n"
                . '<code>' . h($happ) . '</code>' . "\n";
        } else {
            $txt .= "لینک Happ برای این سرویس در دسترس نیست.\n";
        }
        if ($ext) {
            $txt .= "\n🔗 <b>لینک‌های دیگر</b>\n";
            $n = 0;
            foreach ($ext as $e) {
                $link = trim((string)($e['link'] ?? ''));
                if ($link === '') continue;
                $title = trim((string)($e['title'] ?? ''));
                $txt .= '• ' . ($title !== '' ? h($title) . ': ' : '') . '<code>' . h($link) . '</code>' . "\n";
                if (++$n >= 5) break;
            }
        }

        $rows = [
            [Tg::btn('🔄 به‌روزرسانی', 'svchapp:' . $id)],
            [Tg::btn('⬅️ بازگشت', 'svc:' . $id)],
        ];
        $msgId ? Tg::edit($chatId, $msgId, $txt, Tg::ikb($rows)) : Tg::send($chatId, $txt, Tg::ikb($rows));
    }

'''

MA_LINKS = r'''    /* 0.0.2 #happ-links: لینک Happ و لینک‌های خارجی سرویس */
    case 'svc_links': {
        $sid = (int)($in['id'] ?? 0);
        $s   = DB::one('SELECT * FROM {p}services WHERE id = :i AND user_id = :u AND status <> :d',
            [':i' => $sid, ':u' => $UID, ':d' => 'deleted']);
        if (!$s) ma_fail('سرویس یافت نشد.');
        if (!class_exists('Links') || !Links::supported($s)) {
            ma_out(['ok' => true, 'supported' => false, 'happ' => '', 'items' => []]);
        }
        ma_out([
            'ok'        => true,
            'supported' => true,
            'happ'      => Links::happ($s),
            'items'     => Links::external($s),
        ]);
    }

'''

# 1) service card button (before the devices block)
rep_rx(
    "app/Bot/Bot.php",
    r"^        /\* 0\.0\.2 #dev-btn",
    lambda m: HAPP_BTN + m.group(0),
    marker="0.0.2 #happ-btn",
)

# 2) the view itself (before devicesView)
rep_rx(
    "app/Bot/Bot.php",
    r"^    private static function devicesView\(",
    lambda m: HAPP_VIEW + m.group(0),
    marker="private static function happView",
)

# 3) router case
rep_rx(
    "app/Bot/Bot.php",
    r"^([ \t]*)case 'svcdel':",
    lambda m: m.group(1) + "case 'svchapp': Tg::answerCb($cbId); self::happView($chatId, $msgId, (int)$arg); return;\n" + m.group(0),
    marker="case 'svchapp':",
)

# 4) mini-app action
rep_rx(
    "miniapp/api.php",
    r"^    case 'svc_devices': \{$",
    lambda m: MA_LINKS + m.group(0),
    marker="'svc_links'",
)

# sanity
if "app/Bot/Bot.php" in NEW:
    s = NEW["app/Bot/Bot.php"]
    for needle in ("private static function happView", "case 'svchapp':", "private static function devicesView", "case 'svcdel':", "private static function sendSub"):
        if needle not in s:
            ERRORS.append("Bot.php lost: " + needle)
    if len(s) < 150000:
        ERRORS.append("Bot.php too short: %d" % len(s))
if "miniapp/api.php" in NEW:
    s = NEW["miniapp/api.php"]
    for needle in ("case 'svc_links':", "case 'svc_devices':", "case 'svc_dead':"):
        if needle not in s:
            ERRORS.append("miniapp/api.php lost: " + needle)
    if len(s) < 100000:
        ERRORS.append("miniapp/api.php too short: %d" % len(s))

write_all()

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)
v["build"] = BUILD
entry = "دکمهٔ «افزودن به Happ»: دریافت دیپ‌لینک اختصاصی Happ و لینک‌های خارجی اکانت از پنل 3x-ui در ربات و مینی‌اپ."
if isinstance(v.get("changelog"), list) and entry not in v["changelog"]:
    v["changelog"].insert(0, entry)
    print("changelog: entry added")
with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")
print("build: " + BUILD)
