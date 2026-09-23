# -*- coding: utf-8 -*-
# fixed137 - three user-reported bugs:
#   1) product deliver mode "sub only" still shipped direct configs (bot + mini app)
#   2) config list showed more links than the panel really has (stale/duplicate rows)
#   3) HWID never triggered: bot handed out its own sub link instead of the panel
#      sub / dedicated Happ link, so the panel could not register the device
import io, os, re, sys, json, subprocess

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed137').strip() or 'fixed137'

CACHE = {}
NEW = set()
ERRORS = []
WARN = []


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        CACHE[path] = fh.read()
    return CACHE[path]


def rep_rx(path, pattern, fn, marker, expect=1, optional=False, flags=re.M):
    try:
        src = load(path)
    except Exception as e:
        msg = '%s: cannot read (%s)' % (path, e)
        (WARN if optional else ERRORS).append(msg)
        print(('warn: ' if optional else 'ERROR: ') + msg)
        return
    if marker in src:
        print('skip (already applied): %s / %s' % (path, marker))
        return
    rx = re.compile(pattern, flags)
    n = len(rx.findall(src))
    if n != expect:
        msg = '%s: regex for %s matched %d times (want %d)' % (path, marker, n, expect)
        if optional:
            WARN.append(msg)
            print('warn: ' + msg)
            return
        ERRORS.append(msg)
        print('ERROR: ' + msg)
        return
    CACHE[path] = rx.sub(fn, src, count=expect)
    NEW.add(path)
    print('patched: %s / %s (%d)' % (path, marker, expect))


def write_all():
    if ERRORS:
        print('ABORTED - anchors not found:')
        for e in ERRORS:
            print(' - ' + e)
        sys.exit(1)
    for p in sorted(NEW):
        with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
            fh.write(CACHE[p])
        print('wrote ' + p)
    php = None
    try:
        r = subprocess.run(['php', '-v'], capture_output=True)
        if r.returncode == 0:
            php = 'php'
    except Exception:
        php = None
    print('php lint: %s' % ('on' if php else 'n/a'))
    if php:
        for p in sorted(NEW):
            if not p.endswith('.php'):
                continue
            r = subprocess.run([php, '-l', os.path.join(ROOT, p)], capture_output=True)
            if r.returncode != 0:
                print('PHP LINT FAILED: ' + p)
                print(r.stdout.decode('utf-8', 'replace'))
                print(r.stderr.decode('utf-8', 'replace'))
                sys.exit(1)
    print('changed files: %d' % len(NEW))
    if WARN:
        print('warnings (optional patches skipped):')
        for w in WARN:
            print(' - ' + w)


SVC = 'app/Service/Svc.php'
LNK = 'app/Service/Links.php'
BOT = 'app/Bot/Bot.php'
API = 'miniapp/api.php'
PRD = 'admin/pages/products.php'

# ============================================================================
# BUG 2 - duplicate / stale configs
# ============================================================================

# 2.1 storedConfigs(): dedupe by identity (display name after # is ignored)
rep_rx(
    SVC,
    r"\$out\[\] = \$l;\n(\s*)\}\n(\s*)return array_values\(array_unique\(\$out\)\);",
    lambda m: "$out[] = $l;\n" + m.group(1) + "}\n" + m.group(2) + "return self::dedupeLinks($out); /* 0.0.2 #cfg-dedupe-stored */",
    '#cfg-dedupe-stored',
)

