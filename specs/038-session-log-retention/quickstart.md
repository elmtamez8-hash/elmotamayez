# Phase 1 — دليلُ التحقّق

**الميزة**: `038-session-log-retention` · **التاريخ**: ٢٠٢٦-٠٩-٢٢ · **النسخةُ الثانية** بعدَ [مراجعةِ الوكلاء](./review-findings.md)

⛔ **أوّلُ ما يجبُ أن يُقالَ، لأنّ النسخةَ الأولى بُنِيَت على عكسِه: لا قاعدةَ حقيقيّةٌ اليومَ تُخرِجُ رقماً موجباً.** أقدمُ صفٍّ في الإنتاجِ عمرُه ١٩ يوماً وفي التطويرِ ٤٧، ومدّةُ الاحتفاظِ ٩٠. **فالمرورُ على قاعدةٍ كما هي يُخرِجُ أصفاراً — وهي نفسُها ما يُخرِجُه بناءٌ لم تُكتَبْ فيه الميزةُ أصلاً.** فكلُّ قياسٍ هنا يبدأُ بتشييخِ صفوف، وكلُّ نفيٍ معه ضابطٌ موجَبٌ في التشغيلةِ نفسِها.

---

## ٠ · بناءُ المُهيَّأ — وثلاثةُ ألغامٍ فيه

```bash
cd backend
echo "" | php artisan tinker --execute="
  \$u = App\Models\User::factory()->create();
  \$d = App\Modules\Identity\Models\Device::factory()->create(['user_id' => \$u->id]);

  // ⚠️ ip_hash يُكتَبُ صراحةً — المصنعُ لا يكتبُه، فصفٌّ منه مولودٌ «مُجهَّلاً»
  // ⚠️ والوالدُ واحدٌ — المصنعُ يُعلِنُ user_id وdevice_id مصنعَينِ مستقلَّين
  foreach (range(1, 60) as \$i) {
      \$s = App\Modules\Identity\Models\AuthSession::factory()->create([
          'user_id' => \$u->id, 'device_id' => \$d->id,
          'status' => 'ended', 'ip_hash' => hash('sha256', '10.0.0.'.\$i),
      ]);
      // ⚠️ التشييخُ باستعلام: created_at ليس في \$fillable
      DB::table('auth_sessions')->where('id', \$s->id)->update([
          'created_at' => now()->subDays(400 - \$i),
          'ended_at'   => now()->subDays(400 - \$i),
      ]);
  }
  DB::table('devices')->where('id', \$d->id)->update(['created_at' => now()->subDays(400)]);
  echo \$u->id.PHP_EOL;
"
```

⛔ **ثلاثةُ ألغامٍ مقيسة**، وكلُّ واحدٍ منها يجعلُ التشغيلةَ خضراءَ على بناءٍ فارغ:

1. **`AuthSessionFactory` لا يكتبُ `ip_hash`** — و`ip_hash IS NULL` هي علامةُ «جُهِّلَ سلفاً». فصفُّ المصنعِ مولودٌ مُجهَّلاً.
2. **`user_id` و`device_id` مصنعانِ مستقلّانِ فيه** — فجلسةُ شخصٍ وجهازُ آخر، واختبارُ الحظرِ القضائيِّ يُعفي هذا ويُجهِّلُ جهازَ ذاك **ويمرُّ**.
3. **`created_at` ليس في `$fillable`** — صفٌّ «قديمٌ» داخلَ `create()` يُولَدُ اليوم.

⚠️ **ويُضافُ `ip_hash` إلى المصنعِ في التنفيذ**، كي لا يتكرّرَ اللغمُ الأوّلُ في كلِّ اختبارٍ يأتي بعدَنا.

---

## ١ · الكتالوجُ وصلَ (FR-001)

```bash
echo "" | php artisan tinker --execute="
  foreach (App\Modules\Compliance\Models\DataCategory::whereIn('key',['auth_session','device'])->get() as \$r)
      echo \$r->key.' | '.\$r->retain_days.' | '.\$r->expiry_behaviour?->value.' | '.\$r->erasure_mode?->value.PHP_EOL;
"
```

**المتوقَّع**: صفّانِ · مدّةٌ **غيرُ فارغة** · `anonymise` · `anonymise`.

