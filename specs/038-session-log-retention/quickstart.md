# Phase 1 — دليلُ التحقّق: كيف يُقاسُ أنّ هذا يعمل

**الميزة**: `038-session-log-retention` · **التاريخ**: ٢٠٢٦-٠٩-٢٢

⛔ **كلُّ ما هنا قياس، ولا بندَ منه يُعلَنُ «تمّ» بقراءةِ شيفرة.** والجدولانِ اللذانِ تعالجُهما هذه الميزةُ هما بالضبطِ الجدولانِ اللذانِ بقيا عشرينَ إصداراً بلا أن يلحظَهما أحد.

---

## المتطلَّباتُ السابقة

- الخلفيّةُ وحدَها. **لا خادمَ واجهةٍ ولا متصفّح** — الحمولةُ لا تتغيّر (`research.md` · ر-٤).
- عاملُ طابورٍ يستمعُ إلى `compliance`، أو تشغيلُ المهمّةِ متزامنةً في `tinker`.
- ⚠️ **وعاملٌ قديمٌ يحملُ الشيفرةَ التي أقلعَ بها.** أعِدْ تشغيلَه بعدَ كلِّ تعديل، وإلّا قرأتَ أثراً من بناءٍ لم يعُدْ على القرص.

---

## ١ · الكتالوجُ وصلَ فعلاً (FR-001 · FR-002)

```bash
cd backend
echo "" | php artisan tinker --execute="
  \$rows = App\Modules\Compliance\Models\DataCategory::whereIn('key', ['auth_session','device'])->get();
  echo \$rows->count().PHP_EOL;
  foreach (\$rows as \$r) { echo \$r->key.' | '.\$r->retain_days.' | '.\$r->expiry_behaviour?->value.' | '.\$r->erasure_mode?->value.PHP_EOL; }
"
```

**المتوقَّع**: صفّانِ · `180` · `anonymise` · `anonymise`.

⛔ **وهذا يُعادُ على الإنتاجِ بعدَ النشر، لا على التطويرِ وحدَه.** الكتالوجُ بياناتٌ مرجعيّةٌ يُنادى باذرُها في `migrate:fresh --seed` وفي `tests/Pest.php` فقط، فالصفُّ الجديدُ لا يصلُ قاعدةً قائمةً إلّا بهجرةِ الإملاءِ الخلفيّ. **هذه المرّةُ السادسةُ لهذا العطبِ في هذا المستودَع**، وقياسُ التطويرِ وحدَه أخضرُ وكاذب.

---

## ٢ · المحوُ لم يُطفَأْ في الوحدةِ كلِّها (⛔ **يُقاسُ أوّلاً**)

```bash
echo "" | php artisan tinker --execute="
  \$owner = app(App\Modules\Identity\Support\IdentityPersonalData::class);
  \$modes = App\Modules\Compliance\Models\DataCategory::whereIn('key', \$owner->describe())
      ->pluck('erasure_mode')->map(fn(\$m) => is_object(\$m) ? \$m->value : (string) \$m)->unique()->values();
  echo \$modes->count().' :: '.\$modes->implode(',').PHP_EOL;
"
```

**المتوقَّع**: `1 :: anonymise`.

⛔ **وأيُّ عددٍ غيرِ ١ يعني أنّ كلَّ طلبِ محوٍ في `Identity` صارَ `Retain` بلا خطأٍ ظاهر** — لا اسمَ يُجهَّلُ ولا بريدَ ولا هاتف، وأثرُه الوحيدُ سطرُ `compliance.erasure.unclear_mode` لا يقرؤُه أحد. يُقاسُ قبلَ كلِّ ما بعدَه لأنّه العطبُ الوحيدُ هنا الذي لا يُصدِرُ صوتاً.

---

## ٣ · المكنسةُ تُجهِّلُ وتُسقِّفُ — وتُشغَّلُ **مرّتَين**

```bash
echo "" | php artisan tinker --execute="
  App\Modules\Compliance\Jobs\RunRetentionSweepJob::dispatchSync();
  \$a = App\Modules\Compliance\Models\RetentionSweepRun::latest('id')->first();
  echo 'المرور 1: مُجهَّل='.\$a->rows_anonymised.' محذوف='.\$a->rows_deleted.PHP_EOL;

  App\Modules\Compliance\Jobs\RunRetentionSweepJob::dispatchSync();
  \$b = App\Modules\Compliance\Models\RetentionSweepRun::latest('id')->first();
  echo 'المرور 2: مُجهَّل='.\$b->rows_anonymised.' محذوف='.\$b->rows_deleted.PHP_EOL;
"
```

**المتوقَّع**: المرورُ الأوّلُ يُحرِّكُ عدداً موجباً، **والثاني صفرانِ**.

