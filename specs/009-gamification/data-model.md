# Data Model — ٠٠٩ التلعيب

تسعةُ جداول. **ثمانيةٌ كياناتٌ مذكورةٌ بالاسم في `Key Entities`، والتاسعُ مُشتقٌّ يُحذَف
ويُعاد بناؤه.** كلُّها `HasUuid`، وكلُّ ملفٍّ `declare(strict_types=1);`.

**العمودُ الحاسمُ في كلّ جدولٍ هو غيابُ `workspace_id` أو وجودُه** — وهو قرارُ `Q0` مكتوباً
بعمود، وطبقةُ كلٍّ منها مُعلَنةٌ في `plan.md › Constitution Check` كما يفرض الدستور.

---

## ١ — `gamification_actions` · بياناتٌ مرجعيةٌ منصّية (ب)

**لا `workspace_id`.** قيمةُ الفعل قرارُ منصّةٍ، ومدرّسٌ يرفع قيمةَ «حضور حصة» يضع طلابَه في
صدارةِ المادةِ والصفِّ والمنصّة.

| العمود | النوع | ملاحظة |
|---|---|---|
| `key` | `string(64)` **unique** | `session_attended` · `mistake_fixed` … — المفتاحُ الذي يسمّيه المستمع |
| `name_ar` | `string(120)` | |
| `xp` | `integer` (‏**موقَّع**) | سالبٌ مسموح — `payment_late` |
| `coins` | `integer` (‏**موقَّع**) | |
| `daily_cap` | `unsignedSmallInteger` **nullable** | `null` = بلا سقف |
| `is_active` | `boolean` default `true` | |

⚠️ **`xp` و`coins` موقَّعان بقصد**: الفعلُ السالبُ يعيش هنا لا في فرعٍ خاصٍّ في الكود، وعمودٌ
غيرُ موقَّعٍ يجعل `payment_late` مستحيلاً بلا هجرةٍ ثانية.

---

## ٢ — `award_entries` · **جسر** — وهو الحقيقةُ كلُّها

| العمود | النوع | ملاحظة |
|---|---|---|
| `student_user_id` | FK → `users` | المستخدمُ **العامّ**، لا صفٌّ لكلّ مدرّس |
| `action_key` | `string(64)` | نصٌّ لا FK — الفعلُ قد يُحذَف والقيدُ يبقى مقروءاً |
| `xp` · `coins` | `integer` موقَّعان | ⚠️ **القيمةُ المُطبَّقةُ فعلاً**، مُجمَّدةٌ لحظةَ المنح (‏§R10) |
| `workspace_id` | FK **nullable** | سياقُ المدرّس. `null` لفعلٍ لا مدرّسَ له |
| `course_id` · `lesson_id` | FK nullable | ⚠️ **بهما تُشتقّ النطاقاتُ الستّةُ كلُّها** (`FR-028ب`) |
| `source_type` · `source_id` | `string(64)` · `unsignedBigInteger` | ما أطلق المنح |
| `reversal_of_id` | FK → self, nullable | قيدٌ عكسيّ (`FR-010`) |
| `created_at` | timestamp | |

**الفهارس**:

- `unique(student_user_id, action_key, source_type, source_id)` — ⚠️ **حارسُ الإدمَاج وهو
  الفهرس نفسُه** (§R3). لا فحصَ قبله.
- `index(student_user_id, created_at)` — حسابُ السقفِ اليوميّ (`NFR-012`).
- `index(workspace_id, created_at)` · `index(course_id, created_at)` — إعادةُ بناءِ اللوحات.

**قواعدُ النموذج**: `booted()` يرمي على `updating` و`deleting`. الكتابةُ `insertOrIgnore`
بـ`uuid` و`created_at` **مُمرَّرَين صراحةً**، ثمّ قراءةٌ بالمفتاح، و**الغيابُ يرمي**.

---

