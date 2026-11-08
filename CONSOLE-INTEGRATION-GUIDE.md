# راهنمای اتصال پلاگین HubPilot به کنسول

نسخه پلاگین: `0.4.1`  
هدف: پلاگین در حالت عادی فقط آمار بازدید را نگه می‌دارد؛ به‌محض اتصال به کنسول، داشبورد همان سایت علاوه بر آمار، **آخرین تست سرعت موبایل/دسکتاپ، آخرین تست امنیت و داده‌های پایش** را از کنسول نشان می‌دهد. ذخیره‌سازی ابری هم اختیاری است و هیچ ردیف آماری در دیتابیس سایت نوشته نمی‌شود.

از نسخه ۰.۴.۰ هر بازدید یک **درجه کیفیت** دارد و آمار در چهار حالت نمایش داده می‌شود: `verified` (پیش‌فرض، User-Agent سالم + تأیید رفتار مرورگر)، `all`، `nojs` و `suspect`. تشخیص دیتاسنتر در کنسول انجام می‌شود، نه در پلاگین.

---

> آدرس کنسول در نسخهٔ عمومی پلاگین ثابت است و از پنل قابل تغییر نیست. برای کنسول خودمیزبان یا محیط توسعه، در `wp-config.php` مقدار `WPHS_CONSOLE_URL` را تعریف کنید؛ در پنل فقط کلید اتصال سایت وارد می‌شود.

## ۱) سه endpoint که پلاگین صدا می‌زند

| Method | Path | Auth | کاربرد |
|---|---|---|---|
| GET | `/api/v1/analytics/ping` | `Authorization: Bearer <site_token>` | تست اتصال از تنظیمات پلاگین |
| GET | `/api/v1/site/overview` | `Authorization: Bearer <site_token>` | داده‌ی داشبورد: سرعت، امنیت، پایش و اختیاری آمار |
| POST | `/api/v1/analytics/ingest` | `Authorization: Bearer <site_token>` + HMAC | دریافت دسته‌های رویداد آماری از سایت |

پلاگین **بدون توکن** هیچ‌کدام از این‌ها را صدا نمی‌زند؛ داشبورد در این حالت فقط خلاصه‌ی آمار محلی است.

---

## ۲) کلید اتصال سایت (مهم‌ترین قدم)

از `verification_token` سایت برای این کار استفاده نکنید؛ آن توکن عمومی و مخصوص تایید دامنه است.

### ۲.۱. ستون جدید در جدول websites

در `backend/app/db/init_db.py`، بعد از ساخت جدول `websites` یا در بخش migration:

```sql
ALTER TABLE websites ADD COLUMN analytics_token TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS idx_websites_analytics_token
  ON websites(analytics_token);
```

با همان الگوی `PRAGMA table_info(websites)` که در فایل هست، فقط اگر ستون وجود نداشت اجرا شود.

### ۲.۲. تولید توکن

دو روش قابل قبول:

- **پیشنهادی:** یک endpoint برای ساخت/نمایش توکن در صفحه سایت:
  - `POST /api/v1/websites/{website_id}/analytics-token`
  - با احراز هویت کاربر عادی و مالکیت همان سایت.
  - اگر توکن خالی است، با `secrets.token_urlsafe(32)` بسازید و **فقط همان یک بار** به کاربر نشان دهید.
  - امکان rotate/revoke هم در نظر بگیرید.
- یا هنگام `add_website` توکن را بسازید و در `WebsiteRepository.create...` ذخیره کنید.

در هر دو حالت توکن را مثل رمز عبور تلقی کنید: HTTPS، عدم نمایش در لاگ‌ها و امکان باطل‌کردن.

### ۲.۳. خواندن سایت با توکن

در `website_repository.py`:

```python
@staticmethod
def get_by_analytics_token(token: str):
    token = (token or "").strip()
    if not token:
        return None
    with get_db_session() as conn:
        row = conn.execute(
            "SELECT * FROM websites WHERE analytics_token = ?",
            (token,)
        ).fetchone()
        return dict(row) if row else None
```