⚠️ **ولا يُقارَنُ الرقمُ بقيمةٍ حرفيّة**: الباذرُ `firstOrCreate`، فمشغِّلٌ عدّلَ المدّةَ يجعلُ قياساً حرفيّاً يُقرَأُ إخفاقاً. المطلوبُ الحضورُ وعدمُ الفراغِ — و`expires()` تشترطُ الاثنَين.

⛔ **ويُعادُ على الإنتاجِ بعدَ النشر.** الكتالوجُ بياناتٌ مرجعيّة، والصفُّ الجديدُ لا يصلُ قاعدةً قائمةً إلّا بهجرةِ الإملاءِ الخلفيّ — وهي المرّةُ السادسةُ لهذا الصنفِ في هذا المستودَع. وهذه **الخطوةُ الوحيدةُ** التي ترى غيابَ الهجرة.

---

## ٢ · المحوُ لم يُطفَأْ في الوحدةِ كلِّها (⛔ **يُقاسُ أوّلاً**)

```bash
echo "" | php artisan tinker --execute="
  \$o = app(App\Modules\Identity\Support\IdentityPersonalData::class);
  \$m = App\Modules\Compliance\Models\DataCategory::whereIn('key', \$o->describe())
      ->pluck('erasure_mode')->map(fn(\$x) => is_object(\$x) ? \$x->value : (string) \$x)->unique()->values();
  echo \$m->count().' :: '.\$m->implode(',').PHP_EOL;
"
```

**المتوقَّع**: `1 :: anonymise`. وأيُّ عددٍ غيرِ ١ يعني أنّ كلَّ طلبِ محوٍ في `Identity` صارَ `Retain` **بلا خطأٍ ظاهر**.

---

## ٣ · المكنسةُ تُجهِّلُ — مرّتَينِ، ومع أسبابِ الإخفاق

```bash
echo "" | php artisan tinker --execute="
  foreach ([1,2] as \$pass) {
      App\Modules\Compliance\Jobs\RunRetentionSweepJob::dispatchSync();
      \$r = App\Modules\Compliance\Models\RetentionSweepRun::latest('id')->first();
      echo \$pass.': مُجهَّل='.\$r->rows_anonymised.' محذوف='.\$r->rows_deleted
          .' إخفاقات='.\$r->findings_count.' '.json_encode(\$r->findings).PHP_EOL;
  }
"
```

**المتوقَّع**: المرورُ الأوّلُ **موجبٌ في `rows_anonymised`**، والثاني **صفر**، و`findings_count = 0` في الاثنَين.

⛔ **وطباعةُ `findings` ليست زينة**: المكنسةُ تلتقطُ إخفاقَ كلِّ فئةٍ وتُكمِلُ. فاصطدامُ قيمةِ التجهيلِ بالفهرسِ الفريدِ — العطبُ الذي يُحذِّرُ منه قرارُ الخطّةِ ٤ — يُخرِجُ أرقاماً تبدو سليمةً وسطراً واحداً في `findings` لا تراه بدونِ هذا.

⚠️ **والمرورُ الثاني هو القياس**: تشغيلةٌ واحدةٌ خضراءُ إلى الأبدِ وتُثبِتُ العكس.

---

## ٤ · ضابطٌ موجَبٌ: الحديثةُ لم تُمَسّ (⛔ **بدونِه المقياسُ كلُّه أجوف**)

```bash
echo "" | php artisan tinker --execute="
  \$b = now()->subDays((int) App\Modules\Compliance\Models\DataCategory::where('key','auth_session')->value('retain_days'));
  echo 'قديمة وما زالت تحمل عنواناً: '.App\Modules\Identity\Models\AuthSession::where('status','ended')
      ->where('ended_at','<',\$b)->whereNotNull('ip_hash')->count().PHP_EOL;
  echo 'حديثة وما زالت تحمل عنواناً: '.App\Modules\Identity\Models\AuthSession::where('status','ended')
      ->where('ended_at','>=',\$b)->whereNotNull('ip_hash')->count().PHP_EOL;
"
```

**المتوقَّع**: الأوّلُ **صفر** (SC-001) · الثانيُ **موجب**.

⛔ **والثاني هو الضابط**: حذفُ شرطِ العمرِ من الذراعِ يُجهِّلُ كلَّ شيءٍ بما فيه جلسةُ الأمس، ويُبقي السطرَ الأوّلَ صفراً — أي يمرُّ. الموجَبُ وحدَه يعضُّ.

---

