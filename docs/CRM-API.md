# مستند API مدیریت مشتریان (برای اپلیکیشن Android و iOS کارمندان)

این API از نسخهٔ **2.20.0** در دسترس است. فضای نام آن `vereskonto/v1` است و همهٔ مسیرها با `/crm` شروع می‌شوند:

```
https://vereskala.com/wp-json/vereskonto/v1/crm/...
```

> فهرست زندهٔ همهٔ مسیرها با پارامترها و نوع هرکدام از نشانی `https://vereskala.com/wp-json/vereskonto/v1` قابل دریافت است (ساختار استاندارد REST وردپرس).

## پیش‌نیازها

- در پیشخوان، بخش **Vereskonto › تنظیمات عمومی › API موبایل**، گزینهٔ «فعال بودن API» را روشن کنید. تا زمانی که خاموش باشد، هیچ مسیری وجود ندارد و پاسخ 404 برمی‌گردد.
- کارمند باید نقش «کارشناس فروش»، «مدیر فروش»، «مدیر فروشگاه» یا «مدیر کل» داشته باشد.
- شمارهٔ موبایل کارمند باید در پروفایل کاربری‌اش، در فیلد **تلفن صورت‌حساب** (`billing_phone`)، ثبت شده باشد. ورود با کد پیامکی به همین شماره انجام می‌شود.
- همهٔ درخواست‌ها فقط از طریق **HTTPS** فرستاده شوند.

## احراز هویت

### ۱. درخواست کد

```http
POST /crm/auth/request-otp
Content-Type: application/json

{"mobile": "09121234567"}
```

پاسخ همیشه یکسان است، چه شماره مال کارمند باشد چه نباشد، تا نتوان با این مسیر فهمید چه کسی در فروشگاه کار می‌کند. پیامک فقط به شمارهٔ کارمندان فرستاده می‌شود.

```json
{"sent": true, "expires_in": 120}
```

| کد خطا | معنی |
|---|---|
| 422 `vkl_bad_mobile` | شماره معتبر نیست |
| 429 `vkl_rate` | درخواست بیش از اندازه (محدودیت بر اساس شماره و IP) |

### ۲. تأیید کد و دریافت توکن

```http
POST /crm/auth/verify-otp

{"mobile": "09121234567", "code": "48213", "device": "Pixel 8 / app 1.0"}
```

```json
{
  "token": "Qm9…",
  "token_type": "Bearer",
  "expires_in": 2592000,
  "user": {"id": 21, "name": "سارا محمدی", "email": "", "capabilities": {"vkl_crm_access": true, "vkl_crm_edit_own": true, "vkl_crm_edit_all": false, "…": false}}
}
```

- توکن پیش‌فرض **۳۰ روز** اعتبار دارد (تنظیم `vkl_api_staff_token_ttl_days`).
- توکن فقط یک بار نمایش داده می‌شود و روی سرور فقط هش آن نگه داشته می‌شود. آن را در Keychain (iOS) یا EncryptedSharedPreferences (Android) ذخیره کنید.
- پس از ۵ کد نادرست، ورود آن شماره ۱۵ دقیقه بسته می‌شود (پاسخ 429 با کد `vkl_locked`).
- برای کد نادرست یا منقضی پاسخ 401 با کد `vkl_bad_code` برمی‌گردد.

### ۳. استفاده از توکن

```http
Authorization: Bearer Qm9…
```

- توکن‌های اپ مشتری (مسیر `/auth/verify-otp`) **به مسیرهای مدیریت مشتریان دسترسی ندارند** و پاسخ 403 با کد `vkl_crm_scope` می‌گیرند.
- روش‌های دیگر هم پذیرفته می‌شوند: «گذرواژهٔ برنامه» (Application Password) وردپرس با Basic Auth روی HTTPS، و برای جاوااسکریپت داخل پیشخوان، کوکی ورود به همراه `X-WP-Nonce`.

### خروج

```http
POST /crm/auth/logout
```

همین توکن باطل می‌شود. مدیر کل هم می‌تواند از صفحهٔ «API موبایل» همهٔ توکن‌ها را یکجا باطل کند.

## قواعد کلی