در `api/deps.py`:

```python
from fastapi import Request, HTTPException, status

def get_site_by_token(request: Request) -> dict:
    auth = request.headers.get("Authorization", "")
    if not auth.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="missing site token")
    token = auth[7:].strip()
    site = WebsiteRepository.get_by_analytics_token(token)
    if not site:
        raise HTTPException(status_code=401, detail="invalid site token")
    if not site.get("is_verified"):
        raise HTTPException(status_code=403, detail="site is not verified")
    return site
```

اگر می‌خواهید توکن را هش‌شده ذخیره کنید، همین‌جا هش را مقایسه کنید؛ ولی کاربر باید یک بار توکن خام را دریافت کند.

---

## ۳) Ping

فایل جدید: `backend/app/api/v1/analytics.py`

```python
from fastapi import APIRouter, Depends
from backend.app.api.deps import get_site_by_token

router = APIRouter()

@router.get("/ping")
async def analytics_ping(site: dict = Depends(get_site_by_token)):
    return {
        "success": True,
        "site": {
            "id": site["id"],
            "domain": site["domain"],
            "verified": bool(site["is_verified"]),
        },
        "message": "ok",
    }
```

در `router.py`:

```python
api_v1_router.include_router(analytics_router, prefix="/analytics", tags=["Analytics"])
```

---

## ۴) Overview — همان چیزی که داشبورد پلاگین نشان می‌دهد

فایل پیشنهادی: `backend/app/api/v1/site.py`

```python
router = APIRouter()

@router.get("/overview")
async def site_overview(site: dict = Depends(get_site_by_token)):
    ...
```

### ۴.۱. آخرین تست سرعت

جدول `speed_audits` فیلد `website_id` ندارد؛ با `user_id` و `domain` سایت کار کنید:

```sql
SELECT strategy, performance_score, fcp_ms, lcp_ms, cls, tbt_ms,
       speed_index_ms, created_at
FROM speed_audits
WHERE user_id = ? AND domain = ?
ORDER BY created_at DESC;
```

- اولین ردیف با `strategy = 'mobile'` و اولین ردیف با `strategy = 'desktop'` را بردارید.
- اگر هیچ ردیفی نبود، `speed.mobile` و/یا `speed.desktop` را `null` بگذارید.

خروجی بخش سرعت:

```json
"speed": {
  "mobile": {
    "performance_score": 47,
    "fcp_ms": 2100,
    "lcp_ms": 3200,
    "cls": 0.18,
    "tbt_ms": 480,
    "speed_index_ms": 4100,
    "created_at": "2026-11-03T10:00:00"
  },
  "desktop": { "...": "..." }
}
```

### ۴.۲. آخرین تست امنیت

```sql
SELECT security_score, grade, headers_score, ssl_score,
       vulnerabilities_score, raw_json, created_at
FROM security_audits
WHERE website_id = ?
ORDER BY created_at DESC
LIMIT 1;
```

- `critical` / `high` / `medium` / `low` اختیاری‌اند؛ اگر در `raw_json` قابل استخراج بودند اضافه کنید، وگرنه حذفشان کنید (پلاگین نبودشان را تحمل می‌کند).
- خروجی:

```json
"security": {
  "security_score": 68,
  "grade": "C",
  "headers_score": 72,
  "ssl_score": 90,
  "vulnerabilities_score": 55,
  "critical": 0,
  "high": 2,
  "medium": 5,
  "low": 4,
  "created_at": "2026-11-02T18:00:00",
  "url": "https://console.wphub.org/sites/123/security/456"
}
```

اگر تست امنیتی وجود ندارد، مقدار `security` را `null` بدهید.

### ۴.۳. پایش و مانیتورینگ

از فیلدهای خود `websites` و چک‌های ۲۴ ساعت اخیر:

