# Phase 1 — Data Model: صفحةُ المادّةِ منهجاً ومجموعات

**Date**: 2026-08-27 · **Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

كلُّ جدولٍ جديدٍ مملوكٌ للمستأجرِ يستعملُ `BelongsToWorkspace` و`HasUuid`، وكلُّ ملفٍّ `declare(strict_types=1);`. المسارُ والحمولةُ يكشفان `uuid` وحدَه.

---

## أوّلاً: الكياناتُ الجديدة

### 1. `cohorts` — المجموعة · `Learning`

تشغيلةٌ مجدولةٌ لمادّةٍ واحدة. **لا تملكُ محتوًى ولا مالاً.**

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` · `workspace_id` | — | `BelongsToWorkspace` |
| `course_id` | FK → `courses` · **NOT NULL** | المجموعةُ لا تقومُ بلا مادّة |
| `name` | string(120) | «السبت ٤م» — يميّزُها في شاشةِ الاختيار |
| `description` | text nullable | |
| `capacity` | unsignedInteger **nullable** | `null` = بلا سقف (الافتراضُ ٤) |
| `members_count` | unsignedInteger default 0 | عدّادٌ مادّيّ — يُقتنَصُ بـUPDATE شرطيّة، لا `count()` |
| `status` | string(20) | `open` · `closed` (لا انضمامَ جديد) · `archived` |
| `archived_at` | timestamp nullable | |
| `created_by` | FK → `users` | |

**فهارس**: `unique(course_id, name)` · `index(workspace_id, course_id, status)`

⚠️ **`members_count` عمودٌ لا استعلام.** السعةُ تُقتنَصُ بجملةٍ واحدةٍ ذرّيّةٍ (`WHERE capacity IS NULL OR members_count < capacity`) — وهي لغةُ المقعدِ نفسُها في ٠٠٥؛ `count()` ثمّ `insert()` هو تعريفُ السباق، و`lockForUpdate()` بلا أثرٍ على SQLite فيُنتِجُ اختباراً محلّيّاً أخضرَ لا يُثبِتُ شيئاً عن MySQL.

⚠️ **الحذفُ ممنوعٌ والأرشفةُ هي البديل** (FR-035). ولا `SoftDeletes`: الحذفُ الناعمُ يضعُ الصفَّ خلفَ نطاقٍ عامّ، وهو بالضبط حيثُ لا تراهُ شاشةُ المدرّسِ ولا التدقيق — نفسُ تعليلِ `hidden_at` بدلَ `deleted_at` في ٠١٠.

---

### 2. `cohort_memberships` — العضويّة · `Learning`

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` · `workspace_id` | — | |
| `cohort_id` | FK → `cohorts` | |
| `course_id` | FK → `courses` | **مُكرَّرٌ عمداً**: الفهرسُ الفريدُ أدناهُ يحتاجُه بلا انضمام |
| `student_user_id` | FK → `users` | |
| `joined_at` | timestamp | |
| `closed_at` | timestamp nullable | `null` = مفتوحة |
| `closed_slot` | unsignedBigInteger **NOT NULL DEFAULT 0** | ⚠️ الحارس — انظر أدناه |

**فهارس**:
- `unique(student_user_id, course_id, closed_slot)` ← **FR-027 · SC-008**
- `index(cohort_id, closed_at)` — قائمةُ الزملاءِ والعدّاد

> ⚠️ **`closed_slot` هو الفرقُ بين حارسٍ يعضُّ وحارسٍ لا وجودَ له.**
> `unique(student, course)` على `closed_at IS NULL` **لا يعضُّ**، لأنّ NULL لا يساوي NULL على أيٍّ من المحرّكين — فكلُّ الصفوفِ المغلقةِ تتعايشُ وكذلك المفتوحة. الحارسُ صفرٌ سنتينل: `0` بينما مفتوحة، ثمّ **معرّفُ الصفِّ نفسِه** عندَ الإغلاق (فريدٌ بالتعريف، فالمغلقاتُ لا تتصادم).
> السابقةُ في هذا المستودعِ ثلاثيّة: `concept_stats.lesson_id` · `unlock_rules.course_id` · `award_entries.reversal_of_id`. والفهرسُ الجزئيُّ (`WHERE closed_at IS NULL`) ميزةُ Postgres **لا وجودَ لها في MySQL** — وهو سببُ كونِ `captured_order_id` عموداً فريداً قابلاً للعدمِ لا فهرساً جزئيّاً.
> **`closed_slot` ليست في `$fillable`**: تُكتَبُ داخلَ الجملةِ التي تملكُ الإغلاق، ومُسنَدةً جماعيّاً تصيرُ باباً ثانياً لفتحِ عضويّةٍ ثانيةٍ من خارجِ ذلك الفعل.

---

### 3. `cohort_membership_events` — السجلُّ التاريخيّ · `Learning`

