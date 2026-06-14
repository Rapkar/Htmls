# Taronix Gold Price — مستندات پلاگین

پلاگین وردپرس برای دریافت قیمت طلای ۱۸ عیار از API داریک (Daric)، ذخیره امن در `gold18_price` و نمایش در سایت.

---

## خلاصه: چطور کار می‌کند؟

```
سرور Cron  ──GET──▶  /wp-json/taronix-gold/v1/sync?key=SECRET
                              │
                              ▼
                    بررسی Secret Key
                              │
                              ▼
                    لاگین به API داریک (SSO)
                              │
                              ▼
                    دریافت قیمت طلا (GetGoldlPrice)
                              │
                              ▼
                    اعتبارسنجی قیمت
                              │
              ┌───────────────┴───────────────┐
              ▼                               ▼
         قیمت معتبر                      قیمت نامعتبر
              │                               │
              ▼                               ▼
   update_option('gold18_price')
   update_option('gold24_price')      ← gold18 × 24/18
   delete_transient('h7a_get_initial_data')
   delete_transient('taronix_gold18_price')
              │
              ▼
   شورت‌کد [taronix_gold_price] قیمت را از gold18_price نمایش می‌دهد
```

**نکته مهم:** تا زمانی که URL کران صدا زده نشود، `gold18_price` و `gold24_price` **هیچ‌وقت** عوض نمی‌شوند.

---

## نصب

1. پوشه `taronix-gold-price` را در `wp-content/plugins/` کپی کنید.
2. از **افزونه‌ها → Taronix Gold Price → فعال‌سازی** پلاگین را فعال کنید.
3. به **تنظیمات → Taronix Gold Price** بروید.
4. نام کاربری و رمز API داریک را وارد و ذخیره کنید.
5. URL کران را در crontab سرور خودتان قرار دهید.

---

## تنظیمات

### از پنل ادمین

| فیلد | توضیح |
|------|--------|
| Login URL | آدرس SSO داریک (پیش‌فرض: `https://apisc.daric.gold/sso/api/v1/user/AuthWithUsername`) |
| Username | نام کاربری API |
| Password | رمز عبور API |

### از wp-config.php (اختیاری — اولویت بالاتر)

```php
define('DARIC_GOLD_USERNAME', 'tara_user');
define('DARIC_GOLD_PASSWORD', 'your-password');
define('DARIC_GOLD_LOGIN_URL', 'https://apisc.daric.gold/sso/api/v1/user/AuthWithUsername');
define('DARIC_GOLD_CRON_SECRET', 'your-long-random-secret');
```

---

## Cron Job (تنها راه آپدیت قیمت)

### آدرس

```
GET https://YOUR-SITE.com/wp-json/taronix-gold/v1/sync?key=YOUR_SECRET
```

Secret هنگام فعال‌سازی پلاگین خودکار ساخته می‌شود و در صفحه تنظیمات نمایش داده می‌شود.

### روش ۱: Query String

```bash
curl -fsS "https://YOUR-SITE.com/wp-json/taronix-gold/v1/sync?key=YOUR_SECRET"
```

### روش ۲: Header

```bash
curl -fsS -H "X-Cron-Secret: YOUR_SECRET" \
  "https://YOUR-SITE.com/wp-json/taronix-gold/v1/sync"
```

### نمونه crontab (هر ۵ دقیقه)

```cron
*/5 * * * * curl -fsS "https://YOUR-SITE.com/wp-json/taronix-gold/v1/sync?key=YOUR_SECRET" >/dev/null 2>&1
```

### پاسخ‌های API

**موفق — قیمت آپدیت شد:**
```json
{
  "success": true,
  "updated": true,
  "price": 17568294,
  "previous": 17500000,
  "message": "Price updated successfully."
}
```

**موفق — قیمت تغییری نکرد:**
```json
{
  "success": true,
  "updated": false,
  "price": 17568294,
  "previous": 17568294,
  "message": "Price unchanged."
}
```

**خطا — قیمت قبلی حفظ شد:**
```json
{
  "success": false,
  "updated": false,
  "price": 17500000,
  "code": "fetch_failed",
  "message": "Invalid or empty gold price response"
}
```

### کدهای HTTP

| کد | معنی |
|----|------|
| 200 | sync موفق (قیمت جدید یا بدون تغییر) |
| 403 | secret اشتباه یا ارسال نشده |
| 409 | sync دیگری در حال اجراست |
| 500 | secret تنظیم نشده |
| 502 | sync ناموفق (API خطا / قیمت نامعتبر) |

---

## شورت‌کد نمایش قیمت

```
[taronix_gold_price]
```

- قیمت را **فقط از** `gold18_price` می‌خواند (به API وصل نمی‌شود).
- اگر قیمت ذخیره‌شده نباشد، چیزی نمایش نمی‌دهد.
- اعداد را با جداکننده هزارگان و ارقام فارسی نشان می‌دهد.
- استایل: نوار سفید گرد با متن «نرخ لحظه‌ای طلا» و «تومان».

### خواندن قیمت در کد PHP تم

```php
$price = taronix_gold_price_get_value(); // int یا false
```

---

## قوانین امنیتی قیمت

قیمت **فقط** در صورت برقراری همه شرایط زیر در `gold18_price` ذخیره می‌شود:

1. فراخوانی از endpoint کران (با secret معتبر)
2. credentialهای API تنظیم شده باشند
3. API پاسخ `IsSuccess: true` بدهد
4. `BestSellPrice` از API معتبر باشد، یا قیمت قبلی دیتابیس موجود باشد، یا `BestBuyPrice` (فقط وقتی قیمت قبلی نیست)
5. قیمت بین **۱۰۰,۰۰۰** تا **۹۹۹,۹۹۹,۹۹۹** تومان باشد
6. نسبت به قیمت قبلی منطقی باشد (پیش‌فرض: بین ۵۰٪ تا ۲۰۰٪)

