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
| `VPS_APP_PATH` | `/srv/mteatch` | ما ستستنسخُ فيه في §٥ |
| `VPS_SSH_KEY` | **المفتاحُ الخاصُّ كاملاً** | `cat ~/.ssh/id_ed25519` |
| `VPS_KNOWN_HOSTS` | بصمةُ الخادم | `ssh-keyscan -H <IP>` |

⚠️ **`VPS_KNOWN_HOSTS` ليست شكليّة.** بدونها يمسحُ العاملُ البصمةَ عندَ كلِّ
نشرةٍ ويثقُ بأيِّ خادمٍ يُجيبُ على ذلك العنوانِ في تلك اللحظة — أي أنّه **يقبلُ**
اعتراضَ الاتّصالِ بدلاً من أن يمنعَه، وهو الشيءُ الوحيدُ الذي `known_hosts`
موجودٌ لأجلِه.

⚠️ **ومفتاحُ نشرٍ مستقلٌّ أفضلُ من مفتاحِك الشخصيّ**: `ssh-keygen -t ed25519 -f
~/.ssh/mteatch_deploy -C "github-actions"` ثمّ ضعِ العامَّ في `authorized_keys`
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
git clone https://github.com/elmtamez8-hash/elmotamayez.git mteatch
cd mteatch
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
  -v mteatch_certbot-conf:/etc/letsencrypt \
  -v mteatch_certbot-www:/var/www/certbot \
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
cd /srv/mteatch
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
ssh root@<IP> 'cd /srv/mteatch && ./scripts/deploy.sh'
```

---

## ١٠ · ما وجدَه هذا التجهيزُ من أعطالٍ في الشيفرة

🤖 ثلاثةٌ، وكلُّها كانت ستُوقِفُ النشرةَ الأولى:

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

---

## ١١ · بنودٌ مفتوحة

- ⚠️ **تدويرُ `BUNNY_STREAM_ACCESS_KEY` و`BUNNY_PULL_ZONE_SECURITY_KEY`** — مفتوحٌ
  منذُ جلسةٍ سابقة. ومقيسٌ اليومَ أنّ حسابَ Bunny يرُدُّ `401` على الـAPI ونطاقَ
  السحبِ يرُدُّ صفحةَ «غير مُهيَّأ»، فتشغيلُ الفيديو معطَّلٌ حتى تُدوَّرَ ويُعادَ ربطُ
  الحساب. **النشرةُ تعملُ بدونِه؛ الفيديو وحدَه لا يعمل.**
- **النسخُ الاحتياطيّ غيرُ مُهيَّأٍ بعد.** أقلُّ ما يلزم: `mysqldump` يوميٌّ
  ومُجلَّدُ `storage` إلى تخزينٍ خارجَ الخادم. قاعدةٌ بلا نسخةٍ احتياطيّةٍ هي
  قاعدةٌ ستُفقَدُ مرّةً واحدةً فقط.
- **المراقبة**: لا تنبيهَ يقولُ إنّ الخدمةَ سقطت. `docker compose ps` يدويّاً هو
  كلُّ ما يوجدُ الآن.