## ٣ — `student_progress` · **منصّة (أ)** — صفٌّ واحدٌ لكلّ طالبٍ في المنتج كلِّه

⚠️ **لا `BelongsToWorkspace`، ولا يُضاف.** إضافتُه تُنتج ملفَّ خبرةٍ ومستوىً وسلسلةً **لكلّ
مدرّس**، فيرى الطالبُ مستواه ينخفض بتبديل السياق. عطلٌ صامتٌ ومتأخّر، وهو الاتجاهُ المرآةُ
الذي وُجد `PlatformOwnershipTest` لالتقاطه.

| العمود | النوع | ملاحظة |
|---|---|---|
| `user_id` | FK **unique** | صفٌّ واحد |
| `xp` | `unsignedBigInteger` | ⚠️ الخصمُ بـ`GREATEST(CAST(xp AS SIGNED) - ?, 0)` — §R6 |
| `level` | `unsignedSmallInteger` | مُشتقٌّ من `xp` وعتباتِ `levels`، مخزَّنٌ للقراءة |
| `current_streak` · `best_streak` | `unsignedSmallInteger` | |
| `last_active_day` | `string(10)` | ⚠️ **مفتاحُ يومِ الدوحة** (`YYYY-MM-DD`) لا timestamp |
| `shield_count` | `unsignedTinyInteger` | ⚠️ **عمودٌ لا جدول** — الدرعُ مِثليّ |
| `notified_level` | `unsignedSmallInteger` | آخرُ مستوىً أُبلِغ به؛ يمنع تكرارَ التهنئة |

---

## ٤ — `coin_balances` · **جسر** — `BelongsToWorkspace`

| العمود | النوع |
|---|---|
| `user_id` · `workspace_id` | FK — **`unique(user_id, workspace_id)`** |
| `coins` | `unsignedInteger` |

عملاتٌ كُسبت عند مدرّسٍ لا تُنفَق عند غيره (`FR-028ج`)، لأن المتجرَ متجرُه وهو من يتحمّل كلفةَ
المكافأة. **ولا مجموعَ كلّيّ في أيّ حمولة** — لا يوجد مجموعٌ صحيح، على نفسِ قاعدةِ ٠٠٦ في
الأرصدة.

---

## ٥ — `levels` و ٦ — `badges` · بياناتٌ مرجعيةٌ منصّية (ب)

`levels`: `level` (‏unique) · `name_ar` · `xp_threshold`.

`badges`: `key` (‏unique) · `name_ar` · `icon` · `rule_type` · `rule_value` · `is_active`.

⚠️ **شارةٌ مُنحت لا تُسحب بتغيّر قاعدتها** (`FR-017`) — ولذلك `badge_awards` صفٌّ قائمٌ بذاته
لا استعلامٌ يُعاد تقييمُه.

---

## ٧ — `badge_awards` · **منصّة (أ)**

`user_id` · `badge_key` · `awarded_at` — و**`unique(user_id, badge_key)`** هو حارسُ «مرّةً
واحدةً» (`FR-017`)، لا فحصٌ في الكود.

---

## ٨ — `rewards` · **مساحةُ عمل** — و ٩ — `redemptions` · **جسر**

`rewards` (‏`BelongsToWorkspace` كاملاً): `title` · `price_coins` · `stock` · `type`
(`discount` · `printed` · `deadline_extension` · `streak_shield`) · `monthly_cap` **nullable**
· `month_key` `string(7)` · `month_redeemed` · `is_active`.

⚠️ **`monthly_cap` إلزاميٌّ لكلّ نوعٍ ذي قيمةٍ نقدية** (`FR-031`) — مفروضٌ في `SaveReward`
لا في `FormRequest` وحدَه، لأن اللوحةَ والبذورَ تصلان الـAction بلا نموذجٍ خلفهما.

`redemptions`: `user_id` · `workspace_id` · `reward_id` · `coins_spent` · `status`
(`pending` · `fulfilled` · `rejected`) · `decided_by` · `decided_at`.

