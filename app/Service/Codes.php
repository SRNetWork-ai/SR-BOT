<?php
declare(strict_types=1);

/**
 * کدهای تخفیف و کدهای هدیه (شارژ کیف پول)
 */
class Codes
{
    /* ---------------- تخفیف ---------------- */

    /** @return array{ok:bool,message:string,code?:array,discount?:int,final?:int} */
    public static function checkDiscount(string $code, array $user, int $amount, ?int $productId = null): array
    {
        $code = strtoupper(trim(en_num($code)));
        $row  = DB::one('SELECT * FROM {p}discount_codes WHERE code = :c', [':c' => $code]);
        if (!$row) return ['ok' => false, 'message' => 'کد تخفیف معتبر نیست.'];
        if ((int)$row['active'] !== 1) return ['ok' => false, 'message' => 'این کد غیرفعال شده است.'];
        if (!empty($row['starts_at']) && strtotime((string)$row['starts_at']) > time()) {
            return ['ok' => false, 'message' => 'زمان استفاده از این کد هنوز شروع نشده است.'];
        }
        if ($row['expires_at'] && strtotime((string)$row['expires_at']) < time()) return ['ok' => false, 'message' => 'اعتبار این کد تمام شده است.'];
        if ((int)$row['max_uses'] > 0 && (int)$row['used'] >= (int)$row['max_uses']) return ['ok' => false, 'message' => 'سقف استفاده از این کد پر شده است.'];
        if ($row['product_id'] && $productId && (int)$row['product_id'] !== (int)$productId) return ['ok' => false, 'message' => 'این کد برای این محصول معتبر نیست.'];
        if ((int)$row['min_amount'] > 0 && $amount < (int)$row['min_amount']) {
            return ['ok' => false, 'message' => 'حداقل مبلغ برای این کد ' . money((int)$row['min_amount']) . ' ' . currency() . ' است.'];
        }
        $perUser = (int)$row['per_user'];
        if ($perUser > 0) {
            $used = (int)DB::val('SELECT COUNT(*) FROM {p}discount_uses WHERE code_id = :c AND user_id = :u',
                [':c' => (int)$row['id'], ':u' => (int)$user['id']], 0);
            if ($used >= $perUser) return ['ok' => false, 'message' => 'شما قبلاً از این کد استفاده کرده‌اید.'];
        }

        $discount = $row['type'] === 'percent'
            ? (int)floor($amount * (int)$row['value'] / 100)
            : (int)$row['value'];
        $cap = (int)($row['max_discount'] ?? 0);
        if ($row['type'] === 'percent' && $cap > 0 && $discount > $cap) $discount = $cap;
        $discount = max(0, min($discount, $amount));

        return [
            'ok' => true,
            'message' => 'کد تخفیف اعمال شد.',
            'code' => $row,
            'discount' => $discount,
            'final' => $amount - $discount,
        ];
    }

    public static function useDiscount(array $code, array $user, ?int $orderId = null): void
    {
        DB::insert('discount_uses', [
            'code_id' => (int)$code['id'], 'user_id' => (int)$user['id'],
            'order_id' => $orderId, 'created_at' => now(),
        ]);
        DB::q('UPDATE {p}discount_codes SET used = used + 1 WHERE id = :id', [':id' => (int)$code['id']]);
    }

    /* ---------------- کد هدیه ---------------- */

    public static function redeemGift(string $code, array $user): array
    {
        $code = strtoupper(trim(en_num($code)));
        $row  = DB::one('SELECT * FROM {p}gift_codes WHERE code = :c', [':c' => $code]);
        if (!$row) return ['ok' => false, 'message' => 'کد هدیه معتبر نیست.'];
        if ((int)$row['active'] !== 1) return ['ok' => false, 'message' => 'این کد غیرفعال است.'];
        if ($row['expires_at'] && strtotime((string)$row['expires_at']) < time()) return ['ok' => false, 'message' => 'اعتبار این کد تمام شده است.'];
        if ((int)$row['max_uses'] > 0 && (int)$row['used'] >= (int)$row['max_uses']) return ['ok' => false, 'message' => 'سقف استفاده از این کد پر شده است.'];
        $mine = (int)DB::val('SELECT COUNT(*) FROM {p}gift_uses WHERE code_id = :c AND user_id = :u',
            [':c' => (int)$row['id'], ':u' => (int)$user['id']], 0);
        if ($mine > 0) return ['ok' => false, 'message' => 'شما قبلاً این کد را استفاده کرده‌اید.'];

        $amount = (int)$row['amount'];
        Wallet::credit((int)$user['id'], $amount, 'gift', 'gift', 'کد هدیه: ' . $code);
        DB::insert('gift_uses', ['code_id' => (int)$row['id'], 'user_id' => (int)$user['id'], 'created_at' => now()]);
        DB::q('UPDATE {p}gift_codes SET used = used + 1 WHERE id = :id', [':id' => (int)$row['id']]);

        return ['ok' => true, 'message' => '🎁 مبلغ ' . money($amount) . ' ' . currency() . ' به کیف پول شما افزوده شد.', 'amount' => $amount];
    }

    /** تولید کد تصادفی خوانا */
    public static function generate(string $prefix = ''): string
    {
        return strtoupper(($prefix !== '' ? $prefix . '-' : '') . rnd(4, 'ABCDEFGHJKLMNPQRSTUVWXYZ') . rnd(4, '23456789'));
    }
}
