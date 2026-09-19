#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""fixed85 - Telegram topic logging hardening."""
import io, json, os, re, sys

ROOT = os.environ.get("SRC_ROOT") or os.getcwd()
BUILD = (os.environ.get("NEW_BUILD") or "fixed85").strip() or "fixed85"

CACHE = {}
NEW = {}
ERRORS = []


def load(path):
    if path not in CACHE:
        full = os.path.join(ROOT, path)
        with io.open(full, encoding="utf-8") as fh:
            CACHE[path] = fh.read()
    return CACHE[path]


def rep(path, old, new, expect=1, marker=None):
    s = load(path)
    if marker and marker in s:
        return
    n = s.count(old)
    if n != expect:
        ERRORS.append("%s: literal anchor x%d (want %d): %r" % (path, n, expect, old[:120]))
        return
    CACHE[path] = s.replace(old, new)
    NEW[path] = True


# ===================================================================== settings
S = "admin/pages/settings.php"

rep(S,
    "    if (in_array($act, ['logs', 'logs_setup', 'logs_setup_rebuild', 'logs_test'], true)) {",
    "    if (in_array($act, ['logs', 'logs_setup', 'logs_setup_rebuild', 'logs_test', 'logs_test_one', 'logs_repair', 'logs_flush'], true)) { /* fixed85 */",
    marker="'logs_test_one'")

rep(S,
    "            Logs::setOff($off);\n",
    "            Logs::setOff($off);\n"
    "            /* fixed85: \u062a\u0627\u067e\u06cc\u06a9\u200c\u0647\u0627\u06cc \u0628\u06cc\u200c\u0635\u062f\u0627 + \u0636\u062f \u062a\u06a9\u0631\u0627\u0631 + \u0633\u0627\u062e\u062a \u062e\u0648\u062f\u06a9\u0627\u0631 \u062a\u0627\u067e\u06cc\u06a9 */\n"
    "            $sil = [];\n"
    "            foreach (array_keys(Logs::TOPICS) as $lk) if (isset($_POST['sl_' . $lk])) $sil[] = $lk;\n"
    "            Logs::setSilent($sil);\n"
    "            DB::setSetting('log_dedup_min', (string)max(0, min(1440, pint('log_dedup_min', 3))));\n"
    "            DB::setSetting('log_auto_topic', pchk('log_auto_topic'));\n",
    marker="Logs::setSilent($sil);")

rep(S,
    "        } elseif ($act === 'logs_test') {",
    "        } elseif ($act === 'logs_repair') { /* fixed85 */\n"
    "            $r = Logs::repairTopics();\n"
    "            flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);\n"
    "        } elseif ($act === 'logs_flush') {\n"
    "            $r = Logs::flushQueue(50);\n"
    "            flash('ok', '\U0001f69a \u0635\u0641 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627: ' . fa_num((string)(int)($r['sent'] ?? 0)) . ' \u0627\u0631\u0633\u0627\u0644 \u0634\u062f\u060c '\n"
    "                . fa_num((string)(int)($r['left'] ?? 0)) . ' \u062f\u0631 \u0635\u0641 \u0645\u0627\u0646\u062f'\n"
    "                . ((int)($r['dropped'] ?? 0) > 0 ? '\u060c ' . fa_num((string)(int)$r['dropped']) . ' \u0645\u0646\u0642\u0636\u06cc \u0634\u062f' : '') . '.');\n"
    "        } elseif ($act === 'logs_test_one') {\n"
    "            $tk = (string)preg_replace('/[^a-z_]/', '', (string)($_POST['tkey'] ?? ''));\n"
    "            $r  = Logs::testOne($tk);\n"
    "            flash(!empty($r['ok']) ? 'ok' : 'err', (string)$r['message']);\n"
    "        } elseif ($act === 'logs_test') {",
    marker="$act === 'logs_repair'")

rep(S,
    "$lt = Logs::threads();\n$loff = Logs::off();",
    "$lt = Logs::threads();\n$loff = Logs::off();\n$lsil = Logs::silent();   /* fixed85 */\n$lstat = Logs::stats();   /* fixed85 */",
    marker="$lsil = Logs::silent();")

