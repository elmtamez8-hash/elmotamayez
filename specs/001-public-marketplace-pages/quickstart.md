# Quickstart — التحقق من سوق المدرّسين العام

**Feature**: `001-public-marketplace-pages` · **Date**: 2026-08-01

دليل تشغيل وتحقّق. تفاصيل الحقول في [data-model.md](./data-model.md)
والعقود في [contracts/](./contracts/).

---

## المتطلبات

```powershell
cd D:\mteatch\backend
composer install
php artisan migrate:fresh --seed
php artisan storage:link          # مرة واحدة لكل نسخة

cd D:\mteatch\frontend
npm install
```

**التشغيل** (نافذتان):

```powershell
cd D:\mteatch\backend;  php artisan serve       # :8000
cd D:\mteatch\frontend; npm run dev             # :3000
```

نافذة ثالثة عند اختبار إعادة احتساب درجة الثقة (تعمل في الطابور):

```powershell
cd D:\mteatch\backend; php artisan queue:listen
```

> ⚠️ لا تشغّل `npm run build` والـ `dev` معاً — كلاهما يكتب في `.next/`.

---

## البوابات الأربع (يجب أن تمرّ كلها)

```powershell
cd D:\mteatch\backend
php vendor/bin/pest
./vendor/bin/pint --test
./vendor/bin/phpstan analyse

cd D:\mteatch\frontend
npx tsc --noEmit
npx playwright test              # الصفحات العامة السبع + فحص axe
```

---

## سيناريو 1 — الحارس البديل لعزل المستأجرين (الأهم)

يثبت FR-007 و SC-009. **إن فشل هذا السيناريو تتوقّف الميزة**، لأن
`WorkspaceScope` معطّل أمام الزوار (research R1).

```powershell
cd D:\mteatch\backend
php vendor/bin/pest tests/Feature/Marketplace/PublicExposureTest.php
```

**المتوقع** — ثلاث مجموعات تأكيدات:

| ما يُختبر | المتوقع |
|---|---|
| مدرّس معتمد في مساحة عمل **مشتركة** | يظهر في `GET /api/v1/marketplace/teachers` |
| مدرّس معتمد في مساحة عمل **غير مشتركة** | لا يظهر · رابطه المباشر يعيد **404** |
| مدرّس بحالة `pending` أو `suspended` | لا يظهر · رابطه المباشر يعيد **404** |
| مدرّسون من **3 مساحات عمل مختلفة** | الثلاثة في قائمة واحدة (Q2=A) |
| أي استجابة عامة | لا `email` ولا `phone` ولا `workspace_id` ولا `id` تسلسلي |
| انسحاب مساحة عمل من السوق | كل عناصرها تختفي خلال ≤ 60 ثانية |

التحقق اليدوي من غياب الحقول الخاصة:

```powershell
curl -s http://localhost:8000/api/v1/marketplace/teachers | Select-String -Pattern '"email"|"phone"|"workspace_id"'
# المتوقع: لا نتائج
```

---

## سيناريو 2 — الاكتشاف من طرف الزائر (القصة P1)

1. افتح `http://localhost:3000/` في **نافذة تصفّح خفي** (بلا جلسة).
2. تأكّد من `<html lang="ar" dir="rtl">` وأن كل الأقسام التسعة ظاهرة بترتيب المخطط.
3. انقر مادة من شبكة المواد ← ينتقل إلى `/teachers?subject=…` والفلتر ظاهر كوسم.
4. طبّق فلتر سعر ← تتحدّث النتائج وينعكس الفلتر في عنوان الصفحة.
5. **انسخ الرابط وافتحه في نافذة أخرى** ← نفس النتائج بالضبط (FR-051, SC-015).
6. طبّق فلاتر بلا نتائج ← حالة فراغ عربية موجّهة لإجراء، لا صفحة بيضاء.
7. افتح ملف مدرّس ← درجة الثقة بصرية + عواملها الخمسة + زر حجز يبقى ظاهراً أثناء التمرير.
8. تنقّل بين التبويبات الأربعة ← بلا إعادة تحميل، والتبويب النشط في الرابط.
9. اضغط "احجز الآن" ← يُوجَّه إلى تسجيل الطالب مع الاحتفاظ بسياق المدرّس.

**التحقق من قابلية الفهرسة (SC-016)** — بلا تنفيذ سكربتات:

```powershell
curl -s http://localhost:3000/teachers | Select-String -Pattern 'trust|<h1|<article'
# المتوقع: أسماء المدرّسين والمحتوى الأساسي موجودة في HTML الأولي
```