⛔ **والمرورُ الثاني هو القياس.** تشغيلةٌ واحدةٌ خضراءُ إلى الأبدِ وتُثبِتُ العكس — كتبَها `RollupIdempotencyTest` في هذا المستودَعِ من قبل.

---

## ٤ · النشطةُ لم تُمَسّ (FR-006 · SC-003)

يُقاسُ **قبلَ المكنسةِ وبعدَها**، على مستوى المنصّةِ كلِّها:

```bash
echo "" | php artisan tinker --execute="
  echo App\Modules\Identity\Models\AuthSession::where('status','active')->count().PHP_EOL;
"
```

**المتوقَّع**: الرقمُ **نفسُه** قبلَ وبعد.

⚠️ **على مستوى المنصّةِ لا على مستخدِمٍ واحد**: قياسُ حسابٍ واحدٍ أخضرُ على تنفيذٍ يُنهي جلساتِ الجميعِ عداه.

---

## ٥ · التجهيلُ أصابَ العمودَينِ وحدَهما

```bash
echo "" | php artisan tinker --execute="
  echo 'جلسات منتهية بلا ip: '.App\Modules\Identity\Models\AuthSession::where('status','ended')->whereNull('ip_hash')->count().PHP_EOL;
  echo 'جلسات بلا device_id: '.App\Modules\Identity\Models\AuthSession::whereNull('device_id')->count().PHP_EOL;
  echo 'أجهزة مُجهَّلة: '.App\Modules\Identity\Models\Device::where('fingerprint_hash','like','anonymised:%')->count().PHP_EOL;
  echo 'بصمات مكرّرة: '.App\Modules\Identity\Models\Device::selectRaw('user_id, fingerprint_hash, count(*) c')->groupBy('user_id','fingerprint_hash')->havingRaw('c > 1')->count().PHP_EOL;
"
```

**المتوقَّع**: الأوّلُ موجب · **الثاني صفرٌ دائماً** (`device_id` لا يُفرَّغ) · الثالثُ موجب · **الرابعُ صفر**.

⛔ **والرابعُ هو ما يُثبِتُ القيمةَ الفريدة.** ثابتٌ واحدٌ لكلِّ صفٍّ مُجهَّلٍ يصطدمُ بـ`unique(user_id, fingerprint_hash)` عندَ ثاني جهازٍ لنفسِ المستخدِم، وعلى MySQL خطأٌ يقتلُ الفئةَ كلَّها في تلك الليلة. وعلى SQLite قد يُحكى الأمرُ بصورةٍ أخرى، فالقياسُ على الإنتاجِ بعدَ أوّلِ ليلةٍ ليس اختياريّاً.

---

## ٦ · لا جلسةَ تُشيرُ إلى جهازٍ غيرِ موجود (⛔ **الأخطرُ في القائمة**)

```bash
echo "" | php artisan tinker --execute="
  \$broken = App\Modules\Identity\Models\AuthSession::whereNotIn('device_id',
      App\Modules\Identity\Models\Device::pluck('id'))->count();
  \$deviceCount = App\Modules\Identity\Models\Device::count();
  echo 'جلسات تُشير إلى جهاز غير موجود: '.\$broken.PHP_EOL;
  echo 'عدد صفوف devices: '.\$deviceCount.PHP_EOL;
"
```

**المتوقَّع**: **صفرٌ في الأوّل** · وعددُ `devices` **لم ينقُصْ** قبلَ المكنسةِ وبعدَها.

⛔ **وهذا هو ما يُثبِتُ أنّ FR-006 نُفِّذَت كما هي**: لا صفَّ جهازٍ يُحذَفُ، ولو لم تبقَ له جلسةٌ واحدة. **وتنفيذٌ «ينظِّفُ» الأجهزةَ اليتيمةَ يُنقِصُ العددَ، ويقرأُ تحسيناً** — وهو مصدرُ العطبِ لا علاجُه.

⛔ **ولا مفتاحَ أجنبيَّ على `device_id`** (مقيسٌ في الهجرة)، فالسطرُ الأوّلُ ليس مستحيلاً بل هو ما يُنتِجُه أيُّ حذفٍ هنا. وصفٌّ واحدٌ منه يجعلُ `$this->device->label` في `AuthSessionResource` خطأَ ٥٠٠ **للطلبِ كلِّه**: شاشةُ «الأجهزة والجلسات» تسقطُ عن صاحبِها كلَّها لأجلِ صفٍّ لا يُسمّي أحداً — وهو عينُ ما حدثَ في `ListStudentBalances` (٠٢٩ · T057).

---

## ٧ · الأرشيفُ يحملُ ملفَّينِ جديدَين — ولو كانا فارغَين (SC-008)

يُقاسُ على **حسابَين**: واحدٌ له جلسات، وواحدٌ ليست له.