```python
row = conn.execute("""
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) AS up_count,
           AVG(ttfb_ms) AS avg_ttfb
    FROM uptime_checks
    WHERE website_id = ?
      AND checked_at >= datetime('now', '-1 day')
""", (site["id"],)).fetchone()

total = row["total"] or 0
uptime_24h = round((row["up_count"] / total) * 100, 1) if total else None
```

خروجی:

```json
"monitoring": {
  "status": "up",
  "uptime_24h": 99.8,
  "avg_ttfb_ms": 420,
  "ssl_valid": true,
  "ssl_days_remaining": 45,
  "last_check": "2026-11-03T12:30:00",
  "url": "https://console.wphub.org/sites/123/monitoring"
}
```

- `status` از `websites.uptime_status` یا آخرین چک.
- `last_check` از `websites.last_uptime_check`.
- اگر مانیتورینگ فعال نیست، `monitoring` را `null` بدهید.

### ۴.۴. آمار از کنسول (اختیاری)

پلاگین اگر این بخش را برگردانید، در حالت ابری یک کارت «آمار از کنسول» نشان می‌دهد:

```json
"analytics": {
  "pageviews": 1234,
  "visitors": 856,
  "sessions": 970,
  "bounces": 360,
  "engaged": 84000,
  "range_days": 7,
  "mode": "verified"
}
```

- `bounce_rate` یا `avg_engaged` هم اگر داشته باشید، پلاگین همان را ترجیح می‌دهد.
- `mode` پیش‌فرض `verified` است؛ پلاگین برای کارت آمار کنسول همین حالت تأییدشده را نشان می‌دهد.
- تا وقتی جدول rollup آماری در کنسول نساخته‌اید، این بخش را `null` بدهید؛ داشبورد ابری همچنان صف/وضعیت ارسال را نشان می‌دهد.

### ۴.۵. آمار چندحالته در endpoint‌های کنسول

endpoint آمار اختصاصی کنسول `GET /api/v1/analytics/sites/{website_id}/stats` پارامتر `mode` را می‌پذیرد:

| mode | معنی |
|---|---|
| `verified` (پیش‌فرض) | User-Agent سالم + تأیید رفتار مرورگر؛ بدون دیتاسنتر |
| `all` | همه بازدیدها شامل تأییدشده، بدون JS، مشکوک و دیتاسنتر |
| `nojs` | User-Agent سالم اما بیکن JS دریافت نشده |
| `suspect` | رفتار مرورگر رد شده یا IP دیتاسنتر بوده |

پاسخ علاوه بر `totals`، یک بخش `quality` هم برمی‌گرداند:

```json
"quality": {
  "total_pageviews": 1234,
  "verified_pageviews": 1100,
  "verified_share": 89.1,
  "groups": [
    {"key": "verified", "label": "تأییدشده", "pageviews": 1100, "visitors": 800, "share": 89.1},
    {"key": "nojs", "label": "بدون JS", "pageviews": 80, "visitors": 50, "share": 6.5},
    {"key": "suspect", "label": "مشکوک", "pageviews": 34, "visitors": 20, "share": 2.8},
    {"key": "datacenter", "label": "دیتاسنتر", "pageviews": 20, "visitors": 12, "share": 1.6},
    {"key": "legacy", "label": "قبل از تفکیک", "pageviews": 0, "visitors": 0, "share": 0}
  ]
}
```

### ۴.۶. پاسخ نهایی

```json
{
  "success": true,
  "site": { "domain": "example.com", "url": "https://example.com" },
  "speed": { "mobile": {...}, "desktop": {...} },
  "security": {...},
  "monitoring": {...},
  "analytics": null
}
```

پلاگین این پاسخ را **۱۰ دقیقه** کش می‌کند. برای دیدن فوری تغییرات، ترنزینت `wphs_site_overview` را پاک کنید یا در نسخه بعدی دکمه‌ی refresh اضافه شود.

---

## ۵) Ingest — دریافت دسته‌های آماری

پلاگین هر ۵ دقیقه (در حالت ابری) یک batch از رویدادهای خام را می‌فرستد.

