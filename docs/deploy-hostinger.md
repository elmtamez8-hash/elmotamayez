# النشرُ على Hostinger VPS — دليلٌ تنفيذيّ

> **سِجِلٌّ لا دليلٌ عام.** كلُّ أمرٍ هنا شُغِّلَ فعلاً أو مكتوبٌ ليُشغَّلَ كما هو.
> الخطواتُ مقسومةٌ بوضوح: **🤖 نُفِّذَتْ** (من الجلسة) · **👤 عليك** (لا يستطيعُ
> أحدٌ غيرُك: كلماتُ مرور، DNS، أسرارُ المستودع).

**التاريخ**: ٢٨ أغسطس ٢٠٢٦ · **النشرة**: `main`

---

## ٠ · الخريطة في سطر

```
GitHub (private)  ──push main──▶  CI (٥ بوّابات)  ──إن خضرَّتْ──▶  Deploy
                                                                    │ ssh
                                                                    ▼
                                        VPS: git reset --hard ▸ docker build ▸ migrate
                                                                    │
                              nginx :443 ──┬── /            ▶ frontend:3000  (Next standalone)
                                           ├── /api /admin  ▶ backend:9000   (PHP-FPM)
                                           ├── /app         ▶ reverb:8080    (websocket)
                                           └── /storage     ▶ ملفّاتٌ من القرص
```

خدماتٌ بلا منفذٍ عامّ: `mysql` · `redis` · `meilisearch` · `horizon` · `scheduler`.

---

## ١ · قبلَ أيِّ شيء: الأسرارُ ليست في المستودع

🤖 **فُحِصَ مرّتَين قبلَ أوّلِ دفعة**، وهذا هو الفحصُ نفسُه لتكرارِه:

```bash
git log --all --oneline -- '**/.env' '**/*.pem' '**/*.key'   # يجبُ أن يخرجَ فارغاً
git ls-files | grep -Ei '\.env$|\.pem$|\.key$'               # `.env.example` فقط
```

**النتيجة**: لا `.env` دخلَ التاريخَ قطّ، ولا قيمةَ مفتاحٍ في أيِّ ملفٍّ متتبَّع.

⚠️ **والمستودعُ خاصٌّ (private)، وذلك ليس تفضيلاً.** الشجرةُ تحملُ منطقَ التسعيرِ
والتسويةِ ونصوصَ الاختباراتِ ببياناتِ دخولٍ تجريبيّةٍ مكتوبةٍ صراحةً (`password`)،
وهي غيرُ ضارّةٍ ما دامت لا تُقرَأُ إلّا ممّن يملكُ الخادمَ أصلاً.

---

## ٢ · GitHub

👤 **الحسابُ**: الريموتُ يشيرُ إلى `elmtamez8-hash/elmotamayez` وجلسةُ `gh` الحاليّةُ
باسم `KhaledAbdulBasit`. سجِّلْ دخولَ الحسابِ الثاني في الطرفيّةِ بكتابة:

```
! gh auth login
```

(البادئةُ `!` تُشغِّلُ الأمرَ في هذه الجلسةِ فيظهرُ خرجُه هنا — وأنت من يكتبُ
بياناتِ الدخول، لا أنا.)

🤖 ثمّ الدفعُ الأوّل:

```bash
gh repo create elmtamez8-hash/elmotamayez --private --source=. --remote=origin --push
# أو، إن كان المستودعُ منشأً بالفعل:
git push -u origin main
```

⚠️ **الفرعُ `main` هو `021-course-cohort-hub` بعدَ إعادةِ تسمية** — لا شيءَ يتيمٌ،
لكنَّ الفرعَ القديمَ `master` واقفٌ عندَ `8a572bd` ولا يُدفَع.

---

## ٣ · أسرارُ الـActions

👤 من `Settings ▸ Secrets and variables ▸ Actions ▸ New repository secret`:

| الاسم | القيمة | كيف تحصلُ عليها |
|---|---|---|
| `VPS_HOST` | عنوانُ الخادم | لوحةُ Hostinger |
| `VPS_USER` | `root` أو مستخدمُ النشر | — |
| `VPS_APP_PATH` | `/srv/elmotamayez` | ما ستستنسخُ فيه في §٥ |
| `VPS_SSH_KEY` | **المفتاحُ الخاصُّ كاملاً** | `cat ~/.ssh/id_ed25519` |
| `VPS_KNOWN_HOSTS` | بصمةُ الخادم | `ssh-keyscan -H <IP>` |

⚠️ **`VPS_KNOWN_HOSTS` ليست شكليّة.** بدونها يمسحُ العاملُ البصمةَ عندَ كلِّ
نشرةٍ ويثقُ بأيِّ خادمٍ يُجيبُ على ذلك العنوانِ في تلك اللحظة — أي أنّه **يقبلُ**
اعتراضَ الاتّصالِ بدلاً من أن يمنعَه، وهو الشيءُ الوحيدُ الذي `known_hosts`
موجودٌ لأجلِه.

⚠️ **ومفتاحُ نشرٍ مستقلٌّ أفضلُ من مفتاحِك الشخصيّ**: `ssh-keygen -t ed25519 -f
~/.ssh/elmotamayez_deploy -C "github-actions"` ثمّ ضعِ العامَّ في `authorized_keys`
على الخادمِ والخاصَّ في السرّ. تسريبُ سرِّ مستودعٍ عندَها يُفقِدُك خادماً واحداً
لا كلَّ ما تصلُ إليه بمفتاحِك.

---

## ٤ · النطاق (DNS)

👤 في لوحةِ Hostinger ▸ DNS، سجلّان من نوع `A` إلى IP الخادم:

| الاسم | النوع | القيمة |
|---|---|---|
| `@` | A | `<IP>` |
| `www` | A | `<IP>` |

انتظرْ حتى يُجيبَ `dig +short example.com` بالعنوانِ الصحيح **قبلَ** §٦ —
Let's Encrypt يتحقّقُ بطلبٍ حقيقيٍّ على المنفذِ ٨٠، وشهادةٌ تُطلَبُ قبلَ انتشارِ
DNS تفشلُ وتستهلكُ من حدِّ المحاولاتِ الأسبوعيّ.

---

## ٥ · تجهيزُ الخادم (مرّةً واحدة)

👤 من طرفيّتِك: `! ssh root@<IP>` ثمّ:

```bash
# Docker من السكربتِ الرسميّ
curl -fsSL https://get.docker.com | sh

# جدارُ الحماية: SSH و80 و443 فقط
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable

# ⚠️ ذاكرةُ التبديل: بناءُ Next على ٢GB RAM يُقتَلُ بـOOM في منتصفِ `npm run build`
# ويظهرُ كـ`exit code 137` بلا سطرِ خطأٍ يُسمّي السبب.
fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab

mkdir -p /srv && cd /srv
git clone https://github.com/elmtamez8-hash/elmotamayez.git elmotamayez
cd elmotamayez
```

⚠️ **الاستنساخُ من مستودعٍ خاصٍّ يحتاجُ هُويّة**: إمّا `deploy key` (مفتاحٌ عامٌّ
للقراءةِ فقط تُضيفُه من `Settings ▸ Deploy keys` وتستنسخُ بـSSH)، أو `gh auth
login` على الخادم. مفتاحُ النشرِ أضيقُ، وهو المفضَّل.

---

## ٦ · الإعدادات والشهادة

👤 على الخادم:

```bash
cp docker/.env.prod.example docker/.env
nano docker/.env
```

املأْ: `DOMAIN` · `APP_URL` · كلماتِ مرورِ MySQL · `MEILISEARCH_KEY` ·
`REVERB_APP_*` · `REVERB_PUBLIC_*` · مفاتيحَ LiveKit وBunny · SMTP.
(`openssl rand -hex 32` يولّدُ سرّاً لكلِّ حقلٍ يحتاجُه.)

اترُكْ `APP_KEY` فارغاً الآن ثمّ:

```bash
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env \
  run --rm backend php artisan key:generate --show
# انسخِ الناتجَ (base64:...) إلى APP_KEY في docker/.env
```

⚠️ **`APP_KEY` يُولَّدُ مرّةً ولا يتغيّرُ بعدَها أبداً.** تغييرُه يُبطِلُ كلَّ
جلسةٍ وكلَّ قيمةٍ مشفَّرةٍ في قاعدةِ البيانات، بلا رجعة.

**الشهادة** — قبلَ أوّلِ إقلاعٍ كامل، لأنّ nginx يرفضُ الإقلاعَ على ملفِّ شهادةٍ
غيرِ موجود:

```bash
docker run --rm -p 80:80 \
  -v elmotamayez_certbot-conf:/etc/letsencrypt \
  -v elmotamayez_certbot-www:/var/www/certbot \
  certbot/certbot certonly --standalone \
  -d elmotamayez.tech -d www.elmotamayez.tech \
  --agree-tos -m you@example.com --non-interactive --no-eff-email
```

⚠️ **`docker run`، لا `docker compose run` — وأوّلُ محاولةٍ عُلِّقَتْ تسعَ دقائقَ
بسببِ ذلك.** خدمةُ `certbot` في ملفِّ الحزمةِ تستبدلُ `entrypoint` بحلقةِ تجديدٍ
لا نهائيّة، فوسائطُ `certonly` تمرُّ إليها **وسائطَ لِـ`sh -c` تتجاهلُها**:
الحاويةُ تُقلِعُ، والمنفذُ ٨٠ يبقى فارغاً، **ولا خطأَ في أيِّ سجلّ** — تجلسُ في
حلقةِ التجديدِ إلى الأبد. أمّا الصورةُ نفسُها فـ`entrypoint`ها `certbot`،
فتشغيلُها مباشرةً هو الصيغةُ الوحيدةُ التي تُصدِر.

بعدَها تتولّى خدمةُ `certbot` في الحزمةِ التجديدَ كلَّ اثنتَي عشرةَ ساعة.

---

## ٧ · أوّلُ إقلاع

```bash
cd /srv/elmotamayez
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env up -d --build

# الهجرات
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env \
  exec -T backend php artisan migrate --force

# ⚠️ بذرتان **مرّةً واحدةً فقط**، وهما ليستا بياناتِ عرض:
#   RolesAndPermissionsSeeder — بدونها لا دورَ ولا صلاحيّةَ على المنصّةِ إطلاقاً
#   PlatformSettingsSeeder    — الأرقامُ التشغيليّةُ التي يعدّلُها المشغِّلُ لاحقاً
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env \
  exec -T backend php artisan db:seed --class=RolesAndPermissionsSeeder --force
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env \
  exec -T backend php artisan db:seed --class=PlatformSettingsSeeder --force
```

⚠️ **ولا `DemoDataSeeder` ولا `migrate:fresh` على الإنتاج، بأيِّ حال.** الأولى
تزرعُ حساباتٍ بكلمةِ مرورٍ معروفة، والثانيةُ تُسقِطُ كلَّ جدولٍ في القاعدة.

👤 **حسابُ المدير**: أنشِئْه بيدِك ولا تزرعْه:

```bash
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env \
  exec -it backend php artisan tinker
# ثمّ داخلَ tinker، بكلمةِ مرورٍ تختارُها أنت:
#   $u = App\Models\User::create([...]); $u->is_super_admin = true; $u->save();
```

---

## ٨ · التحقّق

| الفحص | المتوقَّع |
|---|---|
| `curl -I https://example.com` | `200` وشهادةٌ صالحة |
| `curl -I http://example.com` | `301` إلى https |
| `curl https://example.com/api/v1/health` أو أيُّ نقطة | ردٌّ من Laravel لا صفحةُ Next |
| `https://example.com/admin` | لوحةُ Filament |
| `https://example.com/horizon` | العاملُ **يعملُ**، لا لوحةٌ فارغة |
| فتحُ نقاشٍ في تبويبَين | الرسالةُ تصلُ لحظيّاً (سوكِت) |
| `docker compose ... ps` | ثمانِ خدماتٍ `Up` |