در هر خطا → **قیمت قبلی دست نخورده می‌ماند.**

---

## جریان داخلی API داریک

### ۱. لاگین (SSO)

```
POST https://apisc.daric.gold/sso/api/v1/user/AuthWithUsername
Body: {"UserName":"...","Password":"..."}
```

توکن از مسیر `Data.Token.Accesstoken` خوانده می‌شود. توکن ۱۰ دقیقه در transient کش می‌شود.

### ۲. دریافت قیمت

```
GET https://apisc.daric.gold/Loan/api/v1/Tara/GetGoldlPrice
Header: Authorization: Bearer {access_token}
```

### ۳. اولویت قیمت ۱۸ عیار

1. **`BestSellPrice`** — قیمت فروش از API
2. **`gold18_price`** — قیمت قبلی دیتابیس (اگر فروش نیامد)
3. **`BestBuyPrice`** — قیمت خرید از API (فقط اگر قیمت قبلی هم نبود)

### ۴. قیمت ۲۴ عیار

بعد از آپدیت موفق `gold18_price`:

```
gold24_price = round(gold18_price × 24 / 18)
```

اگر محاسبه ۲۴ عیار نامعتبر باشد، **هیچ‌کدام** آپدیت نمی‌شوند.

---

## داده‌های ذخیره‌شده در وردپرس

| کلید | نوع | کاربرد |
|------|-----|--------|
| `gold18_price` | option | قیمت ۱۸ عیار (تومان) — از API |
| `gold24_price` | option | قیمت ۲۴ عیار (تومان) — محاسبه از ۱۸ عیار × 24/18 |
| `daric_gold_username` | option | نام کاربری API |
| `daric_gold_password` | option | رمز API |
| `daric_gold_login_url` | option | آدرس لاگین |
| `daric_gold_cron_secret` | option | کلید امنیتی کران |
| `h7a_get_initial_data` | transient | پاک می‌شود بعد از آپدیت موفق |
| `taronix_gold18_price` | transient | کش نمایش شورت‌کد (۵ دقیقه) |
| `daric_gold_tokens` | transient | کش توکن API (۱۰ دقیقه) |

---

## ساختار فایل‌ها

```
taronix-gold-price/
├── taronix-gold-price.php              # بوت‌استرپ، شورت‌کد، تنظیمات ادمین
├── includes/
│   ├── class-daric-login-client.php    # لاگین SSO + درخواست GET با Bearer
│   ├── class-daric-gold-sync.php       # اعتبارسنجی + update_option
│   ├── class-daric-gold-cron-endpoint.php  # REST endpoint کران
│   └── class-daric-gold-logger.php     # فایل لاگ اختصاصی
├── assets/css/taronix-gold-price.css   # استایل شورت‌کد
└── README.md
```

---

## فایل لاگ اختصاصی

لاگ‌ها **مستقل از تنظیمات debug وردپرس** در فایل جدا نوشته می‌شوند:

```
wp-content/taronix-gold-logs/daric-gold.log
```

مسیر قابل تغییر در `wp-config.php`:

```php
define('DARIC_GOLD_LOG_DIR', '/var/log/taronix-gold');
```

### نمونه خط لاگ

```
[2026-06-14 08:30:15] [ERROR] [daric-gold-sync] Price fetch failed; keeping previous price. | {"http_code":502,"source":"none","error":"Unable to get access token"}
[2026-06-14 08:30:10] [WARNING] [daric-login] Login skipped: cooldown active after previous failure. | {"cooldown_left_sec":42}
[2026-06-14 08:25:00] [INFO] [daric-gold-sync] Price updated successfully. | {"previous":17500000,"price":17568294,"source":"best_sell_price"}
```

### چه چیزهایی لاگ می‌شوند

| سطح | نمونه |
|-----|-------|
| ERROR | خطای لاگین، خطای API، credential نبودن |
| WARNING | secret اشتباه، قیمت مشکوک، استفاده از قیمت قبلی |
| INFO | آپدیت موفق، قیمت بدون تغییر |

رمز، توکن و secret در لاگ **ماسک** می‌شوند.

### مشاهده روی سرور

```bash
tail -f wp-content/taronix-gold-logs/daric-gold.log
```

مسیر دقیق در **تنظیمات → Taronix Gold Price** نمایش داده می‌شود.

---

| مشکل | راه‌حل |
|------|--------|
| قیمت آپدیت نمی‌شود | credentialها را چک کنید؛ URL کران را دستی با curl بزنید |
| HTTP 403 | secret اشتباه است |
| HTTP 502 با `fetch_failed` | API داریک در دسترس نیست یا credential غلط است |
| HTTP 502 با `suspicious_price` | قیمت جدید خیلی با قبلی فرق دارد — عمدی برای امنیت |
| شورت‌کد خالی است | هنوز کران اجرا نشده یا `gold18_price` خالی است |
| قیمت ۰ شد | با این پلاگین امکان‌پذیر نیست — قیمت نامعتبر ذخیره نمی‌شود |

برای جزئیات بیشتر فایل `wp-content/taronix-gold-logs/daric-gold.log` را بررسی کنید.

---

## فیلترهای توسعه‌دهنده

```php
// تغییر credentialها
add_filter('daric_gold_credentials', function ($creds) {
    $creds['username'] = 'custom_user';
    return $creds;
});

// تغییر محدوده نسبت قیمت (پیش‌فرض 0.5 تا 2.0)
add_filter('daric_gold_min_price_ratio', fn() => 0.3);
add_filter('daric_gold_max_price_ratio', fn() => 3.0);
```