### ۵.۱. هدرها

```
Authorization: Bearer <site_token>
Content-Type: application/json
Content-Encoding: gzip
X-WPHS-Timestamp: <unix seconds>
X-WPHS-Signature: sha256=<hex>
X-WPHS-Site: example.com
```

امضا:

```python
expected = "sha256=" + hmac.new(
    site["analytics_token"].encode(),
    ts.encode() + b"." + raw_body,
    hashlib.sha256
).hexdigest()
```

- `raw_body` باید **بدنه‌ی خام و gzip‌شده** باشد.
- `ts` حداکثر ۵ دقیقه اختلاف داشته باشد.
- بعد از بررسی امضا، اگر `Content-Encoding: gzip` بود، `gzip.decompress(raw_body)` کنید.
- اگر پروکسی/Nginx بدنه را قبل از رسیدن به برنامه از gzip درآورد، امضا دیگر معتبر نیست؛ مسیر را طوری تنظیم کنید که بدنه‌ی خام به برنامه برسد (یا در Nginx `gunzip off` و پاس‌دادن `Content-Encoding`).

### ۵.۲. بدنه پس از از gzip درآوردن

```json
{
  "schema": 1,
  "batch_id": "uuid",
  "site": "https://example.com/",
  "site_host": "example.com",
  "plugin_version": "0.4.1",
  "wp_version": "7.1.4",
  "timezone": "Asia/Tehran",
  "sent_at": 1790000000,
  "events": [
    {
      "eid": "32-hex",
      "k": "pv",
      "h": "32-hex-server-hit",
      "c": "",
      "src": "server",
      "u": "/",
      "ti": "...",
      "r": "google.com",
      "ch": "search",
      "d": "desktop",
      "b": "chrome",
      "o": "linux",
      "cc": "IR",
      "v": "32-hex-visitor-daily-hash",
      "s": "32-hex-session",
      "pid": 20,
      "g": "u",
      "ip": "203.0.113.10",
      "t": 1790000000
    },
    {
      "eid": "32-hex",
      "k": "pv",
      "h": "32-hex-server-hit",
      "c": "32-hex-client",
      "src": "js",
      "u": "/",
      "ti": "...",
      "r": "google.com",
      "ch": "search",
      "d": "desktop",
      "b": "chrome",
      "o": "linux",
      "cc": "IR",
      "v": "32-hex-visitor-daily-hash",
      "s": "32-hex-session",
      "pid": 0,
      "g": "v",
      "t": 1790000000
    }
  ]
}
```

- `ip` فقط روی رویداد `src=server` و فقط وقتی حالت ابری + گزینه «ارسال IP به کنسول» فعال باشد قرار می‌گیرد. کنسول باید آن را فقط برای تشخیص دیتاسنتر/کشور استفاده کند و **در دیتابیس ذخیره نکند**.
- `g` درجه اولیه است: `u` برای رویداد سرور، `v`/`s` برای رویداد JS. کنسول در صورت تشخیص دیتاسنتر آن را به `d` تغییر می‌دهد.

### ۵.۳. ذخیره و idempotency

ستون‌های مهم جدول کنسول:

```sql
CREATE TABLE IF NOT EXISTS analytics_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  website_id INTEGER NOT NULL,
  eid TEXT NOT NULL,
  batch_id TEXT,
  kind TEXT NOT NULL,
  source TEXT,
  hit_id TEXT,
  client_id TEXT,
  path TEXT,
  title TEXT,
  referrer TEXT,
  channel TEXT,
  device TEXT,
  browser TEXT,
  os TEXT,
  country TEXT,
  visitor_hash TEXT,
  session_hash TEXT,
  page_id INTEGER,
  seq INTEGER,
  seconds INTEGER,
  grade TEXT,
  datacenter INTEGER DEFAULT 0,
  event_ts INTEGER NOT NULL,
  event_day TEXT NOT NULL,
  event_hour INTEGER,
  received_at TEXT NOT NULL,
  event_json TEXT NOT NULL,
  UNIQUE(website_id, eid)
);
CREATE INDEX IF NOT EXISTS idx_analytics_hit
  ON analytics_events(website_id, hit_id);
CREATE INDEX IF NOT EXISTS idx_analytics_kind_grade
  ON analytics_events(website_id, kind, event_day, grade);
```