⚠️ **و`/horizon` هو الفحصُ الذي يُنسى.** طابورٌ بلا عاملٍ لا يُخطئ: المهامُّ
تدخلُه ولا تُصرَف، فالإشعاراتُ لا تصلُ والتسجيلاتُ لا تُبتلَعُ وأجرُ المدرّسِ يبقى
محجوزاً — ولا شيءَ في أيِّ سجلٍّ يقولُ ذلك.

---

## ٩ · بعدَ ذلك: كلُّ نشرة

```bash
git push origin main        # هذا كلُّ شيء
```

CI يُشغِّلُ البوّاباتِ الخمس؛ إن خضرَّتْ يتّصلُ `Deploy` بالخادمِ ويُشغِّلُ
`scripts/deploy.sh`. وللنشرِ يدويّاً عندَ عطلٍ في الشبكة:

```bash
ssh root@<IP> 'cd /srv/elmotamayez && ./scripts/deploy.sh'
```

---

## ١٠ · ما وجدَه هذا التجهيزُ من أعطالٍ في الشيفرة

🤖 **عشرةٌ**، ولا واحدَ منها كان ليظهرَ في بوّابةٍ محلّيّة: جهازُ التطويرِ
يُوفِّرُ ما لا توفّرُه الصورةُ ولا العاملُ النظيف. الثلاثةُ الأولى أوقفتِ النشرةَ
الأولى، والباقي ظهرَ بعدَ أن كُتِبَت هذه الوثيقة.

1. ⚠️ **`docker/frontend.Dockerfile` ينسخُ `.next/standalone` و`next.config.ts` لم
   يكن يطلبُه.** Next لا يُخرِجُ ذلك المجلَّدَ بلا `output: "standalone"`، فالبناءُ
   يفشلُ عندَ الـ`COPY` بلا تلميحٍ إلى أنّ مفتاحَ إعدادٍ هو السبب — **الواجهةُ
   كانت غيرَ قابلةٍ للنشرِ ولا شيءَ يقولُ ذلك** حتى يُجرِّبَ أحدٌ.
2. ⚠️ **إعادةُ التوجيهِ في `next.config.ts` تُشيرُ إلى `http://localhost:8000`
   ثابتاً.** داخلَ الحاويةِ ذلك هو منفذُ الواجهةِ نفسِها ولا شيءَ يستمع، فتفشلُ
   التهيئةُ المسبقةُ بـ`ECONNREFUSED`. صارت `API_ORIGIN` بقيمةِ التطويرِ افتراضاً.
3. ⚠️ **خادمُ standalone يستمعُ على `localhost` داخلَ الحاوية** — عنوانٌ لا يصلُه
   nginx من حاويةٍ أخرى: ٥٠٢ على كلِّ صفحةٍ بينما سجلُّ الواجهةِ يقولُ `ready`.
   `HOSTNAME=0.0.0.0` في صورةِ التشغيل.

4. ⚠️ **`composer install` كان يفشلُ على `ext-intl` و`ext-exif`** — الصورةُ لم تكن
   قابلةً للبناءِ منذُ كُتِبَت. `icu-dev` + الامتدادان.
5. ⚠️ **`phpredis` امتدادُ PECL** ولا يبنيه `docker-php-ext-install`. الحاوياتُ
   الثمانِ تُقلِعُ خضراءَ ثمّ تسقطُ أوّلُ هجرةٍ بـ`Class "Redis" not found`.
6. ⚠️ **ستّةُ فهارسَ تتجاوزُ سقفَ MySQL (٦٤ حرفاً، خطأ 1059)** عبرَ أربعِ وحدات.
   **SQLite بلا سقفٍ إطلاقاً**، فكانت خضراءَ في ٢٠٩٨ اختباراً منذُ سبيك ٠٠١ —
   وكلٌّ منها كان سيقتلُ دورةَ نشرٍ مستقلّة.