SVC_HELPERS = """    /* ================= 0.0.2 #cfg-dedupe-helpers ================= */

    /** شناسهٔ یکتای یک کانفیگ؛ نام نمایشی (بخش بعد از #) نادیده گرفته می‌شود */
    public static function linkIdent(string $l): string
    {
        $l = trim($l);
        if ($l === '') return '';
        if (stripos($l, 'vmess://') === 0) {
            $raw = (string)base64_decode(substr($l, 8));
            $j   = json_decode($raw, true);
            if (is_array($j)) {
                unset($j['ps'], $j['remark'], $j['name']);
                ksort($j);
                return 'vmess|' . (string)json_encode($j);
            }
        }
        $p = strpos($l, '#');
        return strtolower($p === false ? $l : substr($l, 0, $p));
    }

    /** حذف کانفیگ‌های تکراری (حتی اگر فقط نامشان فرق داشته باشد) */
    public static function dedupeLinks(array $links): array
    {
        $out  = [];
        $seen = [];
        foreach ($links as $l) {
            $l = trim((string)$l);
            if ($l === '') continue;
            $k = self::linkIdent($l);
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $l;
        }
        return array_values($out);
    }

    /**
     * فهرست رسمی لینک‌های همین اکانت روی پنل نسل جدید سنایی.
     * خروجی خالی یعنی پنل قدیمی است یا در دسترس نیست (آن وقت از ردیف دیتابیس خوانده می‌شود).
     */
    public static function panelConfigs(array $s): array
    {
        $email = trim((string)($s['client_email'] ?? ''));
        if ($email === '' || !class_exists('Xui')) return [];
        try {
            $x = Xui::forPanel((int)($s['panel_id'] ?? 0));
            if (!$x || !method_exists($x, 'isXui3') || !$x->isXui3()) return [];
            $d = method_exists($x, 'xui3') ? $x->xui3() : null;
            if (!is_object($d) || !method_exists($d, 'linksFor')) return [];
            $links = self::dedupeLinks((array)$d->linksFor($email));
            if (!$links) return [];
            $pn = (string)DB::val('SELECT name FROM {p}panels WHERE id = :i', [':i' => (int)($s['panel_id'] ?? 0)], '');
            return self::renameLinks($links, self::configLabel($s, $pn));
        } catch (Throwable $e) {
            return [];
        }
    }

    /* ================= 0.0.2 #hwid-helpers ================= */

    /** محدودیت دستگاه (HWID) محصولِ این سرویس؛ صفر یعنی بدون محدودیت */
    public static function deviceLimitOf(array $s): int
    {
        $pid = (int)($s['product_id'] ?? 0);
        if ($pid <= 0) return 0;
        try {
            return (int)DB::val('SELECT device_limit FROM {p}products WHERE id = :id', [':id' => $pid], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** آیا این سرویس محدودیت هاردویر دارد؟ (فقط روی پنل نسل جدید معنا دارد) */
    public static function hwidOn(array $s): bool
    {
        if (self::deviceLimitOf($s) <= 0) return false;
        if (!class_exists('Links')) return false;
        try {
            return Links::supported($s);
        } catch (Throwable $e) {
            return false;
        }
    }"""

# 2.2 helper block right after storedConfigs()
rep_rx(
    SVC,
    r"(?m)^(\s*)return self::dedupeLinks\(\$out\); /\* 0\.0\.2 #cfg-dedupe-stored \*/\n(\s*)\}",
    lambda m: m.group(0) + "\n\n" + SVC_HELPERS,
    '#cfg-dedupe-helpers',
)

X3_FIRST = """
{IND}/* 0.0.2 #cfg-x3-first: روی پنل نسل جدید، فهرست رسمی خودِ پنل ملاک است */
{IND}$x3ok = false;
{IND}if (method_exists($xui, 'isXui3') && method_exists($xui, 'xui3')) {
{IND}    try {
{IND}        if ($xui->isXui3()) {
{IND}            $d3 = $xui->xui3();
{IND}            if (is_object($d3) && method_exists($d3, 'linksFor')) {
{IND}                $tmp = (array)$d3->linksFor((string)($s['client_email'] ?? ''));
{IND}                if ($tmp) { $links = $tmp; $x3ok = true; }
{IND}            }
{IND}        }
{IND}    } catch (Throwable $e) {
{IND}        $x3ok = false;
{IND}    }
{IND}}"""

# 2.3 liveConfigs(): ask the new-gen panel first
rep_rx(
    SVC,
    r"(?m)^(\s*)\$own\s*=\s*trim\(\(string\)\(\$s\['sub_link'\] \?\? ''\)\);",
    lambda m: m.group(0) + X3_FIRST.replace('{IND}', m.group(1)),
    '#cfg-x3-first',
)

# 2.4 the old sub-body fetch only runs when the panel list was empty
rep_rx(
    SVC,
    r"if \(method_exists\(\$xui, 'subFetch'\)\) \{",
    lambda m: "if (!$links && method_exists($xui, 'subFetch')) { /* 0.0.2 #cfg-x3-sub */",
    '#cfg-x3-sub',
)

# 2.5 links that came straight from the panel must not be filtered again
rep_rx(
    SVC,
    r"if \(\$links\) \$links = self::ownLinks\(\$s, \$links\);",
    lambda m: "if ($links && empty($x3ok)) $links = self::ownLinks($s, $links); /* 0.0.2 #cfg-x3-own */",
    '#cfg-x3-own',
)