**يُضافُ إليه ولا يُعدَّلُ ولا يُحذَفُ أبداً.**

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` · `workspace_id` | — | |
| `course_id` · `student_user_id` | FK | |
| `cohort_id` | FK nullable | الوجهة |
| `from_cohort_id` | FK nullable | المصدرُ في الانتقال |
| `event` | string(24) | `joined` · `transferred` · `left` · `removed` · `requested` · `approved` · `rejected` · `request_dropped` |
| `actor_user_id` | FK nullable | مَن نفّذ — `null` لحدثٍ نفّذهُ النظام |
| `reason` | string(500) nullable | |
| `created_at` | timestamp | `UPDATED_AT = null` — سابقةُ `ProgressHistory` |

**فهارس**: `index(course_id, student_user_id, created_at)` · `index(cohort_id, created_at)`

⚠️ **الامتناعُ مفروضٌ على النموذج**: `booted()` يرمي على `updating` و`deleting` — سابقةُ `LedgerEntry`. والفعلُ وحدَه لا يكفي، لأنّ `update()` الجماعيَّ لا يجلبُ نماذجَ فيتخطّاه. ⇒ **لا كتابةَ جماعيّةً على هذا الجدولِ إطلاقاً.**

⚠️ **الرفضُ حدثٌ كالقَبول** (FR-033): سجلٌّ يحفظُ المقبولَ وحدَه يُظهِرُ طالباً لم يطلبْ شيئاً قطّ.

---

### 4. `cohort_transfer_requests` — طلبُ الانتقال · `Learning`

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` · `workspace_id` | — | |
| `course_id` · `student_user_id` | FK | |
| `to_cohort_id` | FK → `cohorts` | |
| `from_cohort_id` | FK → `cohorts` | العضويّةُ القائمةُ لحظةَ الطلب |
| `student_reason` | string(500) nullable | |
| `status` | string(16) | `pending` · `approved` · `rejected` · `dropped` |
| `decided_by` · `decided_at` · `decision_reason` | — | `decision_reason` **إلزاميٌّ عندَ الرفض** (FR-028ح) |
| `pending_slot` | unsignedBigInteger **NOT NULL DEFAULT 0** | نفسُ حارسِ `closed_slot` |

**فهارس**: `unique(student_user_id, course_id, pending_slot)` ← «طلبٌ معلَّقٌ واحد» (FR-028ز) · `index(to_cohort_id, status)` — طابورُ المدرّس

⚠️ **الطلبُ لا يمسُّ العضويّةَ** (FR-028و): لا عمودَ في `cohort_memberships` يتغيّرُ عندَ التقديم. الطالبُ في مجموعتِه كامِلَ الحقوقِ حتى لحظةِ القَبول — وإلّا خرجَ من مكانٍ قبلَ أن يدخلَ آخَر، انتظاراً لجوابٍ قد لا يأتي.

⚠️ **السعةُ تُقاسُ عندَ الموافقة**: اقتناصُ `cohorts.members_count` يقعُ **داخلَ فعلِ الموافقة**. القياسُ عندَ التقديمِ يقبلُ طلبين على مقعدٍ واحد.

---

### 5. `conversation_write_bans` — منعُ الكتابةِ في خيطٍ بعينِه · `Community`

