<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * تست توابع کمکی — اجرا: composer test  (یا php tests/run.php بدون PHPUnit)
 */
final class HelpersTest extends TestCase
{
    public function testEscape(): void
    {
        $this->assertSame('&lt;b&gt;', h('<b>'));
        $this->assertSame('&quot;x&quot;', h('"x"'));
    }

    public function testJsonHelpers(): void
    {
        $this->assertSame('{"a":1}', jenc(['a' => 1]));
        $this->assertSame(['a' => 1], jdec('{"a":1}'));
        $this->assertSame([], jdec(''));
        $this->assertSame(['fallback'], jdec('not-json', ['fallback']));
    }

    public function testRnd(): void
    {
        $s = rnd(16);
        $this->assertSame(16, strlen($s));
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $s);
    }

    public function testUuid(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            uuidv4()
        );
    }

    public function testVolumeHelpers(): void
    {
        $this->assertSame(1073741824, gb2bytes(1));
        $this->assertSame(0.5, bytes2gb(536870912));
        $this->assertSame('1 GB', human_bytes(1073741824));
        $this->assertSame('0', human_bytes(0));
    }

    public function testDigits(): void
    {
        $this->assertSame('1234', en_num('۱۲۳۴'));
        $this->assertSame('2026', en_num('٢٠٢٦'));
        $this->assertSame('1,234,567', money(1234567, false));
    }

    public function testCleanText(): void
    {
        $this->assertSame('salam', clean_text("  salam\x00  "));
        $this->assertSame(5, mb_strlen(clean_text('abcdefgh', 5)));
    }

    public function testSplitUnicodeSafe(): void
    {
        $parts = str_split_unicode_safe(str_repeat('a', 100), 30);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $p) {
            $this->assertLessThanOrEqual(30, mb_strlen($p));
        }
    }

    public function testJalali(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}$/', en_num(to_jalali('2026-03-21')));
    }

    public function testEncryptWithoutKeyIsPassthrough(): void
    {
        $this->assertSame('plain', app_decrypt(app_encrypt('plain')));
    }
}