| موضوع | قاعده |
|---|---|
| تاریخ‌ها در پاسخ | ISO 8601 به وقت UTC، مثل `"2026-11-21T14:05:00Z"` |
| تاریخ‌ها در ورودی | `birthday`، `expected_close`، `due_date` و `meeting_date` به شمسی (`1405/09/10`) یا میلادی (`2026-12-01`)؛ ساعت‌ها (`due_time`، `meeting_time`) به وقت فروشگاه (تهران) و به شکل `HH:MM`. در وظایف می‌توان به‌جای این دو، `due_at` را به شکل ISO فرستاد. |
| مبلغ | عدد. رشته با رقم فارسی و جداکننده هم پذیرفته می‌شود (`"۱٬۲۰۰٬۰۰۰"`) |
| صفحه‌بندی | `?page=1&per_page=20` (حداکثر 100). تعداد کل در سرآیندهای `X-WP-Total` و `X-WP-TotalPages` |
| همگام‌سازی آفلاین | `?updated_since=2026-11-21T10:00:00Z` فقط رکوردهای تغییرکرده را برمی‌گرداند، **همراه با رکوردهای حذف‌شده** (`"deleted": true`). برای درخواست بعدی، مقدار سرآیند `X-VKL-Server-Time` را به‌عنوان `updated_since` بفرستید. |
| دسترسی | همان قواعد پیشخوان: کارشناس فقط رکوردهای خودش و (اگر تنظیم روشن باشد) رکوردهای بدون کارشناس را می‌بیند. رکوردی که کاربر اجازهٔ دیدنش را ندارد پاسخ **404** می‌گیرد، تا نتوان وجود شناسه‌ها را حدس زد. فیلد `can_edit` در هر رکورد می‌گوید کاربر اجازهٔ ویرایش دارد یا نه. |

**شکل خطاها:**

```json
{"code": "vkl_crm_duplicate", "message": "مخاطبی با این شمارهٔ موبایل از قبل وجود دارد.", "data": {"status": 409, "contact_id": 12, "field": "mobile"}}
```

| HTTP | معنی |
|---|---|
| 400 | پارامتر نامعتبر (اعتبارسنجی خود وردپرس، با کد `rest_invalid_param`) |
| 401 | وارد نشده یا توکن نامعتبر |
| 403 | دسترسی ندارد |
| 404 | پیدا نشد (یا اجازهٔ دیدنش را ندارد) |
| 409 | تکراری (مثلاً شمارهٔ موبایل) |
| 422 | داده‌ها معتبر نیستند (`data.field` نام فیلد را می‌گوید) |
| 429 | درخواست بیش از اندازه |

## مسیرها

### جلسه و داده‌های پایه

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/crm/me` | کاربر، دسترسی‌ها و شمار کارهای امروز و معوق |
| GET | `/crm/meta` | فهرست‌های لازم برای فرم‌ها و برچسب‌ها: مراحل مشتری، منابع، انواع فعالیت، نتیجهٔ تماس، اولویت‌ها، گزینه‌های یادآوری، قیف‌ها با مراحل، برچسب‌ها، کارمندان، دلایل از دست رفتن، واحد پول و منطقهٔ زمانی |
| GET | `/crm/reports/summary?period=this_month` | آمار داشبورد (KPI، قیف، کارهای امروز). مقدارهای `period`: `this_month`، `last_month`، `last_30`، `last_90`، `this_year` و `custom` (به همراه `from` و `to` شمسی) |

### مخاطبین

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/crm/contacts` | فیلترها: `q` (نام، موبایل به هر شکل، ایمیل، شرکت)، `lifecycle`، `owner_id` (‎-1 یعنی بدون کارشناس)، `source`، `tag_id`، `city`، `orderby`، `order`، `updated_since` |
| POST | `/crm/contacts` | ساخت مخاطب. فیلدها: `first_name`، `last_name`، `company`، `mobile`، `email`، `city`، `birthday`، `lifecycle`، `source`، `score`، `owner_id` (فقط مدیران)، `tag_ids[]` |
| GET | `/crm/contacts/{id}` | یک مخاطب |
| PATCH | `/crm/contacts/{id}` | ویرایش (فقط فیلدهای فرستاده‌شده تغییر می‌کنند) |
| DELETE | `/crm/contacts/{id}` | انتقال به فهرست حذف‌شده‌ها (نیازمند دسترسی حذف) |
| POST | `/crm/contacts/{id}/claim` | برداشتن مخاطب بدون کارشناس برای خود |
| GET | `/crm/contacts/{id}/timeline?limit=30&before=0&types[]=call` | تاریخچه، جدیدترین اول. برای صفحهٔ بعد، `next_before` را به‌عنوان `before` بفرستید. |
| POST | `/crm/contacts/{id}/activities` | ثبت یادداشت، تماس یا جلسه. فیلدها: `type` (`note`، `call`، `meeting`)، `subject`، `body`، `deal_id`؛ برای تماس `direction` (`in` یا `out`)، `outcome` و `minutes`؛ برای جلسه `meeting_status` (`done`، `scheduled`، `cancelled`)، `meeting_date` و `meeting_time` |