---

## سيناريو 3 — درجة الثقة (القصة P5)

```powershell
cd D:\mteatch\backend
php vendor/bin/pest tests/Feature/Marketplace/TrustScoreTest.php
```

| الحالة | المتوقع |
|---|---|
| 5 حصص مكتملة، تقييمان | `trust_score = null` · `band = "building"` — **لا صفر** |
| 12 حصة، 4 تقييمات بمتوسط 4.5 | قيمة رقمية 0–100 مطابقة لأوزان `config/marketplace.php` |
| إضافة شكوى مؤكدة | تنقص 5 نقاط · بحد أقصى 20 |
| تقييم جديد | إعادة الاحتساب عبر الطابور وانعكاس القيمة في السوق |
| طالب بلا حصة مكتملة يحاول التقييم | **422** |
| نفس الطالب يقيّم مرتين | تحديث السجل، لا سجل ثانٍ · المتوسط لا يتضخّم |

**تحقّق يدوي من قاعدة الطابور** (الدستور، المبدأ I):

```powershell
Select-String -Path "app/Modules/Marketplace/Jobs/*.php" -Pattern "WorkspaceContext::set|->set\("
# المتوقع: لا نتائج — الطابور يستخدم forWorkspace() حصراً
```

---

## سيناريو 4 — التسجيل والأدوار (القصتان P2 و P4)

1. `http://localhost:3000/signup/student` ← أرسل بلا تفعيل الموافقة ← الإرسال ممنوع
   ومربع الموافقة **غير مُفعّل مسبقاً**.
2. أكمل التسجيل ← تحقّق من `platform_role = student` و**عدم** إنشاء مساحة عمل:

```powershell
cd D:\mteatch\backend
php artisan tinker --execute="`$u = App\Models\User::where('email','<البريد>')->first(); dump(`$u->platform_role, `$u->workspaces()->count());"
# المتوقع: 'student', 0
```

3. تحقّق من بقاء مسار إنشاء الأكاديمية القائم (`/register`) عاملاً بلا تغيير (FR-011).
4. `http://localhost:3000/signup/teacher` ← امرر الخطوات الأربع، أغلق المتصفح في الخطوة 3،
   ارجع ← يستأنف من الخطوة 3 ببياناته محفوظة.
5. أرسل ← رسالة "طلبك قيد المراجعة من فريقنا الأكاديمي".
6. تحقّق من **عدم** ظهوره في `/teachers` قبل الاعتماد.
7. اعتمده من `http://localhost:8000/admin` ← يظهر في السوق خلال ≤ 60 ثانية.

---

## سيناريو 5 — إمكانية الوصول والتجاوب (SC-012, SC-013)

```powershell
cd D:\mteatch\frontend
npx playwright test e2e/accessibility.spec.ts
```

يفحص السبع صفحات في **الوضعين الفاتح والليلي** وعلى **360px / 768px / 1440px**.

**المتوقع: صفر مخالفات.**

⚠️ **نقطة فشل متوقّعة**: `#FF8A34` (التمييز) و`#F5B301` (الثقة المتوسطة) **لا يحقّقان**
تباين 4.5:1 مع نص أبيض. الحل المعتمد: نص داكن عليهما، أو درجة أغمق كخلفية للنص.
هذا مسجّل في المواصفة ومتوقّع أن يظهر في أول تشغيل.

---

## سيناريو 6 — الأداء تحت الحجم (SC-008)

```powershell
cd D:\mteatch\backend
php artisan db:seed --class=MarketplaceLoadSeeder    # 50,000 مدرّس + 10,000 كورس
Measure-Command { curl -s "http://localhost:8000/api/v1/marketplace/teachers?subject=math&price_max=200&sort=rating_desc" }
```

**المتوقع**: أقل من ثانية. التشغيل الثاني أسرع (تخزين مؤقت 60 ثانية).
إن تجاوز الثانية: راجع الفهارس المركّبة في [data-model.md](./data-model.md) قبل إضافة أي تخزين مؤقت إضافي.

---

## المسارات الحرجة القائمة — يجب أن تبقى خضراء

```powershell
cd D:\mteatch\backend
php vendor/bin/pest tests/Feature/Tenancy tests/Feature/Learning tests/Feature/Assessments tests/Feature/Payments
```

الثمانية المذكورة في `AGENTS.md`. هذه الميزة تمسّ `users` و`workspaces` و`courses`،
فكسر أيّ منها إشارة إلى ارتداد لا إلى اختبار قديم.
