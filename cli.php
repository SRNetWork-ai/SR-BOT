#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ابزار خط فرمان بکاپ و به‌روزرسانی
 *
 *   php cli.php version
 *   php cli.php token <TOKEN> [--force] [--no-hook]
 *   php cli.php hook [set|del|info]
 *   php cli.php backup [db|full]
 *   php cli.php list
 *   php cli.php restore <file> [--files]
 *   php cli.php check
 *   php cli.php update [--no-backup] [--no-migrate]
 *   php cli.php migrate
 *   php cli.php prune [count]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require __DIR__ . '/app/bootstrap.php';
boot();

$argvv = $_SERVER['argv'] ?? [];
$cmd   = strtolower((string)($argvv[1] ?? 'version'));
$arg   = (string)($argvv[2] ?? '');
$flags = array_slice($argvv, 2);
$has   = fn(string $f) => in_array($f, $flags, true);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }

switch ($cmd) {

    case 'version':
        $i = Updater::info();
        out('VPN Shop ' . APP_VERSION);
        out('repo      : ' . Updater::repo() . ' @ ' . Updater::branch());
        out('latest    : ' . ($i['latest'] !== '' ? (string)$i['latest'] : '-'));
        out('update    : ' . (!empty($i['has_update']) ? 'available' : 'up to date'));
        $s = Backup::stats();
        out('backups   : ' . (string)$s['count'] . ' files, ' . human_bytes((int)$s['size']));
        out('last backup: ' . ($s['last'] ? date('Y-m-d H:i', (int)$s['last']) : '-'));
        break;

    case 'token':
        if ($arg === '' || strpos($arg, '--') === 0) { out('usage: php cli.php token <TOKEN> [--force] [--no-hook]'); exit(1); }
        if (!class_exists('Cfg')) { out('[ERR] app/Service/Cfg.php موجود نیست.'); exit(1); }
        $r = Cfg::saveToken($arg, $has('--force'));
        out((!empty($r['ok']) ? '[OK] ' : '[ERR] ') . (string)($r['message'] ?? ''));
        if (!empty($r['ok']) && !empty($r['verified']) && !$has('--no-hook')) {
            $w = Tg::setWebhook(app_url('index.php'), (string)Cfg::get('bot.secret', ''));
            out(!empty($w['ok'])
                ? '[OK] webhook -> ' . app_url('index.php')
                : '[ERR] webhook: ' . (string)($w['description'] ?? '-'));
        }
        exit(!empty($r['ok']) ? 0 : 1);

    case 'hook':
        $sub = $arg !== '' ? strtolower($arg) : 'info';
        if ($sub === 'set') {
            $w = Tg::setWebhook(app_url('index.php'), (string)cfg('bot.secret', ''));
            out((!empty($w['ok']) ? '[OK] webhook -> ' . app_url('index.php') : '[ERR] ' . (string)($w['description'] ?? '-')));
        } elseif ($sub === 'del') {
            $w = Tg::deleteWebhook();
            out(!empty($w['ok']) ? '[OK] webhook deleted' : '[ERR] ' . (string)($w['description'] ?? '-'));
        } else {
            $me = Tg::getMe();
            out('me       : ' . (!empty($me['ok'])
                ? '@' . (string)($me['result']['username'] ?? '-')
                : 'FAILED ' . (string)($me['description'] ?? '-')));
            $i = Tg::api('getWebhookInfo');
            $w = (array)($i['result'] ?? []);
            out('url      : ' . (string)($w['url'] ?? '-'));
            out('expected : ' . app_url('index.php'));
            out('pending  : ' . (string)($w['pending_update_count'] ?? '0'));
            out('last err : ' . (string)($w['last_error_message'] ?? '-'));
        }
        break;

    case 'backup':
        $type = $arg === 'full' ? 'full' : 'db';
        $r = Backup::create($type, 'cli');
        out((!empty($r['ok']) ? '[OK] ' : '[ERR] ') . (string)($r['message'] ?? ''));
        if (!empty($r['name'])) out('file: storage/backups/' . (string)$r['name']);
        exit(!empty($r['ok']) ? 0 : 1);

    case 'list':
        foreach (Backup::all() as $b) {
            out(str_pad((string)$b['name'], 34) . ' ' . str_pad(human_bytes((int)$b['size']), 10)
                . ' ' . date('Y-m-d H:i', (int)$b['time']) . '  [' . (string)$b['type'] . ']');
        }
        break;

    case 'restore':
        if ($arg === '' || strpos($arg, '--') === 0) { out('usage: php cli.php restore <file> [--files]'); exit(1); }
        $r = Backup::restore($arg, $has('--files'));
        out((!empty($r['ok']) ? '[OK] ' : '[ERR] ') . (string)($r['message'] ?? ''));
        exit(!empty($r['ok']) ? 0 : 1);

    case 'check':
        $r = Updater::check();
        out((!empty($r['ok']) ? '[OK] ' : '[ERR] ') . (string)($r['message'] ?? ''));
        foreach ((array)($r['changelog'] ?? []) as $c) out('  - ' . (string)$c);
        exit(!empty($r['ok']) ? 0 : 1);

    case 'update':
        $c = Updater::check();
        out((string)($c['message'] ?? ''));
        if (empty($c['ok'])) exit(1);
        if (empty($c['has_update']) && !$has('--force')) { out('نسخه جدیدی نیست. برای نصب مجدد --force بزنید.'); exit(0); }
        $d = Updater::download();
        out((string)($d['message'] ?? ''));
        if (empty($d['ok'])) exit(1);
        $r = Updater::apply((string)$d['path'], !$has('--no-backup'), !$has('--no-migrate'));
        @unlink((string)$d['path']);
        foreach ((array)($r['log'] ?? []) as $l) out('  ' . (string)$l);
        out((!empty($r['ok']) ? '[OK] ' : '[ERR] ') . (string)($r['message'] ?? ''));
        exit(!empty($r['ok']) ? 0 : 1);

    case 'migrate':
        $r = Updater::migrate();
        out((!empty($r['ok']) ? '[OK] ' : '[ERR] ') . (string)($r['message'] ?? ''));
        exit(!empty($r['ok']) ? 0 : 1);

    case 'prune':
        $keep = $arg !== '' ? max(1, (int)$arg) : max(1, (int)DB::setting('backup_keep', 7));
        out('[OK] removed ' . (string)Backup::prune($keep) . ' old files (keep ' . (string)$keep . ')');
        break;

    default:
        out("commands: version | backup [db|full] | list | restore <file> [--files] | check | update [--force] [--no-backup] [--no-migrate] | migrate | prune [n]");
        exit(1);
}
