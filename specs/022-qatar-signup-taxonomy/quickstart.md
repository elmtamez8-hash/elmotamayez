# Phase 1 · التحقّقُ العمليّ — مفرداتُ التسجيلِ القَطَريّة

⚠️ **لا تشغيلَ لأيِّ مجموعةِ اختباراتٍ كاملةٍ محليّاً — أمرٌ قائمٌ من المالك.** البوّابةُ
الكاملةُ على CI. المسموحُ هنا مستهدَفٌ ومرّةً واحدةً عندَ نهايةِ كلِّ تغييرٍ متماسك.

---

## قبلَ كلِّ شيء: الحالةُ التي يجبُ ألّا يقيسَها أحدٌ بالخطأ

قاعدةُ التطويرِ عندَك **مزروعةٌ ببياناتٍ تجريبيّة**، فالمفرداتُ فيها موجودةٌ وتحتَها مدرّسون
معروضون — أي أنّ **كلا العطلَينِ غيرُ مرئيٍّ محليّاً**. لذلك كلُّ سيناريو أدناه يبدأُ
بتحييدِ ذلك؛ سيناريو يُقاسُ على قاعدةٍ مزروعةٍ يمرُّ أخضرَ ويثبتُ العكس.

```bash
# لا تُنفَّذْ بلا إذن — تدمّرُ قاعدةَ التطوير.
php artisan migrate:fresh   # بلا --seed
```

---

## السيناريو ١ — المفرداتُ تصلُ قاعدةً بلا بياناتٍ تجريبيّة (US1 · US2 · SC-001 · SC-002)

**السؤالُ المقيس**: هل يوجدُ ما يُختار، على نشرةٍ لا عرضَ تجريبيَّ فيها؟

```bash
php artisan migrate      # هجراتُ الردمِ تنادي seedMissing()
php artisan tinker --execute="
  echo App\Modules\Marketplace\Models\GradeLevel::count(), ' مراحل / ',
       App\Modules\Marketplace\Models\SchoolYear::count(),  ' صفوف / ',
       App\Modules\Marketplace\Models\Subject::count(),     ' مواد';"
```

**المتوقَّع**: `5 مراحل / 14 صفوف / 13 مواد` — وصفرُ مدرّسينَ معروضين.

```bash
curl -s localhost:8000/api/v1/signup/school-years | head -c 300
curl -s localhost:8000/api/v1/signup/subjects     | head -c 300
curl -s localhost:8000/api/v1/marketplace/subjects            # يبقى []
```

**المتوقَّع**: الأوّلانِ ممتلئان، والثالثُ `[]` — وهذا **ليس عطلاً**: هو `SC-008` يعمل. لو
امتلأ الثالثُ فقد اتّسعَ شريطُ المتجرِ بأثرٍ جانبيٍّ وهو ما تمنعُه المواصفة.

---

## السيناريو ٢ — البابُ والشاشةُ يتّفقان (SC-003)

```bash
php vendor/bin/pest tests/Feature/Identity tests/Feature/Marketplace
```

الاختبارُ الحاسمُ يقارنُ **مجموعتَين**: ما يُرجِعُه مسارُ التسجيل، وما يقبلُه `Rule::in`
داخلَ الطلبِ نفسِه — ويسقطُ عندَ أيِّ فرق. الطريقةُ الوحيدةُ التي كانت ستكشفُ العطلَ الأصليَّ
قبلَ شحنِه.

⚠️ **الاختبارُ يجبُ أن يبنيَ تركيبتَه بلا مدرّسٍ معروضٍ واحد.** تركيبةٌ فيها مدرّسٌ معتمَدٌ
تمرُّ خضراءَ فوقَ القفلِ الدائريِّ بالضبط، لأنّ الطريقَينِ يتّفقانِ حينَها صدفةً.

---

## السيناريو ٣ — طالبٌ يسجّلُ بصفٍّ وتُشتَقُّ مرحلتُه (US1 · FR-001ج)

```bash
curl -s -X POST localhost:8000/api/v1/auth/register/student \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"first_name":"سلمى","last_name":"ع","email":"s@example.test",
       "phone":"33123456","country":"QA",
       "password":"Passw0rd!x","password_confirmation":"Passw0rd!x",
       "school_year_slug":"year-10","region_slug":"doha","date_of_birth":"2010-01-01",
       "guardian_contact":"33999888","terms_accepted":true}' | python -m json.tool | head -30
```

