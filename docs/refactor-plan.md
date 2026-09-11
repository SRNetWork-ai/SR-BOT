# نقشه شکستن فایل‌های بزرگ (Refactor Plan)

## چرا الان یک‌جا انجام نشد؟
جابه‌جایی هزاران خط بین فایل‌ها در یک بسته، ریسک بالایی برای از کار افتادن ربات در محیط واقعی دارد.
برنامه درست: هر مرحله در یک نسخه جدا، با تست روی سرور واقعی، و قابلیت برگشت با بکاپ.
از این نسخه به بعد، **کد جدید در کلاس‌های جدا** نوشته می‌شود (نمونه: `app/Service/Flood.php`).

## وضعیت فعلی
| فایل | حجم تقریبی | مسئولیت‌ها |
|---|---|---|
| `app/Bot/Bot.php` | ~4300 خط | منو، فروشگاه، کیف پول، خرید، سرویس‌ها، تیکت، جوین اجباری، پلن دلخواه |
| `miniapp/api.php` | ~3900 خط | همه endpointهای مینی‌اپ |
| `miniapp/index.php` | ~3900 خط | کل SPA مینی‌اپ |
| `admin/pages/settings.php` | ~2800 خط | همه تب‌های تنظیمات |

## مراحل پیشنهادی (هر مرحله = یک نسخه)
1. **Bot.php → traitها** (بدون تغییر رفتار):
   - `app/Bot/Concerns/ShopTrait.php` — فروشگاه و خرید (sectionProducts، showPanel، showCategory، showProduct، startPurchase، finalizePurchase)
   - `app/Bot/Concerns/CusTrait.php` — پلن دلخواه (cusCfg، cusMenu، cusAction، cusFinalize)
   - `app/Bot/Concerns/ForceJoinTrait.php` — جوین اجباری (fjRows، fjGate، fjPrompt و بقیه)
   - `app/Bot/Concerns/WalletTrait.php` — کیف پول و شارژ
   - کلاس `Bot` فقط `use` می‌کند؛ امضای متدها ثابت می‌ماند و autoload فعلی (`app/Bot/`) پاسخگو است.
2. **miniapp/api.php → روتر + فایل هر ماژول**: `miniapp/handlers/{shop,wallet,services,reseller,cus}.php` و یک switch کوتاه که require می‌کند.
3. **settings.php → یک فایل هر تب**: `admin/pages/settings/{shop,payment,bot,logs,broadcast}.php`.
4. **تست پس از هر مرحله**: `bash tools/lint.sh` + سناریوی دستی (start، خرید، شارژ، تیکت، گزارش تست).

## قواعد از این به بعد
- قابلیت جدید = کلاس جدید در `app/Service/` یا trait جدید؛ به فایل‌های بزرگ فقط «اتصال» اضافه می‌شود.
- قبل از هر انتشار: `tools/lint.sh` و در صورت امکان `phpstan analyse`.
- هر نسخه در `CHANGELOG.md` ثبت شود.