## ٥ · النشطةُ لم تُمَسّ (FR-003 · SC-003)

يُقاسُ **قبلَ الخطوةِ ٣ وبعدَها**، على المنصّةِ كلِّها:

```bash
echo "" | php artisan tinker --execute="
  echo App\Modules\Identity\Models\AuthSession::where('status','active')->count().PHP_EOL;"
```

**المتوقَّع**: الرقمُ نفسُه. ⚠️ **على المنصّةِ لا على حسابٍ واحد** — قياسُ حسابٍ واحدٍ أخضرُ على تنفيذٍ يُنهي جلساتِ الجميعِ عداه.

---

## ٦ · المقبرةُ عملَت (FR-013 · SC-001) — ⛔ **وهذا ما يُثبِتُ أنّ التجهيلَ جهّلَ فعلاً**

```bash
echo "" | php artisan tinker --execute="
  \$leak = App\Modules\Identity\Models\AuthSession::where('status','ended')->whereNull('ip_hash')
      ->whereHas('device', fn(\$q) => \$q->where('fingerprint_hash','not like','anonymised:%'))->count();
  echo 'جلسات مُجهَّلة ما زالت تشير إلى بصمة حقيقية: '.\$leak.PHP_EOL;
  echo 'جلسات بلا device_id: '.App\Modules\Identity\Models\AuthSession::whereNull('device_id')->count().PHP_EOL;
  echo 'بصمات مكرّرة: '.App\Modules\Identity\Models\Device::selectRaw('user_id, fingerprint_hash, count(*) c')
      ->groupBy('user_id','fingerprint_hash')->havingRaw('c > 1')->get()->count().PHP_EOL;
"
```

**المتوقَّع**: الثلاثةُ **أصفار**.

⛔ **الأوّلُ هو قياسُ FR-013.** بدونِ المقبرةِ يكونُ موجباً لكلِّ جلسةٍ قديمةٍ على متصفّحٍ ما زالَ يُستعمَل — أي عندَ أكثرِ الناس. **وهذا بالضبطِ ما أسقطَ النسخةَ الأولى من الخطّة.**

⛔ **والثالثُ يُثبِتُ أنّ القيمةَ بُنِيَت في PHP لا بـ`||` في SQL.** على MySQL يكتبُ `||` القيمةَ `'1'` في كلِّ صفّ، فيصطدمُ بالفهرسِ عندَ ثاني جهازٍ لمستخدِمٍ واحد. **وعلى SQLite يعملُ صحيحاً**، فهذا القياسُ لا يُغني عن إعادتِه على الإنتاجِ بعدَ أوّلِ ليلة.

---

## ٧ · السقف (FR-004 · SC-002) — ثلاثُ قياساتٍ لا واحدة

```bash
echo "" | php artisan tinker --execute="
  App\Modules\Identity\Jobs\EnforceAuthSessionCapJob::dispatchSync();
  \$u = <ID>;
  \$cap = (int) App\Modules\Tenancy\Support\PlatformSettings::get('auth.auth_session_cap_per_user', 50);
  \$q = fn() => App\Modules\Identity\Models\AuthSession::where('user_id', \$u);
  echo 'مُجهَّلة باقية: '.(clone \$q)()->where('status','ended')->whereNull('ip_hash')->count().' / سقف '.\$cap.PHP_EOL;
  echo 'حديثة باقية: '.(clone \$q)()->where('status','ended')->whereNotNull('ip_hash')->count().PHP_EOL;
  echo 'نشطة باقية: '.(clone \$q)()->where('status','active')->count().PHP_EOL;
  echo 'أحدث الباقيات: '.(clone \$q)()->whereNull('ip_hash')->max('ended_at').PHP_EOL;
"
```

**المتوقَّع**: المُجهَّلةُ = السقفُ بالضبط · الحديثةُ **كما كانت** · النشطةُ **كما كانت** · والباقياتُ هي الأحدث.

⛔ **والسطرانِ الثاني والثالث هما ما يمنعُ العطبَ الأسوأ.** قياسٌ يكتفي بالعدِّ الأوّلِ يمرُّ على تنفيذٍ حذفَ الحديثاتِ والنشطاتِ معاً.