7. ⚠️ **`env()` يرُدُّ `''` لا `null` لمفتاحٍ مُعلَنٍ وفارغ**، فـ`env('K', 12)`
   يُعطي `''` و`(int) '' === 0`. و`PAYMENTS_CALLBACK_MAX_ATTEMPTS=0` **يتخلّى عن
   كلِّ ردِّ دفعٍ مؤجَّلٍ عندَ أوّلِ محاولة** — مالٌ يضيعُ بلا سطرٍ في أيِّ سجلّ.
   العلاجُ `?:` لا المُعامِلُ الثاني لـ`env()`.
8. ⚠️ **بتُّ التنفيذِ لا يُحفَظُ في git على ويندوز** (`100644`) فيرُدُّ الخادمُ
   `Permission denied` على `scripts/deploy.sh`. `git update-index --chmod=+x`.
9. ⚠️ **القارئان اثنان لعنوانِ الـAPI.** `next.config.ts` يقرأُ `API_ORIGIN`
   لإعادةِ الكتابة — **وهي لا تنطبقُ على جلبٍ صادرٍ من كودِ الخادمِ أصلاً** —
   بينما `lib/public-api.ts` يقرأُ `MARKETPLACE_API_URL`. ضبطُ واحدٍ من اثنَين
   يبدو كإصلاحٍ ولا يُصلِحُ شيئاً.
10. ⚠️ **`User` لم يكن يُنفِّذُ `FilamentUser`، فلوحةُ `/admin` ترفضُ كلَّ تسجيلِ
    دخولٍ خارجَ `local`.** الشرطُ في `Filament\Http\Middleware\Authenticate` هو
    «البيئةُ ليست local» — صحيحٌ دائماً على الخادم. والفحصُ الدخانيُّ أعمى عنه
    (`/admin` يرُدُّ ٣٠٢ قبلَ المصادقة)، **وكلُّ اختبارِ لوحةٍ في الشجرةِ يبدأُ
    بـ`config(['app.env' => 'local'])`** — الحيلةُ التي أخضرتِ الاختباراتِ هي
    نفسُها التي أخفتِ العطل. حارسُه `PanelAccessOutsideLocalTest`، ويُشترَطُ
    ألّا يُثبِّتَ البيئةَ.
11. ⚠️ **`.env.prod.example` كان يُسمّي مفتاحَين لا يقرؤهما أحد**:
    `BUNNY_LIBRARY_ID` و`BUNNY_PULL_ZONE_HOSTNAME`، بينما `config/media.php`
    يقرأُ `BUNNY_STREAM_LIBRARY_ID` و`BUNNY_PULL_ZONE`. من يملأُ القيمتَين
    يحصلُ على إعدادٍ يبدو مكتملاً وفيديو لا يعملُ بحال. وكتلةُ
    `LIVEKIT_EGRESS_*` كانت غائبةً تماماً — وبدونها **يُحجَزُ أجرُ المدرّس**
    لكلِّ حصّةٍ لا يُحفَظُ تسجيلُها.

---

## ١١ · بنودٌ مفتوحة

- ⚠️ **تدويرُ `BUNNY_STREAM_ACCESS_KEY` و`BUNNY_PULL_ZONE_SECURITY_KEY`** — مفتوحٌ
  منذُ جلسةٍ سابقة. ومقيسٌ اليومَ أنّ حسابَ Bunny يرُدُّ `401` على الـAPI ونطاقَ
  السحبِ يرُدُّ صفحةَ «غير مُهيَّأ»، فتشغيلُ الفيديو معطَّلٌ حتى تُدوَّرَ ويُعادَ ربطُ
  الحساب. **النشرةُ تعملُ بدونِه؛ الفيديو وحدَه لا يعمل.**
- **النسخُ الاحتياطيّ**: `scripts/backup.sh` يوميّاً في `cron` (٣:١٧ فجراً)،
  قاعدةٌ + مرفوعات، احتفاظٌ ١٤ يوماً، في `/srv/backups`. ⚠️ **على نفسِ القرص**:
  يحمي من الحذفِ الخاطئِ ومن هجرةٍ فاسدة، **ولا يحمي من ضياعِ الخادم**. النقلُ
  خارجَه يحتاجُ وجهةً (لقطةُ Hostinger أو `rclone`) — بندٌ مفتوح.
