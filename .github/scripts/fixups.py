#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Maintenance fixups for SR-BOT — idempotent, run from the repository root.

Fixes the panel client payload so Go-based panels (3x-ui / new-gen forks) never
receive a numeric row id where a string UUID is expected:
    json: cannot unmarshal number into Go struct field .id of type string
"""
import json
import os
import pathlib
import re

changed = []


def read(rel):
    p = pathlib.Path(rel)
    return p.read_text(encoding="utf-8") if p.is_file() else None


def write(rel, text):
    pathlib.Path(rel).write_text(text, encoding="utf-8")
    changed.append(rel)


HELPERS = """    /* ---------- هم‌سان‌سازی کلاینت پیش از ارسال به پنل ---------- */

    /** این مقدار شناسهٔ واقعی کلاینت (UUID/پسورد) است یا شمارهٔ ردیف پنل؟ */
    public static function isClientKey($v): bool
    {
        if (is_array($v) || is_bool($v) || $v === null) return false;
        $v = trim((string)$v);
        if ($v === '') return false;
        return !ctype_digit($v);
    }

    /** کلید کلاینت برای مسیر API پنل — اول uuid، بعد id، بعد password */
    public static function clientKey(array $client, string $fallback = ''): string
    {
        foreach (['uuid', 'id', 'password'] as $k) {
            if (self::isClientKey($client[$k] ?? null)) return trim((string)$client[$k]);
        }
        return trim($fallback);
    }

    /**
     * هم‌سان‌سازی نوع فیلدهای کلاینت.
     * پنل‌های Go برای id رشته می‌خواهند؛ اگر شمارهٔ ردیف پنل
     * به‌جای UUID ارسال شود خطای
     * «json: cannot unmarshal number into Go struct field .id of type string» می‌دهند.
     * حجم/انقضا هم باید عدد صحیح باشند، نه اعشاری یا رشته.
     */
    public static function normClient(array $c, string $key = ''): array
    {
        if (isset($c['id']) && !self::isClientKey($c['id'])) unset($c['id']);
        if (!isset($c['id']) && !isset($c['password']) && self::isClientKey($key)) $c['id'] = trim($key);
        foreach (['id', 'email', 'password', 'method', 'flow', 'security', 'subId', 'comment'] as $k) {
            if (isset($c[$k]) && !is_array($c[$k]) && !is_bool($c[$k])) $c[$k] = (string)$c[$k];
        }
        foreach (['totalGB', 'expiryTime', 'limitIp', 'reset', 'deviceLimit'] as $k) {
            if (isset($c[$k]) && !is_array($c[$k])) $c[$k] = (int)$c[$k];
        }
        if (isset($c['enable'])) $c['enable'] = (bool)$c['enable'];
        return $c;
    }

