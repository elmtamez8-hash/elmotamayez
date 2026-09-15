# Phase 1 — نموذجُ البيانات

---

## جدولٌ جديد · `lesson_cohort_scopes`

**غيابُ الصفِّ هو «للجميع».** فلا عمودَ بقيمةٍ مبدئيّةٍ ولا ترحيلَ بياناتٍ ولا حالةَ ثالثة —
وFR-002 («لا يتغيّرُ شيءٌ لأحدٍ بمجرّدِ الشحن») صحيحةٌ بالبناء.

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` | bigint | |
| `workspace_id` | unsignedBigInteger · index | `BelongsToWorkspace` |
| `uuid` | uuid · unique | `HasUuid` — وهو المعرّفُ العامُّ في هذا المستودع |
| `lesson_id` | unsignedBigInteger · index | |
| `cohort_id` | unsignedBigInteger · index | |
| `timestamps` | | |

**الفهارسُ مُسمّاةٌ باليد** — MySQL ترفضُ ما جاوزَ ٦٤ محرفاً وSQLite لا حدَّ لها، وقد أوقفَ
ذلك نشرةً في ٢٠٢٦-٠٩-٠٩:

- `lesson_cohort_scopes_uuid_unique` → (`uuid`)
- `lesson_cohort_scopes_pair_unique` → (`lesson_id`, `cohort_id`) — **الحارسُ من صفٍّ مكرَّر**
- `lesson_cohort_scopes_cohort_index` → (`cohort_id`)
- `lesson_cohort_scopes_workspace_index` → (`workspace_id`)

**لا مفتاحَ أجنبيٌّ على `cohort_id`** — اتّساقاً مع الجداولِ المجاورةِ في هذا المستودع. وحالةُ
«مجموعةٌ أُرشِفت أو حُذِفت» (حالةُ الحافّة) لا تحتاجُه: الصفُّ يبقى، والعنصرُ يبقى خارجَ
المقامِ لأنّ **وجودَ صفٍّ** هو الشرطُ لا صلاحيّةُ ما يشيرُ إليه — فلا يقعُ في مقامِ أحدٍ أبداً.

---

## عمودٌ جديد · `lessons.release_session_id`

```
$table->unsignedBigInteger('release_session_id')->nullable()->after('class_session_id');
$table->index('release_session_id', 'lessons_release_session_id_index');
```

- **مفرَّغٌ = يظهرُ الآن** (FR-006).
- **ليس `$fillable`**: يُكتَبُ من Action واحدٍ يتحقّقُ أنّ الحصّةَ من كورسِ الدرسِ نفسِه.
- **ليس `class_session_id`** ولا يُقرأُ مكانَه في أيِّ موضعٍ — انظر `research.md` · ق-١.

---

## القراءةُ — ثلاثةُ أسئلةٍ لا أكثر

| السؤال | المصدر | التكلفة |
|---|---|---|
| في أيِّ مجموعاتٍ هذا الطالبُ الآن؟ | `CohortDirectory::openMembershipCohortIdsFor()` | استعلامٌ واحدٌ للشجرة |
| ما نطاقُ كلِّ عنصر؟ | `lesson_cohort_scopes whereIn lesson_id` | استعلامٌ واحد، **ولا يُسأَلُ إن لم يكنْ في الشجرةِ عنصرٌ مقصور** |
| أأُفرِجَ عن حصّةِ الربط؟ | `SessionReleaseStatus::releasedSessionIds()` | استعلامٌ واحد، **ولا يُسأَلُ إن لم يكنْ في الشجرةِ ربطٌ** |

---

## الحكمُ — أينَ يُكتَبُ بالضبط

### ١ · المقام — `Lesson::scopeProgressEligible()`

شرطانِ يُضافانِ بجوارِ `whereNull('lessons.class_session_id')` القائم:

```php
->whereNull('lessons.release_session_id')
->whereDoesntHave('cohortScopes')
```

⚠️ **وهذا هو نصفُ المواصفةِ الخطير.** الشرطانِ خاصّيّتانِ في العنصرِ نفسِه لا في القارئ
(FR-013أ)، فالمقامُ يبقى **واحداً لكلِّ كورسٍ** ويُقرأُ كما يُقرأُ اليوم، وتصيرُ FR-012
وFR-014 وFR-015 صحيحةً بالبناءِ لا بالحراسة.

### ٢ · البابُ — `LessonGate::for()` و`forTree()`

الفرعانِ الجديدانِ يقعانِ **بعدَ** `isVisibleChain()` و`isOpen()` و«التسجيلُ الفعّال»، و**قبلَ**
سؤالِ التسلسل — لأنّ عنصراً خارجَ النطاقِ لا يقفُ في طريقِ شيءٍ أصلاً (FR-015):

```
… isVisibleChain  →  isOpen  →  grantsContentAccess  →  class_session_id (٠٣٥)
   →  [جديد] النطاق      ⇒ out_of_scope
   →  [جديد] الموعد      ⇒ unreleased
   →  التسلسل