- **المراقبة**: لا تنبيهَ يقولُ إنّ الخدمةَ سقطت. `docker compose ps` يدويّاً هو
  كلُّ ما يوجدُ الآن.

---

## ١٢ · الأسرارُ ومزوّدو الفيديو والبثّ

🤖 **ما يُدوَّرُ على الخادمِ نفسِه** (فعلتُه؛ صفرُ مستخدمين وقتَها فكان مجّانيّاً —
ولن يكونَ كذلك مرّةً أخرى): `APP_KEY` · `REVERB_APP_ID/KEY/SECRET` ·
`MEILISEARCH_KEY` · `DB_PASSWORD` · `DB_ROOT_PASSWORD`.

⚠️ **`APP_KEY` يُشفِّرُ `user_security_settings`** (سرُّ التحقّقِ بخطوتَين ورموزُ
الاستعادة). تدويرُه بعدَ أن يُفعِّلَ أحدٌ التحقّقَ بخطوتَين **يُتلِفُ تلك الصفوفَ بلا
رجعةٍ وبلا خطأ**. اليومَ الجداولُ فارغة؛ بعدَها لا يُدوَّرُ إلّا مع خطّةِ ترحيل.

⚠️ **`REVERB_APP_KEY` مدموجٌ في حزمةِ الواجهةِ وقتَ البناء** (`NEXT_PUBLIC_*`)،
فتدويرُه يستلزمُ إعادةَ بناءِ الواجهة — وإلّا بقيَ المتصفّحُ يُقدِّمُ مفتاحاً ميّتاً
ولا يظهرُ إلّا كـ«النقاشُ لا يظهرُ لحظيّاً».

⚠️ **`ALTER USER` لثلاثةِ حسابات** — `elmotamayez@%` و`root@%` و`root@localhost` —
لأنّ صورةَ MySQL تُنشئُ الحساباتِ **عندَ التهيئةِ الأولى فقط**؛ تغييرُ `.env` وحدَه
لا يفعلُ شيئاً. **وتُعادُ تهيئةُ حاويةِ mysql بعدَها**: فحصُها الصحّيُّ يحملُ
`-p${DB_ROOT_PASSWORD}` المدموجَ وقتَ الإنشاء، فيصيرُ `unhealthy` ويمنعُ
`service_healthy` كلَّ إقلاعٍ لاحقٍ للواجهةِ الخلفيّة.

👤 **ما يُدوَّرُ عندَ المزوّدِ ولا أستطيعُه**: LiveKit (API key/secret) ·
Cloudflare R2 (access key) · Bunny (Stream AccessKey و Pull Zone security key).
تُلغى من لوحةِ كلِّ مزوّدٍ وتُصدَرُ جديدةً، ثمّ:

```bash
ssh root@<server> 'cd /srv/elmotamayez && bash scripts/set-secrets.sh'
```

يسألُ عن كلِّ مفتاحٍ بـ`read -rs` (لا يُطبَعُ ولا يدخلُ `history` ولا يظهرُ في
`ps`)، **يتحقّقُ أنّ `LIVEKIT_URL` يبدأُ بـ`wss://`**، ولا يُبدِّلُ مزوّداً إلّا
باكتمالِ مفاتيحِه — مزوّدٌ مُعلَنٌ بمفتاحٍ فارغٍ أسوأُ من مزوّدٍ مُعطَّل: الرفعُ
يُقبَلُ ثمّ يفشلُ عندَ الطرفِ الآخَر. ثمّ يُعيدُ تشغيلَ ما يقرأُ المفاتيح.

⚠️ **يُشغَّلُ من طرفيّةِ صاحبِ الخادم**، لا عبرَ مساعدٍ ولا في محادثة: قيمةٌ تُكتَبُ
في نافذةِ حوارٍ هي قيمةٌ محفوظةٌ في سجلِّ تلك المحادثة.