- `INSERT OR IGNORE` بزنید؛ `accepted` = تعداد ردیف‌های واقعاً درج‌شده.
- پاسخ موفق: `{"success": true, "accepted": N}`.
- اگر `batch_id` تکراری بود، همان batch را دوباره پردازش نکنید.
- قبل از `json.dumps(event)` برای ستون `event_json`، کلیدهای `ip` و `bs` را حذف کنید. IP خام نباید در دیتابیس بماند.

### ۵.۴. معناشناسی رویدادها و درجه کیفیت

- `k = pv` با `src = server`: بازدید سمت سرور. `g = u` (User-Agent سالم، هنوز JS تایید نکرده).
- `k = pv` با `src = js`: تایید همان بازدید از سمت مرورگر (`h` مشترک). `g = v` (سیگنال‌های مرورگر تایید شد) یا `g = s` (رد شد). این دو نباید دو بازدید شمرده شوند.
- `k = eng`: تعامل (`c` = شناسه مرورگر، `sq` = شماره ترتیب). برای هر `c` فقط `sq`های بزرگ‌تر از آخرین مقدار را اعمال کنید (idempotent).
- **هم‌ترازی درجه:** اگر برای یک `hit_id` هم رویداد server و هم JS دارید، درجه هر دو ردیف را یکسان کنید. اگر JS `v` است، ردیف server هم `v` شود؛ اگر JS `s` است، server هم `s` شود. این کار باعث می‌شود فیلتر `grade` هر بازدید را فقط یک بار بشمارد.
- **دیتاسنتر:** اگر IP رویداد server در لیست CIDR دیتاسنتر بود، هر دو ردیف همان `hit_id` را `grade = d` و `datacenter = 1` کنید. حالت `verified` پیش‌فرض این بازدیدها را نشان نمی‌دهد.
- **بدون JS:** اگر بعد از حدود ۱۰ دقیقه رویداد JS نرسید، ردیف server با `u` می‌ماند و در حالت `nojs` دیده می‌شود.
- **قدیمی‌ها:** برای ردیف‌های قبلی که ستون `grade` ندارند، پس از migration مقدار `v` برای `source=js` و `u` برای `source=server` بگذارید و ردیف‌های server دارای JS را به `v` ارتقا دهید.

### ۵.۴.۱. تشخیص دیتاسنتر در کنسول

پلاگین خودش IP یا دیتاسنتر را تشخیص نمی‌دهد. کنسول این کار را با فایل محلی CIDR انجام می‌دهد:

- فایل تولیدشده: `backend/app/data/datacenter_cidrs.txt`
- فایل دستی: `backend/app/data/datacenter_cidrs_manual.txt`
- متغیر محیطی اختیاری: `ANALYTICS_DATACENTER_CIDRS` (چند CIDR با کاما)
- مسیر جایگزین فایل: `ANALYTICS_DATACENTER_CIDR_FILE`
- پایگاه GeoIP اختیاری: `ANALYTICS_GEOIP_DB` (MaxMind GeoLite2/GeoIP2؛ برای فعال‌شدن، پکیج `geoip2` را نصب کنید: `pip install geoip2`)

به‌روزرسانی لیست:

```bash
python3 backend/scripts/refresh_datacenter_ranges.py
# در صورت نیاز به IPv6:
python3 backend/scripts/refresh_datacenter_ranges.py --include-ipv6
```

