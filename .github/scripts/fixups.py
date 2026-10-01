# -*- coding: utf-8 -*-
# fixed168 - stop swallowing button errors + add static integrity guards
import io, os, sys, json, re, urllib.request

ROOT = os.environ.get('SRC_ROOT') or os.getcwd()
BUILD = (os.environ.get('NEW_BUILD') or 'fixed168').strip() or 'fixed168'
CACHE = {}
ORIG = {}
NEW = []
ERRORS = []
WARN = []


def load(path):
    if path in CACHE:
        return CACHE[path]
    with io.open(os.path.join(ROOT, path), 'r', encoding='utf-8') as fh:
        s = fh.read()
    CACHE[path] = s
    ORIG[path] = s
    return s


def add_file(path, content):
    full = os.path.join(ROOT, path)
    if os.path.exists(full):
        print('skip (exists): %s' % path)
        return
    d = os.path.dirname(full)
    if d and not os.path.isdir(d):
        os.makedirs(d)
    CACHE[path] = content
    ORIG[path] = None
    NEW.append(path)
    print('NEW %s' % path)


def rep_lit(path, old, new, marker, optional=False):
    s = load(path)
    if marker in s:
        print('skip (already applied): %s' % marker)
        return
    n = s.count(old)
    if n != 1:
        msg = '%s: anchor for %s matched %d times (want 1)' % (path, marker, n)
        (WARN if optional else ERRORS).append(msg)
        print('MISS: %s' % msg)
        return
    CACHE[path] = s.replace(old, new, 1)
    print('patched: %s (%s)' % (path, marker))


def grep(path, needle, limit=12):
    s = load(path)
    hits = 0
    for i, ln in enumerate(s.split(chr(10))):
        if needle in ln:
            hits += 1
            if hits <= limit:
                print('  %5d| %s' % (i + 1, ln.strip()[:150]))
    print('---- grep %s in %s (%d) ----' % (needle, path, hits))


# ============================================================
# 1) do not swallow button handler errors any more
# ============================================================
old_catch = (
    "            } catch (Throwable $e) {\n"
    "            }\n"
    "        }\n"
    "\n"
    "        switch ($text) {\n"
)
new_catch = (
    "            } catch (Throwable $e) { /* #btn-guard */\n"
    "                app_log('bot', 'button handler failed: ' . $e->getMessage());\n"
    "                if (stripos($e->getMessage(), 'undefined method') !== false) {\n"
    "                    Tg::send($chatId, '\u26a0\ufe0f \u0627\u06cc\u0646 \u0628\u062e\u0634 \u0645\u0648\u0642\u062a\u0627\u064b \u062f\u0631 \u062f\u0633\u062a\u0631\u0633 \u0646\u06cc\u0633\u062a. \u0644\u0637\u0641\u0627\u064b \u0628\u0647 \u067e\u0634\u062a\u06cc\u0628\u0627\u0646\u06cc \u0627\u0637\u0644\u0627\u0639 \u062f\u0647\u06cc\u062f.');\n"
    "                    return;\n"
    "                }\n"
    "            }\n"
    "        }\n"
    "\n"
    "        switch ($text) {\n"
)
rep_lit('app/Bot/Bot.php', old_catch, new_catch, '#btn-guard')


# ============================================================
# 2) static integrity test (phpunit)
# ============================================================
TEST = r"""<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * محافظ ایستا در برابر «گم شدن متدهای ربات».
 * اگر متدی که در Bot.php صدا زده می‌شود تعریف نشده باشد، تست می‌شکند.
 */
final class StaticIntegrityTest extends TestCase
{
    private function src(string $rel): string
    {
        $p = dirname(__DIR__) . '/' . $rel;
        self::assertFileExists($p, $rel . ' پیدا نشد');
        return (string)file_get_contents($p);
    }

    private function hasFn(string $src, string $name): bool
    {
        return (bool)preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/i', $src);
    }

    public function testBotSelfCallsAreDefined(): void
    {
        $src = $this->src('app/Bot/Bot.php');
        preg_match_all('/self::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m);
        $missing = [];
        foreach (array_unique($m[1]) as $name) {
            if (!$this->hasFn($src, $name)) {
                $missing[] = $name;
            }
        }
        self::assertSame([], $missing, 'متدهای صدازده‌شده ولی تعریف‌نشده: ' . implode(', ', $missing));
    }

    public function testMenuHandlersExist(): void
    {
        $src = $this->src('app/Bot/Bot.php');
        $need = [
            'sectionProducts', 'sectionServices', 'sectionWallet', 'sectionAccount',
            'sectionTest', 'sectionTutorials', 'sectionSupport', 'sectionReseller',
            'sectionReferral', 'sectionStock', 'sectionResellerServices', 'sectionOrders',
            'walletCardMenu', 'walletHistory', 'askAmount', 'verifyMenu', 'referralList',
            'resellerRequest', 'miniappBtn', 'runButton', 'handleState', 'mainMenu',
        ];
        foreach ($need as $fn) {
            self::assertTrue($this->hasFn($src, $fn), 'متد ' . $fn . ' در Bot.php نیست');
        }
    }

    public function testBotFileIsNotTruncated(): void
    {
        $src = $this->src('app/Bot/Bot.php');
        $count = preg_match_all('/\n\s+(?:public|private|protected)\s+(?:static\s+)?function\s/', $src);
        self::assertGreaterThanOrEqual(120, $count, 'تعداد متدهای Bot.php غیرعادی کم است: ' . $count);
    }

    public function testKbConstantsUsedByBotExist(): void
    {
        $bot = $this->src('app/Bot/Bot.php');
        $kb  = $this->src('app/Bot/Kb.php');
        preg_match_all('/Kb::([A-Z][A-Z0-9_]*)\b/', $bot, $m);
        $missing = [];
        foreach (array_unique($m[1]) as $c) {
            if (strpos($kb, 'const ' . $c) === false) {
                $missing[] = $c;
            }
        }
        self::assertSame([], $missing, 'ثابت‌های گمشده در Kb: ' . implode(', ', $missing));
    }
}
"""
add_file('tests/StaticIntegrityTest.php', TEST)