⚠️ **أربعةُ حقولٍ كانت ناقصةً في نسخةٍ سابقةٍ من هذا السيناريو** (R11-ل-١): `phone` و`country`
مطلوبان، والحقلُ اسمُه `terms_accepted` لا `accepts_terms`، ومَن وُلِدَ ٢٠١٠ **قاصر** فيلزمُه
`guardian_contact`. سيناريو يقفُ عند ٤٢٢ هو سيناريو يُختصَرُ حتى يمرَّ — وهذا بالذاتِ هو
الحارسُ الوحيدُ لأخطرِ بندٍ في جدولِ المخاطر.

**المتوقَّع**: `201`؛ الحمولةُ تحملُ `school_year_slug: "year-10"` **و**
`grade_level_slug: "secondary"` — الثاني مشتَقٌّ لا مُرسَل.

**والقياسُ الذي يكشفُ عطلَ `$fillable`** — وهو العطلُ الذي شُحِنَ ثلاثَ مرّاتٍ في مواصفة ٠١٣
بردٍّ `201` وثلاثةِ أعمدةٍ فارغة:

```bash
php artisan tinker --execute="
  \$p = App\Modules\Identity\Models\StudentProfile::latest('id')->first();
  echo 'المخزَّن: ', var_export(\$p->school_year_slug, true), ' | المشتقّ: ',
       var_export(\$p->stageSlug(), true);"
```

**المتوقَّع**: `'year-10' | 'secondary'`. ⚠️ **`null` هنا مع `201` أعلاه هو العطلُ نفسُه**
— لا تُصدِّقِ الردَّ، اقرأِ الصفّ.

---

## السيناريو ٤ — الطالبُ القديمُ لا ينكسر (Edge Case)

```bash
php artisan tinker --execute="
  \$p = App\Modules\Identity\Models\StudentProfile::first();
  \$p->forceFill(['school_year_slug' => null, 'grade_level_slug' => 'secondary'])->save();
  echo var_export(\$p->fresh()->stageSlug(), true);"
```

**المتوقَّع**: `'secondary'` — الاحتياطُ يعمل. وشاشاتُه تعرضُ مرحلتَه ولا تُطالبُه باختيارٍ
ليدخل.

---

## السيناريو ٥ — الانتماءُ إلزاميّ، والتعطيلُ يتتالى بالقراءة (FR-011أ · SC-010 · Edge Case)

```bash
php artisan tinker --execute="
  use App\Modules\Marketplace\Models\{SchoolYear, GradeLevel};
  echo 'صفوفٌ نشطةٌ بلا مرحلةٍ نشطة: ',
       SchoolYear::where('is_active', true)
         ->whereHas('gradeLevel', fn (\$q) => \$q->where('is_active', false))->count();
  \$s = GradeLevel::where('slug','secondary')->first();
  \$s->update(['is_active' => false]);"

curl -s localhost:8000/api/v1/signup/school-years | grep -c 'year-10'
```

**المتوقَّع**: `0` من `grep` — صفوفُ الثانويّةِ اختفت بلا كتابةِ صفٍّ واحدٍ فيها. ثمّ:

```bash
php artisan tinker --execute="
  \$s = App\Modules\Marketplace\Models\GradeLevel::where('slug','secondary')->first();
  \$s->update(['is_active' => true]);"
```

⚠️ **عبرَ نسخةِ النموذجِ لا عبرَ باني الاستعلام.** `Model::where(...)->update(...)` لا يُقلِعُ
نموذجاً فلا يُطلِقُ `saved` فلا يُنظَّفُ الكاش — فتبقى القائمةُ قديمةً حتى انتهاءِ المدّة،
والسطرُ التالي يأمرُ القارئَ بتفسيرِ ذلك بأنّه «تعطيلٌ متتالٍ كُتِبَ في الجدول»: تشخيصٌ خاطئٌ
يصنعُه السيناريو بنفسِه.

**المتوقَّع**: عادت الثلاثةُ كما كانت. لو لم تعُدْ فقد كُتِبَ تعطيلٌ متتالٍ في الجدولِ وهو
ما يرفضُه `data-model.md`.

---

## السيناريو ٦ — النشرُ لا يمسحُ تعديلَ المشغّل (US3 · SC-006)

```bash
php artisan tinker --execute="
  App\Modules\Marketplace\Models\Subject::where('slug','math')
    ->update(['name_ar' => 'الرياضيات (متقدّم)']);
  (new Database\Seeders\TaxonomySeeder)->seedMissing();
  echo App\Modules\Marketplace\Models\Subject::where('slug','math')->value('name_ar');"
```