rep(S,
    "        <thead><tr><th>\u06af\u0632\u0627\u0631\u0634</th><th>\u0634\u0646\u0627\u0633\u0647 \u062a\u0627\u067e\u06cc\u06a9</th><th>\u0648\u0636\u0639\u06cc\u062a</th></tr></thead>",
    "        <thead><tr><th>\u06af\u0632\u0627\u0631\u0634</th><th>\u0634\u0646\u0627\u0633\u0647 \u062a\u0627\u067e\u06cc\u06a9</th><th>\u0648\u0636\u0639\u06cc\u062a</th><th>\u0628\u062f\u0648\u0646 \u0627\u0639\u0644\u0627\u0646</th></tr></thead>",
    marker="<th>\u0628\u062f\u0648\u0646 \u0627\u0639\u0644\u0627\u0646</th>")

rep(S,
    "            <td><label class=\"check\"><input type=\"checkbox\" name=\"ev_<?= h((string)$lk) ?>\" value=\"1\" <?= in_array($lk, $loff, true) ? '' : 'checked' ?>><span>\u0627\u0631\u0633\u0627\u0644 \u0634\u0648\u062f</span></label></td>\n",
    "            <td><label class=\"check\"><input type=\"checkbox\" name=\"ev_<?= h((string)$lk) ?>\" value=\"1\" <?= in_array($lk, $loff, true) ? '' : 'checked' ?>><span>\u0627\u0631\u0633\u0627\u0644 \u0634\u0648\u062f</span></label></td>\n"
    "            <td><label class=\"check\"><input type=\"checkbox\" name=\"sl_<?= h((string)$lk) ?>\" value=\"1\" <?= in_array($lk, $lsil, true) ? 'checked' : '' ?>><span>\u0628\u06cc\u200c\u0635\u062f\u0627</span></label></td>\n",
    marker='name="sl_<?= h((string)$lk) ?>"')