⛔ **ويُقاسُ أنّ السقفَ لا يبلغُ ما لم يُجهَّلْ**: بناءُ ستّينَ جلسةً منتهيةً **حديثةً** لمستخدِمٍ واحدٍ ثمّ تشغيلُ المهمّةِ يجبُ أن يحذِفَ **صفراً**. هذا قياسُ FR-004 · أرضيّةِ العمر، وهو ما يُبقي `auth_sessions` سجلَّ دخولٍ لا أداةَ محوِ أدلّة.

---

## ٨ · الحظرُ القضائيُّ يُوقِفُ الذراعَينِ معاً

ضَعْ حظراً على مستخدِمٍ مُهيَّأٍ كما في (٠)، ثمّ شغِّلِ المكنسةَ **ومهمّةَ السقف**.

**المتوقَّع**: عددُ صفوفِه **لم يتغيّر**، و`ip_hash` و`fingerprint_hash` **بقيمتِهما نفسِها** — لا بعددِ صفوفٍ متساوٍ.

⛔ **ويُقاسُ على الاثنَين.** الإعفاءُ في التجهيلِ وحدَه يترُكُ السقفَ يحذِفُ ما أمرَ قرارٌ بحفظِه — والحذفُ لا يُعكَس.

⚠️ **والمُهيَّأُ يمرُّ باللغمِ الثاني في (٠)**: لو كانَ الجهازُ لمستخدِمٍ آخر، يُعفى صاحبُ الجلسةِ وتُجهَّلُ بصمةُ غيرِه **ويمرُّ القياس**.

---

## ٩ · حدُّ الأجهزةِ لم يتحرّك

سجِّلْ دخولاً من نفسِ المتصفّحِ بعدَ المرور.

**المتوقَّع**: **لا يُطرَدُ أيُّ جهازٍ آخر**، وصفُّ الجهازِ الأصليُّ **ما زالَ يُطابَق** (لم يُجهَّلْ لأنّ عليه جلسةً نشطةً وعمرَه دونَ المدّة).

⛔ **والطردُ هو ما يُقاس.** تجهيلُ جهازٍ عليه جلسةٌ نشطةٌ يكسِرُ المطابقةَ فتصيرُ الآلةُ الواحدةُ آلتَين — وعندَ سقفِ جهازٍ للطالبِ هذا طردٌ من الحاسوبِ الذي يجلسُ أمامَه.

⚠️ **ويُقاسُ السباقُ صراحةً**: صفُّ جهازٍ `created_at = now()` بلا أيِّ جلسة، ثمّ المكنسة ⇒ **لا يُجهَّل**. بدونِ شرطِ `created_at < :before` يُجهَّلُ فراغاً.

---

## ١٠ · المحوُ يصلُ الجدولَين — ومَن مُحِيَ قبلَ اليوم (FR-014)

```bash
echo "" | php artisan tinker --execute="
  echo 'حسابات مُجهَّلة ما زالت تحمل عنواناً: '.App\Modules\Identity\Models\AuthSession::whereNotNull('ip_hash')
      ->whereIn('user_id', App\Models\User::where('email','like','anonymised+%')->pluck('id'))->count().PHP_EOL;
"
```

**المتوقَّع**: **صفر** بعدَ الهجرةِ لمرّةٍ واحدة.

⛔ **وبدونِها يبقى كلُّ حسابٍ مُحِيَ قبلَ اليومِ محتفِظاً بعنوانِه إلى الأبد** — الحارسُ يرجِعُ قبلَ المعاملة، ولا ذراعُ عمرٍ تبلغُه.

---

## ١١ · الأرشيفُ يحملُ المفتاحَينِ — ولو فارغَين (SC-008)

يُقاسُ على **حسابَين**: واحدٌ له جلسات، وواحدٌ ليست له.

```bash
echo "" | php artisan tinker --execute="
  \$u = App\Models\User::find(<ID>);
  foreach (app(App\Modules\Identity\Support\IdentityPersonalData::class)
      ->export(new App\Shared\Data\DataSubject(\$u)) as \$k => \$rows) {
      if (in_array(\$k, ['auth_session','device'], true))
          echo \$k.' => '.count(\$rows).' | '.implode(',', array_keys(\$rows[0] ?? [])).PHP_EOL;
  }
"
```

**المتوقَّع**: المفتاحانِ **حاضرانِ في الحالتَين** (بعددِ `0` في الثانية)، وقائمةُ الحقولِ **فيها `ip_hash` ولا فيها `fingerprint_hash`**.