**نمونهٔ مخاطب در پاسخ:**

```json
{
  "id": 9, "user_id": null, "name": "مریم احمدی", "first_name": "مریم", "last_name": "احمدی",
  "company": "پوشاک آریا", "mobile": "09351112233", "email": "", "city": "تهران", "birthday": null,
  "lifecycle": "prospect", "source": "instagram", "owner_id": 21, "owner_name": "سارا محمدی", "score": 0,
  "orders_count": 0, "total_spent": 0, "last_order_at": null, "last_activity_at": "2026-11-21T14:05:00Z",
  "tags": [{"id": 3, "name": "عمده‌فروش", "type": "category", "color": "#2271b1"}],
  "created_at": "2026-11-21T13:00:00Z", "updated_at": "2026-11-21T14:05:00Z", "deleted": false, "can_edit": true
}
```

### فرصت‌های فروش

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/crm/deals` | فیلترها: `q`، `pipeline_id`، `stage_id`، `status` (`open`، `won`، `lost`)، `owner_id`، `contact_id`، `updated_since` |
| POST | `/crm/deals` | فیلدها: `title` و `contact_id` (هر دو لازم)، `value`، `pipeline_id`، `stage_id`، `expected_close`، `source`، `order` (شمارهٔ سفارش ووکامرس، باید مال همین مخاطب باشد)، `owner_id` (فقط مدیران) |
| GET | `/crm/deals/{id}` | یک فرصت |
| PATCH | `/crm/deals/{id}` | ویرایش. تغییر `stage_id` همان قواعد جابه‌جایی را اجرا می‌کند. |
| POST | `/crm/deals/{id}/move` | `{"stage_id": 42, "lost_reason": "قیمت بالا بود"}`. وضعیت فرصت از نوع مرحله می‌آید (در جریان، برنده یا از دست رفته). |
| DELETE | `/crm/deals/{id}` | حذف (نیازمند دسترسی حذف) |

### وظایف

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/crm/tasks?view=today` | مقدارهای `view`: `today`، `overdue`، `upcoming`، `no_date`، `done`، `all`. فیلترهای دیگر: `assignee_id`، `contact_id`، `deal_id`، `priority`، `q`، `updated_since` |
| POST | `/crm/tasks` | فیلدها: `title` (لازم)، `description`، `due_at` (ISO) یا `due_date` به همراه `due_time`، `remind` (`""` یعنی بدون یادآوری، یا `0`، `15`، `60`، `1440` دقیقه پیش از موعد)، `priority`، `contact_id`، `deal_id`، `assignee_id` (فقط مدیران) |
| GET / PATCH / DELETE | `/crm/tasks/{id}` | خواندن، ویرایش و حذف یک وظیفه |
| POST | `/crm/tasks/{id}/done` | `{"done": true}` برای انجام شد، یا `false` برای برگرداندن |

## نمونهٔ جریان کامل با curl

```bash
curl -s -X POST https://vereskala.com/wp-json/vereskonto/v1/crm/auth/request-otp -H 'Content-Type: application/json' -d '{"mobile":"09121234567"}'
```

```bash
curl -s -X POST https://vereskala.com/wp-json/vereskonto/v1/crm/auth/verify-otp -H 'Content-Type: application/json' -d '{"mobile":"09121234567","code":"48213","device":"test"}'
```

```bash
curl -s https://vereskala.com/wp-json/vereskonto/v1/crm/tasks?view=today -H 'Authorization: Bearer TOKEN'
```

```bash
curl -s -X POST https://vereskala.com/wp-json/vereskonto/v1/crm/contacts/9/activities -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' -d '{"type":"call","direction":"out","outcome":"answered","minutes":5,"body":"قیمت عمده خواست"}'
```

## نکته‌های امنیتی برای تیم اپ

- توکن را فقط در فضای امن سیستم‌عامل ذخیره کنید و در لاگ ننویسید.
- با پاسخ **401**، کاربر را دوباره به صفحهٔ ورود ببرید.
- با پاسخ **403** با کد `vkl_crm_forbidden`، کاربر دیگر دسترسی ندارد (مثلاً نقشش عوض شده است).
- سطح دسترسی در **هر درخواست** دوباره بررسی می‌شود. پس اگر مدیر نقش یک کارمند را بردارد، توکن او همان لحظه بی‌اثر می‌شود.