**الحجزُ والإفراجُ كلاهما جملةٌ شرطيةٌ واحدة** (§R7): الحجزُ يُنقص المخزونَ ويزيد عدّادَ الشهر
معاً؛ و**الرفضُ يفكّ الاثنين معاً** وإلا امتلأ السقفُ الشهريُّ بطلباتٍ مرفوضةٍ بينما لا شيءَ
يبدو معطوباً.

---

## ١٠ — `focus_sessions` · **منصّة (أ)**

`user_id` · `planned_minutes` · `started_at` · `ended_at` nullable · `status`
(`running` · `completed` · `aborted`).

المنحُ عند `completed` وحدَه وضمن سقفٍ يوميّ (`FR-040`)، والكتمُ عبر
`App\Shared\Support\FocusWindow` — ⚠️ **و`close()` تُنادى عند الإنهاء المبكر** وإلا بقيت
إشعاراتُه مكتومةً بعد جلسةٍ لم تعد قائمة.

---

## ١١ — `leaderboard_entries` · **مُشتقٌّ، منصّة (ب)**

⚠️ **لا مصدرَ حقيقةٍ هنا.** يُحذَف كلُّه ويُعاد بناؤه من `award_entries` بأمرٍ واحد (`SC-011`)،
وهذا هو ما يجعل غيابَ Redis بلا كلفة (§R1).

| العمود | النوع | ملاحظة |
|---|---|---|
| `scope_key` | `string(80)` | `platform` · `grade:12` · `subject:7` · `workspace:3` · `course:41` · `lesson:915` |
| `period_key` | `string(16)` | `w:2026-W34` (‏أسبوعُ الدوحة) أو `t:2026-1` للترم |
| `user_id` | FK | |
| `points` | `unsignedInteger` | خبرةُ الفترة، لا الخبرةُ التراكمية |
| `level_band` | `unsignedSmallInteger` | ⚠️ **مخزَّنٌ لا محسوب** — التشريحُ بالمستوى أوّلاً (§R5) |

**الفهارس**:

- `unique(scope_key, period_key, user_id)` — يجعل التحديثَ `upsert` صادقاً.
- ⚠️ `index(scope_key, period_key, level_band, points)` — **هذا هو `SC-008` كلُّه**: الرتبةُ
  عدُّ مدىً على الفهرس، وأفضلُ عشرين مسحُ مدىً بحدّ. لا فرزَ ولا `GROUP BY` وقتَ الطلب.

⚠️ **و`level_band` عمودٌ لا اشتقاقٌ وقتَ القراءة**: مستوى الطالب يتغيّر أثناء الأسبوع، فحسابُه
عند كلّ استعلامٍ يعني أن رتبتَه تُقاس ضدّ مجموعةٍ مختلفةٍ في كلّ تحميل. النطاقُ يُثبَّت عند
تحديثِ الصفّ.

---

## ما لا يوجد، بقصد

| الغائب | لماذا |
|---|---|
| جدولُ «دروعِ حماية» | الدرعُ مِثليّ — عمودٌ عددٌ يجيب كلَّ سؤالٍ يجيبه جدولٌ من صفوفٍ متطابقة |
| عمودُ «مجموعِ العملات عبر المدرّسين» | لا مجموعَ صحيحاً — عملاتُ مدرّسٍ لا تُنفَق عند غيره |
| عمودُ «الرتبة» على `student_progress` | الرتبةُ سؤالٌ عن نطاقٍ وفترة، لا صفةٌ للطالب |
| جدولُ «سجلّ تغييرِ قيمِ الأفعال» | `spatie/activitylog` مثبَّتٌ ويغطّيه |
| `workspace_id` على `student_progress` | §Q0 — إضافتُه تكرّر الشخصَ الواحدَ بعددِ مدرّسيه |