⛔ **وطباعةُ أسماءِ الحقولِ ليست زينة**: القياسُ بالعددِ وحدَه لا يرى ما بداخلِ الملفّ.

⚠️ **و`ip_hash` يُصدَّرُ عن قصد** — `ExportFieldAllowlist` يقولُ بنصِّه إنّه غائبٌ عن قائمةِ المنعِ لأنّه «بياناتُ صاحبِه»، والمواصفةُ تفتحُ بأنّ «أين دخلَ حسابُك» لم يكنْ يصلُه. و`fingerprint_hash` وحدَه ممنوع.

---

## ١٢ · الأرقامُ الثلاثةُ تُضبَطُ من الشاشة (FR-005 · SC-005 · SC-007)

- **من اللوحة**: افتحْ شاشةَ إعداداتِ المنصّة، غيِّرِ الأرقامَ الثلاثة، شغِّلْ مرّةً أخرى، وقِسْ أنّ السلوكَ تبِعَها — بلا إصدارٍ وبلا إعادةِ تشغيلِ خدمة.
- **وبلا بذر**: احذِفِ الصفوفَ الثلاثةَ (وامسحِ الذاكرة)، شغِّلْ، وقِسْ أنّها تعملُ بالافتراضاتِ وتنتهي بلا خطأ.

⛔ **ويُقاسُ أنّ المدّةَ مرَّت على `SaveDataCategory`**: حارسُه يرفضُ ما دونَ الحدِّ الأدنى، وSQL خامٌّ يتخطّاه. اكتبْ قيمةً خارجَ المدى وتوقَّعْ رفضاً.

⚠️ **والمفتاحانِ يجبُ أن يظهرا في `PlatformSettings::all()`** — غيابُهما عن الخريطةِ يعني أنّ اللوحةَ لا تراهما وأنّ `flush()` لا يُبطِلُهما، والقراءةُ `rememberForever`.

---

## ١٣ · صفرُ تغييرٍ في الواجهة (FR-008)

```bash
git diff --name-only main... -- frontend/
```

**المتوقَّع**: **لا شيء**.

✅ **مقيسٌ ٢٠٢٦-٠٩-٢٢ بعدَ تنفيذِ US1: صفرُ ملفّاتٍ تحتَ `frontend/`** (وأحدَ عشرَ تحتَ `backend/`).

⚠️ **والصيغةُ `main...` بالنقاطِ الثلاثِ لا `git diff --stat frontend/`**: الثانيةُ تقرأُ غيرَ المُدرَجِ وحدَه، فهي فارغةٌ على أيِّ فرعٍ التُزِمَت تغييراتُه — أي «قياسٌ» يُخرِجُ الجوابَ المطلوبَ مهما كانَ الواقع.

⛔ **ولم يكنْ هذا مضموناً، وقد كادَ يسقُط.** الشاشةُ لا تعرِضُ العمودَينِ المُجهَّلَينِ أصلاً — لكنّها تعرِضُ **بابَ الدخول**، و`AuthSessionResource` كانَ يشتقُّه من `session_id` الذي تمحوه FR-015: فكانت كلُّ جلسةِ لوحةٍ مُجهَّلةٍ تنقلبُ «app» على الشاشةِ وفي الأرشيف. الإصلاحُ نقلُ الاشتقاقِ إلى `token_id` — **خلفيّةٌ بحتة**، فبقيَ الصفرُ صفراً.

⚠️ واسمُ الجهازِ يتغيّرُ عن عمد: صفُّ المقبرةِ يُظهِرُ «جهازٌ لم تعدْ تفاصيلُه محفوظة»، وهو جملةُ FR-008 واصلةً من حقلٍ قائم.

---

## ١٤ · البوّاباتُ الآليّة

```bash
cd backend && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
php vendor/bin/pest --filter="Retention|PersonalData|Erasure|Export|CategoryRegistry"
```

ثمّ ادفَعْ ودَعِ الشرائحَ الأربعَ تُشغِّلُ الحزمة.

⚠️ **ويُوسَّعُ `RetentionSweepIdempotencyTest` القائمُ بتأكيدٍ على `rows_anonymised`** — تأكيدُه اليومَ يُغطّي `rows_deleted` و`rows_archived` وحدَهما، فذراعُ تجهيلٍ لا تتقارَبُ **غيرُ مرئيّةٍ لحارسِ التكرارِ نفسِه**.