"""

# ---------------------------------------------------------------- app/Panel/Xui.php
xui = read("app/Panel/Xui.php")
if xui:
    orig = xui
    sig = "    public function addClient(int $inboundId, string $email, string $uuid, float $volumeGb"
    if "function normClient" not in xui and sig in xui:
        i = xui.index(sig)
        head = xui[:i]
        j = head.rfind("    /**")
        insert_at = j if (j != -1 and head[j:].count("*/") == 1) else i
        xui = xui[:insert_at] + HELPERS + xui[insert_at:]

    body_line = "        $body = ['id' => $inboundId, 'settings' => jenc(['clients' => [$client]])];"
    norm_line = "        $client = self::normClient($client, $uuid);"
    segs = xui.split(body_line)
    if len(segs) > 1:
        rebuilt = segs[0]
        for seg in segs[1:]:
            if not rebuilt.rstrip().endswith(norm_line.strip()):
                rebuilt += norm_line + "\n"
            rebuilt += body_line + seg
        xui = rebuilt

    xui = xui.replace(
        "return $this->call('update', ['uuid' => $uuid], self::formBody($body, $ids), 'POST');",
        "return $this->call('update', ['uuid' => self::clientKey($client, $uuid)], self::formBody($body, $ids), 'POST');",
    )
    xui = xui.replace(
        "return $this->call('update', ['uuid' => $uuid], $body, 'POST');",
        "return $this->call('update', ['uuid' => self::clientKey($client, $uuid)], $body, 'POST');",
    )
    xui = xui.replace(
        "if ($cl) $key = (string)($cl['id'] ?? ($cl['password'] ?? $uuid));",
        "if ($cl) $key = self::clientKey($cl, $uuid);",
    )
    if xui != orig:
        write("app/Panel/Xui.php", xui)

# --------------------------------------------------------------- app/Panel/Xui3.php
x3 = read("app/Panel/Xui3.php")
if x3:
    orig = x3
    x3 = x3.replace(
        "        $key = (string)($row['id'] ?? ($row['password'] ?? ''));",
        "        $key = Xui::clientKey($row, '');",
    )

    old_merge = (
        "        $cur    = $this->clientRow($email) ?? [];\n"
        "        $merged = self::cleanClient(array_merge($cur, $client));\n"
        "        $merged['email'] = $email;"
    )
    new_merge = (
        "        $cur    = $this->clientRow($email) ?? [];\n"
        "        $full   = array_merge($cur, $client);\n"
        "        $merged = self::cleanClient($full);\n"
        "        $merged['email'] = $email;\n"
        "        /* شمارهٔ ردیف پنل حذف و UUID واقعی جای آن می‌نشیند */\n"
        "        $merged = Xui::normClient($merged, Xui::clientKey($full, $uuid));"
    )
    if new_merge not in x3:
        x3 = x3.replace(old_merge, new_merge, 1)

    old_clean = "        foreach ($drop as $k) unset($c[$k]);\n        return $c;"
    new_clean = (
        "        foreach ($drop as $k) unset($c[$k]);\n"
        "        /* شمارهٔ ردیف پنل هرگز به‌جای UUID ارسال نشود */\n"
        "        if (isset($c['id']) && !Xui::isClientKey($c['id'])) unset($c['id']);\n"
        "        return $c;"
    )
    if new_clean not in x3:
        x3 = x3.replace(old_clean, new_clean, 1)

    if x3 != orig:
        write("app/Panel/Xui3.php", x3)

# --------------------------------------------- client key derivation in service layer
CAST_RE = re.compile(r"\(string\)\(\$client\['id'\] \?\? \(\$client\['password'\] \?\? (.*)\)\);")
RAW_RE = re.compile(r"\$client\['id'\] \?\? \(\$client\['password'\] \?\? (.*)\);")


def fix_keys(src):
    out = []
    for line in src.split("\n"):
        if "$client['id'] ?? ($client['password'] ??" in line and "clientKey" not in line:
            m = CAST_RE.search(line) or RAW_RE.search(line)
            if m:
                line = line[: m.start()] + "Xui::clientKey($client, (string)(" + m.group(1) + "));"
        out.append(line)
    return "\n".join(out)


for rel in (
    "app/Service/Svc.php",
    "app/Service/Reseller.php",
    "app/Service/AutoRenew.php",
    "app/Service/Bulk.php",
    "app/Service/Stock.php",
):
    src = read(rel)
    if not src:
        continue
    fixed = fix_keys(src)
    if fixed != src:
        write(rel, fixed)

# ------------------------------------------------------------------- version.json
ENTRY = (
    "🧩 رفع خطای «json: cannot unmarshal number into Go struct field .id of type string» "
    "در تمدید/ویرایش سرویس: شمارهٔ ردیف پنل دیگر به‌جای UUID فرستاده نمی‌شود "
    "و نوع فیلدهای حجم/انقضا/فعال‌بودن پیش از ارسال هم‌سان می‌شود"
)

vp = pathlib.Path("version.json")
if vp.is_file():
    raw = vp.read_text(encoding="utf-8")
    new = raw
    min_php = os.environ.get("MIN_PHP") or "8.1"
    build = os.environ.get("NEW_BUILD") or "fixed82"
    new = re.sub(r'"min_php"\s*:\s*"[^"]*"', '"min_php": "%s"' % min_php, new, count=1)
    new = re.sub(r'"build"\s*:\s*"[^"]*"', '"build": "%s"' % build, new, count=1)
    if ENTRY not in new:
        m = re.search(r'"changelog"\s*:\s*\[\s*\n(\s*)', new)
        if m:
            new = new[: m.end()] + json.dumps(ENTRY, ensure_ascii=False) + ",\n" + m.group(1) + new[m.end():]
    if new != raw:
        try:
            json.loads(new)
        except Exception as exc:  # pragma: no cover
            raise SystemExit("version.json patch produced invalid JSON: %s" % exc)
        write("version.json", new)

print("changed files:", len(changed))
for c in changed:
    print(" -", c)