```bash
echo "" | php artisan tinker --execute="
  \$u = App\Models\User::find(<ID>);
  \$owner = app(App\Modules\Identity\Support\IdentityPersonalData::class);
  foreach (\$owner->export(new App\Shared\Data\DataSubject(\$u)) as \$key => \$rows) {
      if (in_array(\$key, ['auth_session','device'], true)) { echo \$key.' => '.count(\$rows).PHP_EOL; }
  }
"
```

**المتوقَّع**: المفتاحانِ **حاضرانِ في الحالتَين**؛ في الثانيةِ بعددِ `0` لا بغياب.

⛔ **وملفٌّ غائبٌ صمتٌ** بينما «لا جلساتٍ مسجّلةٌ لك» جوابٌ يستحقُّه مَن سأل. و`ExportCompletenessTest` يُسقِطُ البناءَ على غيابِه، فالاختبارُ يعضُّ — لكنّ القياسَ هنا يُثبِتُ **الفارغةَ** وهي الحالةُ التي يمرُّ عليها الحارسُ ولا يُميّزُها.

⚠️ **ولا `ip_hash` ولا `fingerprint_hash` في أيٍّ من الملفَّين**: قيمةٌ مُجزَّأةٌ لا يقرؤُها صاحبُها، وتسليمُها تسليمُ مادّةٍ لهجومِ قاموس.

---

## ٨ · الحظرُ القضائيُّ يُوقِفُ الاثنَين (FR على الإعفاء)

ضَعْ حظراً على مستخدِمٍ له جلساتٌ قديمةٌ تتجاوزُ السقفَ والمدّةَ معاً، ثمّ شغِّلِ المكنسة.

**المتوقَّع**: عددُ صفوفِه في `auth_sessions` و`devices` **لم يتغيّرْ**، و`ip_hash` و`fingerprint_hash` كما كانا.

⛔ **ويُقاسُ على الذراعَينِ معاً.** الإعفاءُ في التجهيلِ وحدَه يترُكُ السقفَ يحذِفُ الصفوفَ التي أمرَ قرارٌ بحفظِها — والحذفُ لا يُعكَس.

---

## ٩ · حدُّ الأجهزةِ لم يتحرّك (⚠️ **الأثرُ الجانبيُّ الوحيدُ الذي يمكنُ أن يطردَ مستخدِماً**)

سجِّلْ دخولاً من نفسِ المتصفّحِ بعدَ مرورِ المكنسة.

**المتوقَّع**: الدخولُ يُنشِئُ **صفَّ `devices` جديداً** (البصمةُ القديمةُ جُهِّلَت فلا تُطابِق) — وهذا صحيحٌ ومقصود — **ولا يُطرَدُ أيُّ جهازٍ آخر**.

⛔ **والطردُ هو ما يُقاس، لا عددُ الصفوف.** `enforceLimit()` يَعُدُّ الأجهزةَ **بين الجلساتِ النشطةِ** وحدَها، فصفٌّ مُجهَّلٌ لا يدخلُ العدَّ أبداً. لكنّ تجهيلَ جهازٍ **ما زالَت له جلسةٌ نشطة** يكسِرُ المطابقةَ فيصيرُ الجهازُ الواحدُ جهازَين — وعندَ سقفِ جهازٍ واحدٍ للطالبِ هذا **طردٌ من الحاسوبِ الذي يجلسُ أمامَه**. شرطُ «لا جلسةَ نشطة» في (ب) هو ما يمنعُه، وهذا البندُ هو ما يُثبِتُه.

---

## ١٠ · صفرُ تغييرٍ في الواجهة (FR-008)

```bash
git diff --name-only main... -- frontend/
```

**المتوقَّع**: **لا شيء**.

⛔ **وهذا متطلَّبٌ لا مصادفة.** FR-008 كُتِبَت تطلبُ «جملةً صريحةً بدلَ الفراغ»، وقياسُ الحمولةِ أظهرَ أنّ **لا خانةَ تصيرُ فارغة**: الشاشةُ تعرِضُ اسمَ الجهازِ وبابَ الدخولِ وتاريخَين، ولا تعرِضُ `ip_hash` ولا `fingerprint_hash` إطلاقاً. فشارةٌ تُضافُ لحالةٍ لا تُغيِّرُ حرفاً هي نفسُها ما يُقرَأُ عطباً.

---

## ١١ · البوّاباتُ الآليّة

```bash
cd backend
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

ثمّ **ادفَعْ ودَعِ الشرائحَ الأربعَ تُشغِّلُ الحزمة** — لا تُشغَّلُ الحزمةُ كاملةً محلّيّاً، ولا تُشغَّلُ حزمتانِ معاً.

محلّيّاً يكفي المُرشَّح:

```bash
php vendor/bin/pest --filter="Retention|PersonalData|Erasure|Export"
```