STAB = (
    '      <div class="section-title">\U0001f6e1 \u067e\u0627\u06cc\u062f\u0627\u0631\u06cc \u0627\u0631\u0633\u0627\u0644 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627</div>\n'
    '      <div class="form-grid g2">\n'
    '        <div class="field"><label>\u0641\u0627\u0635\u0644\u0647\u0654 \u0636\u062f\u062a\u06a9\u0631\u0627\u0631 (\u062f\u0642\u06cc\u0642\u0647)</label>\n'
    '          <input class="mono" type="number" name="log_dedup_min" min="0" max="1440" value="<?= (int)$SET(\'log_dedup_min\', 3) ?>">\n'
    '          <div class="hint">\u067e\u06cc\u0627\u0645 \u06a9\u0627\u0645\u0644\u0627\u064b \u06cc\u06a9\u0633\u0627\u0646 \u062f\u0631 \u0627\u06cc\u0646 \u0628\u0627\u0632\u0647 \u062f\u0648\u0628\u0627\u0631\u0647 \u0627\u0631\u0633\u0627\u0644 \u0646\u0645\u06cc\u200c\u0634\u0648\u062f \u2014 <b>\u06f0 = \u062e\u0627\u0645\u0648\u0634</b></div></div>\n'
    '        <div class="field"><label>\u0633\u0627\u062e\u062a \u062e\u0648\u062f\u06a9\u0627\u0631 \u062a\u0627\u067e\u06cc\u06a9</label>\n'
    '          <label class="check" style="margin-top:8px"><input type="checkbox" name="log_auto_topic" value="1" <?= (int)$SET(\'log_auto_topic\', 1) ? \'checked\' : \'\' ?>>\n'
    '            <span>\u0627\u06af\u0631 \u062a\u0627\u067e\u06cc\u06a9\u06cc \u0646\u0628\u0648\u062f \u06cc\u0627 \u062d\u0630\u0641 \u0634\u062f\u060c \u062e\u0648\u062f\u06a9\u0627\u0631 \u0633\u0627\u062e\u062a\u0647 \u0634\u0648\u062f</span></label></div>\n'
    '        <div class="field"><label>\u062a\u0633\u062a \u06cc\u06a9 \u062a\u0627\u067e\u06cc\u06a9 \u0645\u0634\u062e\u0635</label>\n'
    '          <select name="tkey">\n'
    '            <?php foreach (Logs::TOPICS as $tk1 => $tv1): ?>\n'
    '              <option value="<?= h((string)$tk1) ?>"><?= h((string)$tv1[0]) ?></option>\n'
    '            <?php endforeach; ?>\n'
    '          </select>\n'
    '          <div class="hint">\u0628\u0627 \u062f\u06a9\u0645\u0647\u0654 \xab\u062a\u0633\u062a \u062a\u0627\u067e\u06cc\u06a9 \u0627\u0646\u062a\u062e\u0627\u0628\u06cc\xbb \u06cc\u06a9 \u067e\u06cc\u0627\u0645 \u0622\u0632\u0645\u0627\u06cc\u0634\u06cc \u0641\u0642\u0637 \u062f\u0631 \u0647\u0645\u06cc\u0646 \u062a\u0627\u067e\u06cc\u06a9 \u0627\u0631\u0633\u0627\u0644 \u0645\u06cc\u200c\u0634\u0648\u062f</div></div>\n'
    '      </div>\n'
    '      <div class="alert a-info mt3">\n'
    '        \U0001f4ca \u0627\u0645\u0631\u0648\u0632: <b><?= fa_num((string)(int)$lstat[\'ok\']) ?></b> \u0627\u0631\u0633\u0627\u0644 \u0645\u0648\u0641\u0642 \u0640 <b><?= fa_num((string)(int)$lstat[\'err\']) ?></b> \u0646\u0627\u0645\u0648\u0641\u0642 \u0640 \u0635\u0641 \u0645\u0639\u0644\u0642: <b><?= fa_num((string)(int)$lstat[\'queue\']) ?></b>\n'
    '        <?php if ((string)$lstat[\'last_err\'] !== \'\'): ?><br>\u0622\u062e\u0631\u06cc\u0646 \u062e\u0637\u0627: <span class="mono"><?= h(mb_substr((string)$lstat[\'last_err\'], 0, 160)) ?></span><?php endif; ?>\n'
    '      </div>\n'
    '\n'
)

rep(S,
    '      <div class="section-title">\u23f1 \u0628\u0627\u0632\u0647\u0654 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627\u06cc \u062f\u0648\u0631\u0647\u200c\u0627\u06cc (\u0633\u0627\u0639\u062a)</div>',
    STAB + '      <div class="section-title">\u23f1 \u0628\u0627\u0632\u0647\u0654 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627\u06cc \u062f\u0648\u0631\u0647\u200c\u0627\u06cc (\u0633\u0627\u0639\u062a)</div>',
    marker='name="log_dedup_min"')

rep(S,
    '        <button class="btn btn-ghost" name="act" value="logs_test">\U0001f9ea \u0627\u0631\u0633\u0627\u0644 \u067e\u06cc\u0627\u0645 \u062a\u0633\u062a</button>\n',
    '        <button class="btn btn-ghost" name="act" value="logs_test">\U0001f9ea \u0627\u0631\u0633\u0627\u0644 \u067e\u06cc\u0627\u0645 \u062a\u0633\u062a</button>\n'
    '        <button class="btn btn-ghost" name="act" value="logs_test_one">\U0001f3af \u062a\u0633\u062a \u062a\u0627\u067e\u06cc\u06a9 \u0627\u0646\u062a\u062e\u0627\u0628\u06cc</button>\n'
    '        <button class="btn" name="act" value="logs_repair">\U0001fa7a \u0628\u0631\u0631\u0633\u06cc \u0648 \u062a\u0631\u0645\u06cc\u0645 \u062a\u0627\u067e\u06cc\u06a9\u200c\u0647\u0627</button>\n'
    '        <button class="btn" name="act" value="logs_flush">\U0001f69a \u0627\u0631\u0633\u0627\u0644 \u0635\u0641 \u0645\u0639\u0644\u0642 (<?= fa_num((string)(int)$lstat[\'queue\']) ?>)</button>\n',
    marker='value="logs_flush"')