لیست پیش‌فرض از ASNهای سرویس‌دهنده‌های ابری/هاستینگ (AWS، GCP، Azure، DigitalOcean، Hetzner، OVH، Vultr، Contabo، Linode، Oracle، Alibaba، Tencent و…) ساخته می‌شود. CDNهای عمومی (Cloudflare/Fastly/Akamai edge) عمداً داخل لیست نیستند تا ترافیک واقعی پشت CDN اشتباهاً دیتاسنتر شمرده نشود.

کشور از این ترتیب انتخاب می‌شود: کد `cc` ارسالی پلاگین (از `CF-IPCountry` یا GeoIP هاست) → GeoIP کنسول (اگر `ANALYTICS_GEOIP_DB` تنظیم شده باشد). IP خام در هیچ ستونی ذخیره نمی‌شود.

### ۵.۵. Rate limit

- حداکثر ۲۵۰ رویداد در هر درخواست (پلاگین بیش از این نمی‌فرستد).
- پاسخ 401/403 برای توکن/امضای نامعتبر.
- پاسخ 413 برای حجم زیاد.
- پاسخ 429 با `Retry-After` (پلاگین ۱۵ دقیقه صبر می‌کند).
- پاسخ 5xx برای خطای موقت (پلاگین با backoff تصاعدی تلاش می‌کند).

---

## ۶) Nginx و سرور کنسول

```nginx
client_max_body_size 5m;

location /api/v1/analytics/ingest {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    # گzip درخواستی را به برنامه پاس بده؛ خود برنامه بعد از امضا باز می‌کند.
    proxy_set_header Content-Encoding $http_content_encoding;
    proxy_request_buffering on;
}
```

- HTTPS اجباری.
- اگر از rate limit استفاده می‌کنید، کلید آن را `analytics_token` یا IP قرار دهید.
- برای endpointهای site/overview هم همان proxy setting کافی است.

---

## ۷) سمت هاست وردپرس

- پلاگین در حالت ابری به `wphs_cloud_sync` نیاز دارد. اگر `DISABLE_WP_CRON` فعال است، cron واقعی:
  ```bash
  */5 * * * * cd /path/to/wordpress && wp cron event run wphs_cloud_sync --path=/path/to/wordpress
  ```
- خروجی HTTPS از هاست به دامنه کنسول باید باز باشد.
- در حالت ابری هیچ ردیف آماری در DB سایت نوشته نمی‌شود؛ فقط صف موقت فایل. اگر داده‌ی محلی قدیمی دارید، در تنظیمات/داشبورد ابری دکمه‌ی «پاک‌کردن داده‌های محلی» هست.
- کش تمام‌صفحه: بعد از فعال‌سازی یک‌بار پاک شود تا اسکریپت ردیاب در صفحات کش‌شده قرار بگیرد.

---

## ۸) تست سریع

```bash
TOKEN="paste-site-token"

curl -s -H "Authorization: Bearer $TOKEN" \
  https://console.wphub.org/api/v1/analytics/ping

curl -s -H "Authorization: Bearer $TOKEN" \
  https://console.wphub.org/api/v1/site/overview

# در پلاگین: تنظیمات → فقط کلید اتصال سایت را وارد کنید (آدرس کنسول در کد ثابت است) → تست اتصال
# سپس داشبورد: باید کارت‌های «وضعیت سایت» و سرعت/امنیت/پایش دیده شوند.
```

برای تست ingest، از لاگ خود پلاگین/کنسول استفاده کنید؛ در محیط توسعه می‌توانید یک سرور mock مثل همان تست پلاگین بسازید که بدنه‌ی gzip و امضای HMAC را بررسی کند.

---

## ۹) چک‌لیست پیاده‌سازی