# 2.6 liveConfigs(): dedupe before renaming
rep_rx(
    SVC,
    r"return self::renameLinks\(\$links, self::configLabel\(\$s, \(string\)\(\$panel\['name'\] \?\? ''\)\)\);",
    lambda m: "return self::renameLinks(self::dedupeLinks($links), self::configLabel($s, (string)($panel['name'] ?? ''))); /* 0.0.2 #cfg-dedupe-live */",
    '#cfg-dedupe-live',
)

CFG_LIVE_FIRST = """
{IND}    /* 0.0.2 #cfg-live-first: روی پنل نسل جدید، فهرست رسمی پنل ملاک است تا کانفیگ قدیمی یا تکراری نمانَد */
{IND}    try {
{IND}        if (class_exists('Svc') && method_exists('Svc', 'panelConfigs')) {
{IND}            $pl = Svc::panelConfigs($service);
{IND}            if ($pl) return $pl;
{IND}        }
{IND}    } catch (Throwable $e) {
{IND}    }
"""

# 2.7 Bot::cfgList() prefers the panel list
rep_rx(
    BOT,
    r"(?m)^(\s*)public static function cfgList\(array \$service\): array\n\1\{",
    lambda m: m.group(0) + CFG_LIVE_FIRST.replace('{IND}', m.group(1)).rstrip('\n'),
    '#cfg-live-first',
)

# ============================================================================
# BUG 1 - deliver mode was not enforced everywhere
# ============================================================================

# 1.1 sub message: config button only when the product allows direct configs
rep_rx(
    BOT,
    r"(?m)^(\s*)\[Tg::btn\('([^']*)', 'svccfg:' \. \$id\)\],",
    lambda m: m.group(1) + "...(Svc::wantsCfg(Svc::deliverMode($s)) ? [[Tg::btn('" + m.group(2) + "', 'svccfg:' . $id)]] : []), /* 0.0.2 #dm-sub-btn */",
    '#dm-sub-btn',
)

DM_CFG_GUARD = """{IND}/* 0.0.2 #dm-cfg-guard: محصولی که فقط لینک اشتراک می‌دهد، کانفیگ مستقیم نمی‌دهد */
{IND}$gC = self::myService($id);
{IND}if ($gC && !Svc::wantsCfg(Svc::deliverMode($gC))) { self::sendSub($chatId, $cbId, $id); return; }
"""

# 1.2 config button/command is refused for sub-only products
rep_rx(
    BOT,
    r"(?m)^(\s*)/\* 0\.0\.2 #happ-only-guard2",
    lambda m: DM_CFG_GUARD.replace('{IND}', m.group(1)) + m.group(0),
    '#dm-cfg-guard',
)

DM_API = """
{IND}/* 0.0.2 #dm-strict-api: حالت تحویل محصول روی مینی‌اپ هم اعمال می‌شود */
{IND}$__dmMode = class_exists('Svc') && method_exists('Svc', 'deliverMode') ? Svc::deliverMode($s) : 'both';
{IND}$__noSub  = $__happOnly || (class_exists('Svc') && method_exists('Svc', 'wantsSub') && !Svc::wantsSub($__dmMode));
{IND}$__noCfg  = $__happOnly || (class_exists('Svc') && method_exists('Svc', 'wantsCfg') && !Svc::wantsCfg($__dmMode));"""

# 1.3 mini app API: compute the mode flags
rep_rx(
    API,
    r"(?m)^(\s*)&& class_exists\('Links'\) && Links::supported\(\$s\);",
    lambda m: m.group(0) + DM_API.replace('{IND}', m.group(1)[:-4] if len(m.group(1)) >= 4 else m.group(1)),
    '#dm-strict-api',
)

# 1.4 mini app API: hide sub / configs according to the mode
rep_rx(
    API,
    r"\$__happOnly \? '' :",
    lambda m: "$__noSub ? '' :",
    "$__noSub ? '' :",
    expect=4,
)
rep_rx(
    API,
    r"\$__happOnly \? \[\] :",
    lambda m: "$__noCfg ? [] :",
    '$__noCfg ? [] :',
)

DM_FLAGS = """
{IND}/* 0.0.2 #dm-strict-flags */
{IND}'cfg_off'    => $__noCfg,
{IND}'sub_off'    => $__noSub,
{IND}'hwid'       => class_exists('Svc') && method_exists('Svc', 'hwidOn') ? Svc::hwidOn($s) : false,"""