# ========================================================================= cron
C = "cron/tasks.php"

rep(C,
    "            'sync_fail' => 0, 'nowpay' => 0, 'recovered' => 0, 'hooshpay' => 0, 'gw_expired' => 0,",
    "            'sync_fail' => 0, 'nowpay' => 0, 'recovered' => 0, 'hooshpay' => 0, 'gw_expired' => 0, 'log_queue' => 0,",
    marker="'log_queue' => 0,")

rep(C,
    "    cron_say('gateway cleanup failed: ' . $e->getMessage());\n}\n",
    "    cron_say('gateway cleanup failed: ' . $e->getMessage());\n}\n"
    "\n"
    "/* fixed85: \u0627\u0631\u0633\u0627\u0644 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627\u06cc \u0645\u0639\u0644\u0642 \u062a\u0644\u06af\u0631\u0627\u0645 (\u0635\u0641 \u0622\u0641\u0644\u0627\u06cc\u0646 \u0644\u0627\u06af) */\n"
    "try {\n"
    "    if (class_exists('Logs') && Logs::enabled() && Logs::queueSize() > 0) {\n"
    "        $lq = Logs::flushQueue(40);\n"
    "        $report['log_queue'] = (int)($lq['sent'] ?? 0);\n"
    "        cron_say('log queue: ' . (int)($lq['sent'] ?? 0) . ' sent, ' . (int)($lq['left'] ?? 0) . ' left');\n"
    "    }\n"
    "} catch (Throwable $e) {\n"
    "    cron_say('log queue flush failed: ' . $e->getMessage());\n"
    "}\n",
    marker="log queue flush failed")

# ======================================================================= schema
Q = "database/schema.sql"

rep(Q,
    " ('log_backup','1'),\n",
    " ('log_backup','1'),\n"
    " ('log_silent',''),\n"
    " ('log_dedup_min','3'),\n"
    " ('log_auto_topic','1'),\n"
    " ('log_topic_retry','0'),\n",
    marker="('log_dedup_min'")

# ======================================================================= health
H = "app/Service/Health.php"

rep(H,
    "        $fc    = trim((string)DB::setting('force_channel', ''));",
    "        /* fixed85: \u0633\u0644\u0627\u0645\u062a \u0635\u0641 \u0648 \u0622\u062e\u0631\u06cc\u0646 \u062e\u0637\u0627\u06cc \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627 */\n"
    "        if (class_exists('Logs') && Logs::chat() !== '') {\n"
    "            $lst = Logs::stats();\n"
    "            $lqn = (int)($lst['queue'] ?? 0);\n"
    "            $out[] = self::it('\u0635\u0641 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627\u06cc \u0645\u0639\u0644\u0642', fa_num((string)$lqn), $lqn > 0 ? 'warn' : 'ok',\n"
    "                $lqn > 0 ? '\u0628\u0627 \u0627\u062c\u0631\u0627\u06cc \u06a9\u0631\u0627\u0646\u062c\u0627\u0628 \u06cc\u0627 \u062f\u06a9\u0645\u0647\u0654 \xab\u0627\u0631\u0633\u0627\u0644 \u0635\u0641 \u0645\u0639\u0644\u0642\xbb \u0627\u0631\u0633\u0627\u0644 \u0645\u06cc\u200c\u0634\u0648\u0646\u062f.' : '');\n"
    "            if ((string)($lst['last_err'] ?? '') !== '') {\n"
    "                $out[] = self::it('\u0622\u062e\u0631\u06cc\u0646 \u062e\u0637\u0627\u06cc \u0627\u0631\u0633\u0627\u0644 \u06af\u0632\u0627\u0631\u0634', mb_substr((string)$lst['last_err'], 0, 120), 'warn',\n"
    "                    '\u062f\u0633\u062a\u0631\u0633\u06cc \u0631\u0628\u0627\u062a \u062f\u0631 \u06af\u0631\u0648\u0647 \u0648 \u0641\u0639\u0627\u0644 \u0628\u0648\u062f\u0646 \u062d\u0627\u0644\u062a Topics \u0631\u0627 \u0628\u0631\u0631\u0633\u06cc \u06a9\u0646\u06cc\u062f.');\n"
    "            }\n"
    "        }\n"
    "\n"
    "        $fc    = trim((string)DB::setting('force_channel', ''));",
    marker="\u0635\u0641 \u06af\u0632\u0627\u0631\u0634\u200c\u0647\u0627\u06cc \u0645\u0639\u0644\u0642")