- [x] مهاجرت ستون `websites.analytics_token` + ایندکس یکتا
- [x] تولید/نمایش/rotate توکن در صفحه سایت کنسول
- [x] `WebsiteRepository.get_by_analytics_token`
- [x] `get_site_by_token` در `api/deps.py`
- [x] router آنالیتیکس: ping + ingest
- [x] router site: overview (سرعت، امنیت، پایش، آمار اختیاری)
- [x] include کردن routerها در `api/v1/router.py`
- [x] تنظیم Nginx برای بدنه‌ی gzip و محدودیت حجم/نرخ
- [x] cron هاست وردپرس برای `wphs_cloud_sync`
- [x] مهاجرت `grade` و `datacenter` در `analytics_events`
- [x] تشخیص دیتاسنتر با CIDR محلی + فایل دستی + GeoIP اختیاری
- [x] فیلتر `mode` در همه‌ی گزارش‌ها و `quality` در پاسخ آمار
- [x] سیگنال‌های رفتار مرورگر در `tracker.js` و امتیازدهی سمت سرور
- [x] انتخاب حالت و کارت کیفیت در داشبورد پلاگین و کنسول
- [ ] تست end-to-end با توکن واقعی و ترافیک واقعی
- [ ] بررسی دوره‌ای لیست CIDR (مثلاً ماهانه) با اسکریپت refresh

---

## ۱۰) امنیت و حریم خصوصی

- در حالت محلی، پلاگین IP خام یا User-Agent خام را ذخیره یا ارسال نمی‌کند؛ فقط هش روزانه‌ی بازدیدکننده، مسیر، عنوان، هاست ارجاع و ابعاد دستگاه/مرورگر.
- در حالت ابری، اگر گزینه «ارسال IP به کنسول» فعال باشد، IP بازدیدکننده همراه رویداد `src=server` و فقط در ارتباط HTTPS سرور به سرور ارسال می‌شود. کنسول آن را فقط برای تشخیص دیتاسنتر/کشور استفاده می‌کند و **در هیچ ستون یا `event_json` ذخیره نمی‌کند**. برای غیرفعال‌کردن این ارسال، گزینه را در تنظیمات پلاگین خاموش کنید؛ در این حالت تشخیص دیتاسنتر در کنسول انجام نمی‌شود.
- User-Agent خام همچنان ارسال نمی‌شود؛ فقط سیگنال‌های فشرده رفتار مرورگر برای امتیازدهی استفاده می‌شوند.
- توکن سایت را محرمانه نگه دارید؛ در صورت لو رفتن، rotate کنید.
- امضای HMAC و timestamp را الزامی کنید؛ replay را رد کنید.
- صف موقت سایت را بعد از ارسال موفق پاک کنید و سقف ۲۵MB را جدی بگیرید.
- داده‌های کنسول را طبق سیاست نگهداری خودتان (روزانه/ماهانه) پاک کنید.
- لیست CIDR دیتاسنتر را دوره‌ای به‌روزرسانی کنید؛ لیست قدیمی به‌مرور دقت را کم می‌کند.

---

## ۱۱) وضعیت فعلی پلاگین

- نسخه `0.4.1`.
- داشبورد متصل: کارت‌های سرعت (موبایل/دسکتاپ)، امنیت و پایش + آمار محلی.
- داشبورد ابری: همان کارت‌ها + آمار اختیاری کنسول + صف ارسال.
- داشبورد بدون توکن: فقط خلاصه‌ی آمار محلی (همان چیزی که برای مخزن هم مناسب است).
- ارسال ابری با gzip/HMAC/backoff/سقف صف تست شده است.
- تشخیص ربات: فیلتر User-Agent + سیگنال‌های رفتار مرورگر (webdriver، صفحه‌نمایش، پلاگین‌ها، WebGL، rAF، تعامل، زمان لود و…) با امتیازدهی سمت سرور.
- آمار چهارحالته: `verified` (پیش‌فرض)، `all`، `nojs`، `suspect`؛ حالت دیتاسنتر در کنسول اضافه می‌شود.
- تشخیص دیتاسنتر/کشور در کنسول با CIDR محلی + فایل دستی + GeoIP اختیاری؛ پلاگین خودش IP را تشخیص نمی‌دهد.
- سمت کنسول endpointهای ping/ingest/stats، مهاجرت grade/datacenter و تست‌های quality/mode پیاده‌سازی شده‌اند.