rep_rx(
    API,
    r"(?m)^(\s*)&& class_exists\('Links'\) && Links::supported\(\$s\),",
    lambda m: m.group(0) + DM_FLAGS.replace('{IND}', m.group(1)[:-4] if len(m.group(1)) >= 4 else m.group(1)),
    '#dm-strict-flags',
)

# ============================================================================
# BUG 3 - HWID needs the panel sub / dedicated Happ link
# ============================================================================

LNK_FB = """    /**
     * ساب خودِ پنل برای این سرویس؛ ساب داخلی ربات کنار گذاشته می‌شود.
     * محدودیت هاردویر (HWID) فقط وقتی شمرده می‌شود که مشتری ساب خودِ پنل یا
     * لینک Happ را باز کند؛ ساب داخلی ربات از دید پنل دیده نمی‌شود.
     * 0.0.2 #happ-sub-fb
     */
    public static function panelSub(array $svc): string
    {
        $u = trim((string)($svc['sub_link'] ?? ''));
        if ($u === '') return '';
        if (class_exists('Svc') && method_exists('Svc', 'isOwnSub')) {
            try {
                if (Svc::isOwnSub($u)) return '';
            } catch (Throwable $e) {
            }
        }
        return $u;
    }

    /** اگر پنل لینک آمادهٔ Happ نداد، از روی ساب خودِ پنل ساخته می‌شود */
    public static function happFallback(array $svc): string
    {
        $d = self::driver($svc);
        if ($d && method_exists($d, 'externalLinks')) {
            try {
                foreach ((array)$d->externalLinks((string)($svc['client_email'] ?? '')) as $row) {
                    $l = trim((string)(is_array($row) ? ($row['link'] ?? '') : $row));
                    if ($l !== '' && stripos($l, 'happ://') === 0) return $l;
                }
            } catch (Throwable $e) {
            }
        }
        $u = self::panelSub($svc);
        if ($u === '') return '';
        return 'happ://add/' . rtrim(strtr(base64_encode($u), '+/', '-_'), '=');
    }

"""

# 3.1 Links: panel sub + Happ fallback helpers
rep_rx(
    LNK,
    r"(?m)^(\s*)public static function external\(array \$svc\): array",
    lambda m: LNK_FB + m.group(0),
    '#happ-sub-fb',
)

# 3.2 Links::happ() falls back instead of returning an empty string
rep_rx(
    LNK,
    r"if \(!\$d \|\| !method_exists\(\$d, 'happLink'\)\) return '';",
    lambda m: "if (!$d || !method_exists($d, 'happLink')) return self::happFallback($svc); /* 0.0.2 #happ-fb */",
    '#happ-fb ',
)
rep_rx(
    LNK,
    r"(?m)^(\s*)return trim\(\(string\)\$d->happLink\(\(string\)\$svc\['client_email'\]\)\);",
    lambda m: (m.group(1) + "$l = trim((string)$d->happLink((string)$svc['client_email']));\n"
              + m.group(1) + "return $l !== '' ? $l : self::happFallback($svc); /* 0.0.2 #happ-fb2 */"),
    '#happ-fb2',
)
rep_rx(
    LNK,
    r"(app_log\('panel', 'happ link failed'[^\n]*\n\s*\}\n\s*)return '';",
    lambda m: m.group(1) + "return self::happFallback($svc); /* 0.0.2 #happ-fb3 */",
    '#happ-fb3',
)

HWID_SUB = """
{IND}/* 0.0.2 #hwid-panel-sub: با محدودیت هاردویر باید ساب خودِ پنل تحویل شود، نه ساب داخلی ربات */
{IND}if ($mode !== 'panel' && self::hwidOn($s) && self::panelSub($s) !== '') $mode = 'panel';"""

# 3.3 Svc::subUrl() hands out the panel sub when HWID is on
rep_rx(
    SVC,
    r"(?m)^(\s*)\$mode\s*=\s*\$mode !== null \? \(string\)\$mode : self::subMode\(\);",
    lambda m: m.group(0) + HWID_SUB.replace('{IND}', m.group(1)),
    '#hwid-panel-sub',
)

HWID_EXTRA = """
{IND}/* 0.0.2 #hwid-happ-extra: محصول دارای محدودیت هاردویر همیشه لینک اختصاصی Happ هم می‌گیرد */
{IND}$hwid = class_exists('Svc') && method_exists('Svc', 'hwidOn') ? Svc::hwidOn($service) : false;
{IND}if ($happ === '' && $hwid) $happ = Svc::happLink($service);"""

