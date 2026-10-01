<?php
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