**المتوقَّع**: `الرياضيات (متقدّم)` — باقيةً. ⚠️ لو عادت «الرياضيات» فقد نُودِيَ `run()` في
مسارِ النشر، وهو يمسحُ كلَّ تسميةٍ وترتيبٍ عدّلَهما مشغّلٌ في **كلِّ** إصدار.

---

## السيناريو ٧ — لوحةُ التحكّم (US3 · FR-011 · FR-012)

`/admin` ⇐ «السوق والتصنيف» ⇐ «الصفوف الدراسيّة».

1. **إضافةُ صفٍّ بلا مرحلة** ⇐ يُرفَضُ الحفظ.
2. **صفٌّ جديدٌ بمرحلةٍ نشطة** ⇐ يظهرُ في `signup/school-years` خلالَ ٦٠ ثانية
   (`marketplace.cache_ttl_seconds`).
3. **لا زرَّ حذفٍ** على أيٍّ من الشاشاتِ الأربع.
4. **بحسابِ `finance-admin` أو `compliance-officer`** ⇐ `SchoolYearResource::canViewAny()`
   تُجيبُ `false`؛ وبحسابِ مشرفٍ عامٍّ تُجيبُ `true`.

⚠️ **البند ٤ صُحِّحَ** (R11-ج-١١). كان مكتوباً «بحسابِ مدرّسٍ (مالكِ مساحة)» — وذلك **قياسٌ
فارغ**: `User::mayAccessAdminPanel()` يقبلُ المشرفَ العامَّ و`platform_staff` وحدَهما، فمالكُ
المساحةِ يُردُّ عند البابِ الخارجيِّ ويمرُّ البندُ **فوقَ موردٍ بلا حارسٍ إطلاقاً** — عائلةُ
«تسعةُ اختباراتٍ خضراءَ تقيسُ الشرطَ الخطأ». السكّانُ الحقيقيّون هم موظّفو المنصّةِ الذين
يدخلون اللوحةَ ولا يملكون صلاحيةَ المفردات.

⚠️ **والقياسُ على المورِدِ لا عبرَ `Gate`**: طبقةُ Filament هي التي **تفشلُ مفتوحة** حين تغيبُ
السياسة، بينما `Gate` يفشلُ مغلقاً فيُجيبُ «ممنوع» سواءٌ وُجِدَتِ السياسةُ أم لا — فاختبارُ
المنعِ عبرَه صحيحٌ ولا يُثبِتُ شيئاً. و`PanelResourceDoorTest` يلتقطُ الغيابَ الكاملَ تلقائيّاً.

---

## السيناريو ٨ — زرُّ كلمةِ المرور (US4 · SC-005)

```bash
cd frontend && npm test -- src/components/ui   # vitest، ~ثانيتان
```

الاختبارُ يقيسُ ما لا تراه العين: **الضغطةُ الأولى لا تُرسِلُ النموذج** (`type="button"`)،
والحالةُ الابتدائيّةُ مخفيّة، و`aria-label` يتبدّل.

والعدُّ الذي يُثبِتُ `SC-005`:

```bash
grep -rn 'type="password"' src --include=*.tsx | wc -l   # المتوقَّع: 0
grep -rn '<PasswordField' src --include=*.tsx | wc -l    # المتوقَّع: 14
```

⚠️ **العددُ أربعةَ عشرَ لا اثنا عشر** (R11-ب-١): ستّةٌ خامّةٌ في استماراتِ التسجيل، **وثمانيةٌ
تمرُّ اليومَ بـ`TextField type="password"`** — وهذه الثمانيةُ هي التي كانت ستبقى بلا زرٍّ لو
اكتفى التغييرُ بالحقولِ الخامّة.

⚠️ والرقمُ الأوّلُ **صفرٌ**: مع حذفِ `"password"` من اتّحادِ أنواعِ `TextField`، لم تعُدْ هناك
تهجئةٌ ثانيةٌ يمكنُ كتابتُها أصلاً — وهو ما يجعلُ الصفرَ قابلاً للتحقيقِ بدلَ أن يكونَ أُمنيّة.

---

## البوّاباتُ عندَ نهايةِ العمل (مرّةً واحدة)

```bash
cd backend
php vendor/bin/pest tests/Feature/Identity tests/Feature/Marketplace
./vendor/bin/pint && ./vendor/bin/phpstan analyse

cd ../frontend
npx tsc --noEmit && npm test
```

`npm test` هو «الكاملُ» المقبولُ الوحيد (~ثانيتان، بلا خادمٍ وبلا بناء). ولا تُشغَّلُ
مجموعتا pest معاً — تتقاسمانِ قرصَ الاختبارِ وتصنعانِ سقوطاً كاذباً.