```

⚠️ **والفرعانِ يُكتَبانِ في الدالّتَينِ معاً** — `LessonGateParityTest` يُسقِطُ البناءَ على
اختلافِهما، وهو الصنفُ الذي وُجِدَ لمنعِ «تهجئتَينِ لسؤالٍ واحد».

### ٣ · العرض — `CurriculumResource`

الرموزُ الثلاثةُ الجديدةُ تُسقِطُ الصفَّ كما يُسقِطُه `not_visible` اليوم، ويُسقَطُ الفصلُ ثمّ
القسمُ إذا فرغ. ⚠️ **و`locked_session_count` لا يعُدُّ صفّاً أُسقِط** — عددٌ يشيرُ إلى ما لا
يُرى هو الرقمُ الناقصُ بلا سببٍ الذي تمنعُه ٠٣٥ · FR-025 نفسُها.

### ٤ · الامتحان — `StartAttempt`

الحارسُ القائمُ `guardSessionContent()` يُوسَّعُ بالسؤالَينِ نفسَيهما عبرَ **صفِّ الشجرةِ
نفسِه** الذي يقرؤُه الآن. شاشةٌ تُخفي زرّاً ليست حارساً — والبابُ هو Action.

---

## الكتابة — Action واحد

`SaveLessonAudience` (وحدةُ `Courses`): يستقبلُ الدرسَ وقائمةَ معرّفاتِ المجموعاتِ ومعرّفَ حصّةِ
الإفراج، ويكتبُ الاثنَينِ **في معاملةٍ واحدة**.

- يتحقّقُ أنّ كلَّ مجموعةٍ من **كورسِ الدرسِ نفسِه** — ومعرّفٌ من كورسٍ آخرَ يُرفَضُ برسالة.
- يتحقّقُ أنّ حصّةَ الإفراجِ من كورسِ الدرسِ نفسِه — وإلّا صارَ الإفراجُ رهنَ حصّةٍ لا يحضرُها
  أحدٌ من هؤلاءِ الطلاب.
- **يُطلِقُ `CourseStructureChanged` مرّةً واحدةً للكورس** إن تغيّرَ أيُّ محورٍ — الحدثُ
  والمستمعُ القائمانِ (`ResyncCourseProgress`)، بلا سطرِ مزامنةٍ جديد (FR-016).
- **لا يمسُّ `lesson_progress`** أبداً: ما تمَّ تمّ (FR-017).

---

## دالّةٌ تُضافُ إلى عقدٍ قائم · `SessionAttendanceDirectory`

```php
/** @param list<int> $classSessionIds @return list<int> */
public function releasedSessionIds(array $classSessionIds): array;
```

المُفرَجُ عنه = `delivered_at IS NOT NULL` **أو** `status = 'cancelled'`.

**ولماذا هذا العقدُ بعينِه ولا عقدَ جديد**: هو يحملُ اليومَ
`previousCountableSessionIds(array): array` — سؤالاً عن **حالِ حصّةٍ** لا عن حضورِ أحد، بالصيغةِ
الجماعيّةِ نفسِها. وهو مربوطٌ في `LiveSessionsServiceProvider:111`، و`Learning` تسألُه بالفعلِ
ولا تسمّي `class_sessions` في أيِّ ملفٍّ لها. عقدٌ خامسٌ بجوارَه جوابٌ ثانٍ لسؤالٍ له بيت.

**والصيغةُ جماعيّةٌ بلا مفرَد**: القارئُ شجرةٌ كاملة، ومفردٌ بجوارَها دعوةٌ إلى استعلامٍ لكلِّ
صفّ — وهو ما تحرسُه ميزانيّةُ المنهجِ القائمة.

---

## ⛔ القارئُ الواحد · `Courses\Support\LessonAudience`

**الأبوابُ أربعةٌ، والحكمُ واحد.** صنفٌ واحدٌ يُسأَلُ من الأربعةِ جميعاً:

```php
/** @param iterable<Lesson> $lessons @return array<int,string|null> معرّفُ الدرس ⇒ رمزُ الإخفاء أو null */
public static function hiddenAmong(User $viewer, iterable $lessons): array;

public static function hiddenFor(User $viewer, Lesson $lesson): ?string;
```

- **المفردُ يُشتَقُّ من الجماعيِّ** (`hiddenAmong([$lesson])[$id] ?? null`) فلا تهجئتان.
- المؤلّفُ (عضوُ مساحةِ العمل) يُستثنى في الصنفِ نفسِه، فلا يُعادُ استثناؤه في أربعةِ مواضع.
- **القارئُ الأوّل**: `LessonGate::for()`/`forTree()` — والرمزُ يصيرُ سببَ الرفض.
- **القارئُ الثاني**: `IssuePlaybackGrant::mayWatch()` و`mayWatchMany()`. ⛔ بدونَه، الصفُّ
  مخفيٌّ من المنهجِ والملفُّ يُخدَمُ لمن يحملُ المعرّف — بابانِ يختلفان.
- **القارئُ الثالث**: `StartAttempt` عبرَ صفِّ الشجرةِ الذي يشيرُ إلى الامتحان.
- **القارئُ الرابع**: `ExamController::index()` وبركةُ التدريبِ ودفترُ الأخطاء.

---

## تعديلٌ على قائمٍ · `EloquentSessionContentAccess::unlockableSessionIds()`

يُضافُ شرطُ التسليم/الإلغاءِ إلى الفرعَينِ كليهما (المقعدُ والتسجيلُ+المجموعة)، فلا يُوعَدُ
بفتحٍ يرفضُه `unlockOfferFor()` بعدَ ضغطةٍ واحدة — `research.md` · ق-٤.