# ================================================================== version.json
if ERRORS:
    print("ABORTED - anchors not found:")
    for e in ERRORS:
        print("  - " + e)
    sys.exit(1)

for path in list(CACHE):
    if NEW.get(path):
        with io.open(os.path.join(ROOT, path), "w", encoding="utf-8") as fh:
            fh.write(CACHE[path])

VJ = os.path.join(ROOT, "version.json")
with io.open(VJ, encoding="utf-8") as fh:
    v = json.load(fh)

entry = ("\U0001f5c2 \u0644\u0627\u06af \u062a\u0627\u067e\u06cc\u06a9 \u062a\u0644\u06af\u0631\u0627\u0645 \u0628\u0627\u0632\u0646\u0648\u06cc\u0633\u06cc \u0634\u062f: "
         "\u062a\u0631\u0645\u06cc\u0645 \u062e\u0648\u062f\u06a9\u0627\u0631 \u062a\u0627\u067e\u06cc\u06a9 \u062d\u0630\u0641/\u0628\u0633\u062a\u0647\u200c\u0634\u062f\u0647\u060c "
         "\u062a\u0644\u0627\u0634 \u0645\u062c\u062f\u062f \u0647\u0646\u06af\u0627\u0645 \u0645\u062d\u062f\u0648\u062f\u06cc\u062a \u0646\u0631\u062e \u062a\u0644\u06af\u0631\u0627\u0645\u060c "
         "\u0635\u0641 \u0622\u0641\u0644\u0627\u06cc\u0646 \u0648 \u0627\u0631\u0633\u0627\u0644 \u062f\u0648\u0628\u0627\u0631\u0647 \u0628\u0627 \u06a9\u0631\u0627\u0646\u062c\u0627\u0628\u060c "
         "\u062c\u0644\u0648\u06af\u06cc\u0631\u06cc \u0627\u0632 \u067e\u06cc\u0627\u0645 \u062a\u06a9\u0631\u0627\u0631\u06cc\u060c \u0627\u0631\u0633\u0627\u0644 \u0628\u06cc\u200c\u0635\u062f\u0627\u060c "
         "\u062a\u0642\u0633\u06cc\u0645 \u067e\u06cc\u0627\u0645\u200c\u0647\u0627\u06cc \u0628\u0644\u0646\u062f\u060c \u062a\u0633\u062a \u062a\u06a9\u200c\u062a\u0627\u067e\u06cc\u06a9 "
         "\u0648 \u062f\u06a9\u0645\u0647\u0654 \xab\u0628\u0631\u0631\u0633\u06cc \u0648 \u062a\u0631\u0645\u06cc\u0645 \u062a\u0627\u067e\u06cc\u06a9\u200c\u0647\u0627\xbb \u062f\u0631 \u062a\u0646\u0638\u06cc\u0645\u0627\u062a.")

log = v.get("changelog") or []
if entry not in log:
    log.insert(0, entry)
    v["changelog"] = log
v["build"] = BUILD

with io.open(VJ, "w", encoding="utf-8") as fh:
    json.dump(v, fh, ensure_ascii=False, indent=2)
    fh.write("\n")

print("build: " + BUILD)
print("changed files: " + str(len(NEW)))
for p in sorted(NEW):
    print("  - " + p)