# 3.4 delivery message: add the Happ link for HWID products
rep_rx(
    BOT,
    r"(?m)^(\s*)\$happ = Svc::wantsHapp\(\$mode\) \? Svc::happLink\(\$service\) : '';",
    lambda m: m.group(0) + HWID_EXTRA.replace('{IND}', m.group(1)),
    '#hwid-happ-extra',
)

# 3.5 only the happ-only mode may suppress sub/configs
rep_rx(
    BOT,
    r"if \(\$happ !== ''\) \{ \$wantSub = false; \$wantCfg = false; \}",
    lambda m: "if ($happ !== '' && Svc::wantsHapp($mode)) { $wantSub = false; $wantCfg = false; } /* 0.0.2 #hwid-happ-keep */",
    '#hwid-happ-keep',
)

# 3.6 explicit note so the customer knows which link enforces the device limit
rep_rx(
    BOT,
    r"(?m)^(\s*)\$txt \.= '<code>' \. h\(\$happ\) \. \"</code>\\n\";",
    lambda m: (m.group(0) + "\n" + m.group(1)
               + "if ($hwid) $txt .= \"<i>⚠️ برای شمارش دستگاه‌ها (HWID) حتماً همین لینک را در Happ وارد کنید؛ لینک اشتراک عادی محدودیت دستگاه را اعمال نمی‌کند.</i>\\n\"; /* 0.0.2 #hwid-note */"),
    '#hwid-note',
)

# 3.7 admin hint (optional)
rep_rx(
    PRD,
    r"(?m)^(\s*)<input class=\"mono\" type=\"number\" min=\"0\" name=\"device_limit\"([^\n]*)$",
    lambda m: (m.group(0) + "\n" + m.group(1)
               + "<div class=\"hint\">پنل باید نسل جدید سنایی (توکن API) باشد و در تب Happ گزینهٔ Enforce Hardware ID روشن باشد. با فعال‌بودن این محدودیت، ربات به‌جای ساب داخلی، ساب خودِ پنل و لینک Happ را تحویل می‌دهد. <!-- 0.0.2 #hwid-hint --></div>"),
    '#hwid-hint',
    optional=True,
)

write_all()

# ================================ SANITY ================================
SANITY = [
    (SVC, '#cfg-dedupe-stored'),
    (SVC, 'public static function dedupeLinks(array $links): array'),
    (SVC, 'public static function panelConfigs(array $s): array'),
    (SVC, 'public static function hwidOn(array $s): bool'),
    (SVC, '#cfg-x3-first'),
    (SVC, '#cfg-x3-sub'),
    (SVC, '#cfg-x3-own'),
    (SVC, '#cfg-dedupe-live'),
    (SVC, '#hwid-panel-sub'),
    (LNK, '#happ-sub-fb'),
    (LNK, "'happ://add/'"),
    (LNK, '#happ-fb2'),
    (LNK, '#happ-fb3'),
    (BOT, '#cfg-live-first'),
    (BOT, '#dm-sub-btn'),
    (BOT, '#dm-cfg-guard'),
    (BOT, '#hwid-happ-extra'),
    (BOT, '#hwid-happ-keep'),
    (BOT, '#hwid-note'),
    (API, '#dm-strict-api'),
    (API, '$__noCfg ? [] :'),
    (API, "'cfg_off'"),
]
for p, needle in SANITY:
    try:
        ok = needle in load(p)
    except Exception:
        ok = False
    print('sanity %s / %s : %s' % (p, needle, 'ok' if ok else 'MISSING'))

# ============================== version bump ==============================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
notes = [
    'رفع باگ: حالت تحویل محصول (فقط لینک اشتراک / فقط کانفیگ) اکنون در ربات و مینی‌اپ کامل رعایت می‌شود',
    'رفع باگ: حذف کانفیگ‌های تکراری و نمایش دقیقاً همان کانفیگ‌هایی که روی پنل ثبت است',
    'محدودیت هاردویر (HWID): تحویل ساب خودِ پنل و لینک اختصاصی Happ به‌جای ساب داخلی ربات',
]
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = (notes + cl)[:60]
    print('changelog: entry added')
else:
    print('changelog: skipped (no list)')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write('\n')
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