# ============================================================
# 3) standalone integrity checker (php tools/integrity.php)
# ============================================================
TOOL = r"""<?php
/**
 * بررسی یکپارچگی ایستای سورس
 * اجرا: php tools/integrity.php
 * خروجی غیر صفر یعنی متدی صدا زده شده که تعریف نشده است.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = ['/app', '/cron', '/tools'];
$files = [];
foreach ($dirs as $d) {
    $p = $root . $d;
    if (!is_dir($p)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

$problems = 0;
$checked = 0;
$skipped = 0;
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    $rel = str_replace($root . '/', '', $f);
    if (!preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*/', $src)) {
        continue;
    }
    if (preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*\s+extends\s/', $src)) {
        $skipped++;
        continue;
    }
    $checked++;
    preg_match_all('/self::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $name) {
        if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/i', $src)) {
            $missing[] = $name;
        }
    }
    if ($missing) {
        echo '! ' . $rel . ' -> ' . implode(', ', $missing) . PHP_EOL;
        $problems += count($missing);
    }
}
echo '---' . PHP_EOL;
echo 'files checked: ' . $checked . ', skipped(extends): ' . $skipped . ', problems: ' . $problems . PHP_EOL;
exit($problems > 0 ? 1 : 0);
"""
add_file('tools/integrity.php', TOOL)


# ============================================================
# write
# ============================================================
if ERRORS:
    print('ABORTED - anchors not found:')
    for e in ERRORS:
        print('  - %s' % e)
    print('exit: 1')
    sys.exit(1)

changed = 0
for p, s in CACHE.items():
    if ORIG.get(p) == s:
        continue
    with io.open(os.path.join(ROOT, p), 'w', encoding='utf-8') as fh:
        fh.write(s)
    changed += 1
print('changed files: %d' % changed)
if WARN:
    print('warnings (optional patches skipped):')
    for w in WARN:
        print('  - %s' % w)


# ============================================================
# recon: make sure the restored code respects the Happ rules
# ============================================================
bot = load('app/Bot/Bot.php')
print('app/Bot/Bot.php: %d lines, %d bytes' % (len(bot.split(chr(10))), len(bot.encode('utf-8'))))
for needle in ['#btn-guard', '#happ-plain-off', '#happ-plain', 'Links::happ(', 'Add subscription',
               '\u0622\u062f\u0631\u0633 \u0627\u0634\u062a\u0631\u0627\u06a9', 'crypt5', 'sub_link']:
    grep('app/Bot/Bot.php', needle, 8)


# ============================================================
# forensics: which build removed the block?
# ============================================================
print('===== forensics: fixups.py at e60553e1 (the build that lost the code) =====')
try:
    req = urllib.request.Request(
        'https://raw.githubusercontent.com/SRNetWork-ai/SR-BOT/e60553e15f9a2b1f028662ec7b00c5054439cc0d/.github/scripts/fixups.py',
        headers={'User-Agent': 'sr-bot-fixups'})
    with urllib.request.urlopen(req, timeout=40) as r:
        old_py = r.read().decode('utf-8', 'replace')
    pl = old_py.split(chr(10))
    print('old fixups.py: %d lines' % len(pl))
    shown = 0
    for i, ln in enumerate(pl):
        if 'Bot.php' in ln or 're.sub' in ln or 'splitlines' in ln or '[:i]' in ln:
            shown += 1
            if shown <= 14:
                print('%5d| %s' % (i + 1, ln.strip()[:160]))
    print('suspect lines: %d' % shown)
except Exception as e:
    print('forensics fetch failed: %s' % e)


# ============================================================
# version bump
# ============================================================
vpath = os.path.join(ROOT, 'version.json')
with io.open(vpath, 'r', encoding='utf-8') as fh:
    vj = json.load(fh)
old_build = vj.get('build')
vj['build'] = BUILD
note = '\u0645\u062d\u0627\u0641\u0638: \u062b\u0628\u062a \u062e\u0637\u0627\u06cc \u062f\u06a9\u0645\u0647\u200c\u0647\u0627 \u062f\u0631 \u0644\u0627\u06af \u0628\u0647 \u062c\u0627\u06cc \u0646\u0627\u062f\u06cc\u062f\u0647 \u06af\u0631\u0641\u062a\u0646 + \u0628\u0631\u0631\u0633\u06cc \u06cc\u06a9\u067e\u0627\u0631\u0686\u06af\u06cc \u062e\u0648\u062f\u06a9\u0627\u0631 \u0633\u0648\u0631\u0633'
cl = vj.get('changelog')
if isinstance(cl, list):
    vj['changelog'] = ([note] + cl)[:60]
    print('changelog: entry added')
with io.open(vpath, 'w', encoding='utf-8') as fh:
    json.dump(vj, fh, ensure_ascii=False, indent=2)
    fh.write(chr(10))
print('build: %s (was %s)' % (BUILD, old_build))
print('exit: 0')