**أداةٌ ثالثةٌ، لا استعمالٌ لواحدةٍ قائمة** — انظر R8.

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` · `workspace_id` | — | |
| `conversation_id` | FK → `conversations` | ⚠️ **الخيطُ، لا المساحة** |
| `user_id` · `issued_by` | FK → `users` | |
| `reason` | string(500) | إلزاميّ — رفضٌ صامتٌ يُقرأُ عُطلاً |
| `expires_at` | timestamp **nullable** | `null` = مفتوحٌ يُرفَعُ يدويّاً |
| `lifted_at` · `lifted_by` | nullable | |

**فهارس**: `index(conversation_id, user_id, created_at)`

⚠️ **الانتهاءُ يُحكَمُ في PHP لا في SQL** — `expires_at === null || expires_at->isFuture()`، وأحدثُ صفٍّ يفوز. سابقةُ `BanReader` حرفيّاً، ولسببِها: `DATE_ADD` على MySQL مقابلَ `datetime()` على SQLite لهجتان لسؤالٍ واحد. ⚠️ والمقارنةُ العكسيّةُ (`expires_at > now()` وحدَها) تقرأُ **المنعَ الدائمَ منتهياً** — وهو المنعُ الوحيدُ الذي لا يجوزُ أن ينتهيَ من تلقاءِ نفسِه.

---

## ثانياً: التعديلاتُ على جداولَ قائمة

| الجدول | التغيير | لماذا |
|---|---|---|
| `class_sessions` | `+ cohort_id` FK **nullable** | FR-025. قابلٌ للعدمِ لأنّ مادّةً بلا مجموعاتٍ لا تحتاجُه (FR-036)، **ولأنّ Q3 يمنعُ الترحيلَ الآليّ**: كلُّ صفٍّ قائمٍ اليومَ يبقى `null` ويُحجَبُ حتى يُسنِدَه المدرّسُ يدويّاً |
| `class_sessions` | فهرسٌ `(workspace_id, course_id, cohort_id, starts_at)` | ⚠️ يحلُّ محلَّ `(workspace_id, course_id, starts_at)` الذي يعتمدُ عليه `previousCountableSessionIds()` — R6 |
| `conversations` | `+ cohort_id` FK **nullable** | خيطُ المجموعة |
| `conversations` | فهرسٌ `unique(cohort_id)` | خيطٌ واحدٌ لكلِّ مجموعةٍ لا أكثر — والحلُّ الكسولُ يجعلُ السباقَ ممكناً، فالفهرسُ هو الحارس |
| `announcements` | لا عمودَ جديد | `scope` نصٌّ و`scope_id` قائمان — الجديدُ **قيمةٌ رابعة** `cohort`، لا بنية |

⚠️ **لا تغييرَ في `enrollments` إطلاقاً.** وهذا هو القرارُ الحاملُ للسبيكِ كلِّها: العضويّةُ صفٌّ بجانبَ التسجيلِ لا حقلٌ فيه، فالانتقالُ إغلاقُ صفٍّ وفتحُ آخَر — و«**ولا يتم فقد اي شيئ**» صادقٌ بالبناءِ لأنّ التقدّمَ والدرجاتِ والشهادةَ لم تكن في المجموعةِ يوماً (FR-029).

⚠️ **ولا عمودَ ماليٌّ في أيِّ جدولٍ أعلاه.** لا سعرَ ولا رصيدَ ولا أجر. `billable_seats` تُكتَبُ مرّةً عندَ موعدِ الإلغاءِ ولا تُعادُ حسابُها — فانتقالٌ بعدَها لا يحرّكُ ريالاً ويجبُ ألّا يحاول.

---

## ثالثاً: العقدُ المشترك

### `App\Shared\Contracts\CohortDirectory` — تُنفّذُه `Learning`

```
hasOpenMembership(User, int courseId): bool          ← FR-028أ (بوّابةُ المحتوى)
openMembershipCohortId(User, int courseId): ?int     ← جدولُ الطالبِ وخيطُه
joinableCohortsExist(int courseId): bool             ← ⚠️ FR-028ب — الصمّام
wasEverMember(User, int cohortId): bool              ← FR-046 (القراءةُ بعدَ الانتقال)
activeMemberIdsFor(int cohortId): list<int>          ← AnnouncementAudience · الفان-آوت
cohortIdsForSessions(list<int>): array<int, ?int>    ← ⚠️ جماعيٌّ — R6
```

⚠️ **كلُّ قراءةٍ يُطلَبُ منها أن تُجيبَ عن قائمةٍ هي جماعيّةٌ بالتوقيع.** موردُ العرضِ يعملُ مرّةً لكلِّ صفّ، فقراءةٌ مفردةٌ داخلَه N+1 بالبناء — وهو عيبُ `ClassSessionResource` نفسُه واصلاً من بابٍ جديد.

⚠️ **`joinableCohortsExist()` أهمُّ توقيعٍ في العقد.** بغيرِه تصيرُ بوّابةُ العضويّةِ الإجباريّةِ قفلاً دائماً على محتوًى مدفوعٍ حين تكونُ كلُّ المجموعاتِ مكتملةً أو مؤرشَفة — وهي عائلةُ أسوأِ عيبٍ يسجّلُه هذا المستودع: شرطٌ لا يوجدُ فعلٌ يُحقِّقُه.

---

## رابعاً: الحالاتُ المحسوبة (لا تُخزَّنُ في عمودٍ أبداً)

| الحالة | تُشتقُّ من | لماذا لا عمود |
|---|---|---|
| `curriculum item state` (مكتمل · مفتوح · مقفولٌ بسبب) | `LessonGate::forTree()` — R2 | تختلفُ بالطالبِ وتتغيّرُ بأوّلِ واجبٍ يُسلَّم؛ عمودٌ يحتاجُ مسحاً لكلِّ طالبٍ لكلِّ عنصر |
| `cohort.is_full` | `capacity` و`members_count` | مشتقٌّ من عمودين حاضرين |
| `write ban active` | أحدثُ صفٍّ + الساعةُ في PHP | R8 |
| `next session` | `class_sessions` + العضويّة | لحظيّةٌ بطبيعتِها |
| `rank` · `level` | `ReadRanksFor` | ⚠️ من جدولين مختلفين، والغيابُ **حالةٌ** — المفتاحُ يُحذَفُ ولا يُطبَعُ صفر |

---

## خامساً: انتقالاتُ الحالة

**المجموعة**: `open` ⇄ `closed` → `archived` (نهائيّةٌ · لا حذف)
**العضويّة**: `open` → `closed` (نهائيّةٌ — إعادةُ الانضمامِ صفٌّ جديد، فالسجلُّ لا يُعادُ كتابتُه)
**طلبُ الانتقال**: `pending` → `approved` · `rejected` · `dropped`

⚠️ **`dropped` ليست زينةً**: طلبٌ معلَّقٌ على مجموعةٍ أُرشِفَت، أو طالبٌ نقلَه المدرّسُ يدويّاً — كلاهما يُسقِطُ الطلبَ بسببٍ مكتوبٍ ويُقيَّدُ في السجلّ. طلبٌ يبقى معلَّقاً على مجموعةٍ لم يعُدْ لها وجودٌ هو طابورٌ ينمو ولا يُقرَأ.
