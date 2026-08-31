# Data Model — التوسّع والتطبيق (spec 012)

ستّةُ جداولَ جديدة. لا جدولَ قائمٌ يُغيَّر عمودُه، ولا عمودَ يُسقَط.

**التصنيفُ الدستوريُّ قرارٌ مُلزِمٌ يُوثَّق في المواصفة** (الدستور **v1.2.0** §I):

| الجدول | الطبقة | الحارسُ الفعليّ | الاختبارُ الإلزاميّ |
|---|---|---|---|
| `adaptive_sessions` | **جسر** | شرطٌ صريحٌ في الـAction على `student_user_id` | `WorkspaceIsolationTest` + اختبارُ الـAction |
| `concept_masteries` | **جسر** | الشرطُ الصريحُ نفسُه | `WorkspaceIsolationTest` + اختبارُ الـAction |
| `study_rooms` | **جسر** | `StudyRoomAccess` عند الإنشاءِ والانضمامِ والاشتراك | `WorkspaceIsolationTest` + `StudyRoomEligibilityTest` |
| `study_room_questions` | **جسر** | يتبع غرفتَه | `WorkspaceIsolationTest` |
| `study_room_participants` | **جسر** | ملكيّةُ الصفِّ + `StudyRoomAccess` | `WorkspaceIsolationTest` |
| `push_subscriptions` | **مملوكٌ للمنصّة — الصنف (أ)** | **ملكيّةُ الصفِّ للمستخدم، لا مساحةُ العمل** | `PlatformOwnershipTest` **بالاتّجاهَين** |

---

## لماذا خمسةُ جسورٍ وواحدٌ منصّيّ

**الجسور**: الفكرةُ والسؤالُ والدرسُ مملوكةٌ لمساحةِ العمل — و`Concept` يقول ذلك في تعليقِه:
«Concepts are not shared between teachers». والطالبُ مملوكٌ للمنصّة. فصفٌّ يربط الاثنَين هو
تعريفُ الجسرِ في الدستور: يحمل `workspace_id` للسياقِ ويشير إلى المستخدمِ العامّ، كما يفعل
`Enrollment`.

**`push_subscriptions` منصّيٌّ من الصنف (أ)**: الاشتراكُ خاصّيةُ **جهازٍ يملكه شخص**، لا
شيءٌ ينتجه مدرّس. و`BelongsToWorkspace` عليه ينسخ الجهازَ الواحدَ بعددِ مدرّسي الطالبِ فيصله
الإشعارُ ثلاثَ مرّات — العطلُ المرآةُ الذي يُوجَد `PlatformOwnershipTest` لالتقاطِه في
الاتّجاهَين. سابقتُه `notification_preferences`، المصنَّفُ في نصِّ الدستورِ ضمنَ الطبقةِ نفسِها.

**⚠️ و`BelongsToWorkspace` على جسرٍ يصل إليه طالبٌ يحرس شيئاً قريباً من لا شيء —
والنتيجةُ أنّ الاختبارَ الإلزاميَّ لا يكفي وحدَه.** `WorkspaceScope::apply()` لا يضيف شرطاً
حين يكون `WorkspaceContext::id()` هو `null`، وهو `null` لكلِّ طالب. فالسمةُ تُوضَع لأنّ
الدستورَ يوجبها ولأنّها تحرس مسارَ المدرّس، **والحارسُ الفعليُّ على مسارِ الطالبِ هو الشرطُ
الصريحُ في الـAction**. و`WorkspaceIsolationTest` يختبر السمةَ — أي يمرّ بلا جهدٍ بينما
الحارسُ الحقيقيُّ غيرُ مختبَرٍ إطلاقاً. **فلكلِّ جسرٍ اختبارانِ لا واحد**: اختبارُ السمة،
واختبارُ الشرطِ في الـAction بمستخدمٍ لا `last_workspace_id` له وسياقٍ مُصفَّر.

وعليه: **لا ربطَ ضمنيّاً للنموذجِ على أيِّ مسارٍ يصل إليه طالب** — الـuuid يُحَلّ داخلَ
الـAction بعد فحصِ الأهلية، على سابقةِ `RedeemReward`.

---

## US1 — المسار التكيّفي

### `adaptive_sessions`

```
id                    bigint PK
uuid                  uuid unique
workspace_id          unsignedBigInteger index          -- سياقٌ لا ملكيّة
student_user_id       unsignedBigInteger index          -- → users.id
concept_id            unsignedBigInteger index          -- → concepts.id
attempt_id            unsignedBigInteger                -- → exam_attempts.id
current_difficulty    string(8)                          -- easy | medium | hard
ceiling_difficulty    string(8)                          -- ⚠️ أعلى متاحٍ في هذه الفكرة
correct_streak        unsignedSmallInteger default 0
served_count          unsignedSmallInteger default 0
status                string(16)                         -- running | mastered | ended
running_key           string(64) nullable unique         -- ⚠️ المطالبةُ الذرّيّة
mastered_at           timestamp null
ended_at              timestamp null
timestamps

unique  (attempt_id)
index   (student_user_id, concept_id, status)
```

> الأسهمُ أعلاه علاقاتٌ منطقيّة، لا قيودَ مفاتيحَ أجنبيّة: لا `foreign()` ولا `constrained()`
> في مجلّدِ هجراتِ هذه الوحدةِ كلِّه، وقيدٌ حقيقيٌّ هنا كان سيمنع تعطيلَ سؤالٍ ظهر في غرفة.
> الأعمدةُ `unsignedBigInteger` مفهرسة، كما في كلِّ الوحدة.

**`attempt_id` فريدٌ ولا يُشارَك** — وهو المفتاحُ الذي يجعل FR-005 صحيحاً بالبناء: كلُّ إجابةٍ
صفٌّ في `exam_answers` تحت محاولةٍ `is_practice = true` و`exam_id = null`، فلا تدخل الدرجاتِ
الرسميةَ (`EloquentStudentGradeDirectory.php:43` يستثنيها صراحةً) **وتدخل دفترَ الأخطاء**
لأنّ `MistakeNotebook` مشتقٌّ من `exam_answers` وحدَها.

**⚠️ `ceiling_difficulty` هو ما يجعل الإتقانَ ممكناً أصلاً.** الإتقانُ إصاباتٌ متتاليةٌ عند
**السقف**، والسقفُ أعلى صعوبةٍ لها سؤالٌ متاحٌ لهذا الطالبِ في هذه الفكرة — يُحسَب باستعلامٍ
واحدٍ عند البدءِ ويُخزَّن. لو كان السقفُ `hard` حرفيّاً، لَما بلغَت الإتقانَ **أبداً** فكرةٌ
ليس فيها سؤالٌ `hard` — وهي الحالةُ الحدّيّةُ التي تسمّيها المواصفةُ بنصِّها — فلا صفَّ إتقانٍ
ولا نقطة، بلا خطأٍ في أيِّ مكان. عائلةُ «عنصرٌ يدخل المقامَ ولا يُكمَل أبداً».

**⚠️ `running_key` هو الحارسُ، والفهرسُ الثلاثيُّ تحته مجرّدُ قراءةٍ سريعة.** `"{student}:{concept}"`
يُكتب عند البدءِ ويُفرَغ عند `mastered`/`ended`. بدونه: بدآنِ متوازيانِ يمرّانِ معاً — لكلٍّ
محاولتُه فـ`unique(attempt_id)` لا يعضّ — ثمّ تبلغ الجلستانِ الإتقانَ فيُمنَح مرّتَين. وهو
مثالُ `captured_order_id` حرفيّاً: عمودٌ قابلٌ للإفراغِ يحمل `unique()`، لأنّ MySQL بلا فهارسَ
جزئيّة و`WHERE status = 'running'` على فهرسٍ ميزةُ Postgres لا وجودَ لها هنا. و`NULL` لا
تصطدم بـ`NULL`، فكلُّ الجلساتِ المنتهيةِ تتعايش.

**`correct_streak` يُصفَّر عند كلِّ خطأٍ وعند كلِّ تغيُّرِ صعوبة**، وإلّا صار جمعاً عبر
درجتَين لا إتقاناً لواحدة. **والعدّاداتُ تتحرّك بعد نجاحِ إدراجِ الإجابةِ وحدَه** — الإدراجُ
هو نقطةُ التسلسل — و`served_count` بـ`increment()` لا `$model->col + 1` (سابقةُ
`recording_attempts`).

### `concept_masteries`

```
id                    bigint PK
uuid                  uuid unique
workspace_id          unsignedBigInteger index
student_user_id       unsignedBigInteger index
concept_id            unsignedBigInteger index
mastered_at           timestamp
threshold_correct     unsignedTinyInt                    -- العتبةُ المتحقَّقةُ عندها
threshold_difficulty  string(8)                          -- والسقفُ المتحقَّقُ عنده
source_session_id     unsignedBigInteger null
created_at index                                          -- للكنسِ بالعمر
timestamps

unique  (student_user_id, concept_id)
```

**يُخزَّن ولا يُشتقّ** — خروجٌ مقصودٌ عن تفضيلِ المستودعِ للاشتقاق: العتبةُ **صفٌّ في
`platform_settings` يعدّله مشغّل** (FR-007)، فإتقانٌ مشتقٌّ يعني أنّ رفعَ العتبةِ من ٣ إلى ٤
يسحب الإتقانَ من كلِّ طالبٍ بلغه، بأثرٍ رجعيٍّ، بلا سطرٍ في أيِّ سجلّ.

**والعمودانِ ليسا زينة**: بدونهما لا يستطيع أحدٌ لاحقاً أن يقرأ **على أيِّ معيارٍ وأيِّ سقفٍ**
مُنِح هذا الصفّ — وهو أوّلُ سؤالٍ يُسأل بعد أوّلِ تعديل. و`threshold_correct` عمودُه
`unsignedTinyInt`، فمشغّلٌ يكتب `300` في صفِّ الإعدادِ يجعل الكتابةَ **رفضاً في MySQL الصارمةِ
وقبولاً صامتاً في SQLite** — الحدُّ يُفرَض في `AdaptiveSettings` عند القراءةِ لا في عرضِ
العمود.

**والفهرسُ الفريدُ يعضّ فعلاً**: كلا عمودَيه `NOT NULL`. فهرسٌ فريدٌ يحمل عموداً يقبل `NULL`
لا يعضّ — وهو ما كلّف `concept_stats.lesson_id` و`unlock_rules.course_id` صفَّ حارسٍ بقيمةِ
`0` في هذه الوحدةِ نفسِها. **وهو أيضاً مصدرُ عدمِ الأثرِ للمنحة**: صفُّ الإتقانِ واحدٌ لكلِّ
(طالب، فكرة) بالتعريف، بخلافِ `source_session_id` الذي يختلف بين جلستَين.

### ما يُعدَّل، لا يُنشأ

| الملفّ | التعديل |
|---|---|
| `Enums/Difficulty.php` | `rank()` · `easier()` · `harder()` — الترتيبُ خاصّيةُ مفردةٍ لا فرعٌ في منطقِ الأعمال |
| `Enums/AdaptiveStatus.php` **جديد** | `running` · `mastered` · `ended` — كلُّ عمودِ حالةٍ في هذه الوحدةِ له enum (`AttemptStatus`, `ExamStatus`, `SubmissionState`, `ImportStatus`) |
| `PlatformSettings::KEYS` | أربعةُ مفاتيحِ `assessments.adaptive.*` |
| `Support/AnswerMarker.php` **جديد** | تصحيحُ الإجابةِ الواحدةِ + إطلاقُ `MistakeResolved` |
| `Actions/GradeAttempt.php` | يستعمل `AnswerMarker`؛ **ويرفض محاولةً تحمل إجاباتٍ سلفاً، قبل المطالبة** |

**⚠️ و`AnswerMarker` يأخذ مجموعةَ «ما أخطأ فيه سابقاً» مُعطاةً ولا يشتقُّها.**
`GradeAttempt::previouslyWrongQuestionIds()` استعلامٌ واحدٌ **قبل الحلقة** — وتعليقُه يمنع
الشكلَ لكلِّ سؤال — **وقبل كتابةِ أيِّ صفِّ إجابة**، لأنّ كلَّ سؤالٍ يجد بعد الإدراجِ خطأً
لنفسِه. مُصحِّحٌ يشتقّها بنفسِه يجعل `GradeAttempt` هو الـN+1 الذي يمنعه تعليقُه.

**⚠️ والرفضُ قبل المطالبةِ هو ما يمنع عطباً دائماً.** `POST /attempts/{attempt}/submit` مربوطٌ
ضمنيّاً بالـuuid ويُفوَّض بالملكيّةِ وحدَها، ومحاولةُ الجلسةِ ملكُ الطالب. و`GradeAttempt`
يكتب صفَّ إجابةٍ لكلِّ عنصرٍ بـ`Answer::create()` — فالاصطدامُ مضمون ⇒ `QueryException` ⇒
٥٠٠. **والقاتل**: `claimForGrading()` خارجَ `DB::transaction()`، فالمعاملةُ تتراجع والمطالبةُ
لا، وتبقى المحاولةُ عند `grading` **بلا كاتبٍ في الشجرةِ يعيدها**. الحارسُ `exists()` واحدٌ
على `exam_answers` **قبل** المطالبة ⇒ `DomainException` ⇒ ٤٢٢. ومعه تحريرُ المطالبةِ في
`catch` — عطلٌ سابقٌ لهذه المرحلةِ يُصلَح لأنّها هي التي تجعله قابلاً للوصول.

**ولا `AttemptFinalized` من `EndAdaptiveSession`**: مستمعُه في الإشعاراتِ يُطلق لمحاولاتِ
التمرينِ **عمداً**، فكلُّ جلسةٍ كانت ستُنتج إشعارَ «نتيجةُ اختبار». الختمُ مباشر
(`submitted_at`, `finalized_at`, `score`) والحدثُ الوحيدُ `ConceptMastered`.

---

## US2 — الويب كتطبيق

### `push_subscriptions`

```
id                    bigint PK
uuid                  uuid unique
user_id               unsignedBigInteger index           -- ⚠️ ولا workspace_id بتاتاً
endpoint              text
endpoint_hash         char(64)                            -- sha256(endpoint)
p256dh                string(255)
auth                  string(255)
user_agent            string(255) null
last_used_at          timestamp null
created_at index                                           -- للكنسِ بالعمر
timestamps

unique  (user_id, endpoint_hash)
```

**`endpoint_hash` لا `endpoint`**: عناوينُ خدماتِ الدفعِ تتجاوز حدَّ الفهرسِ في MySQL
(٣٠٧٢ بايت على `utf8mb4`) بمراحل، فالفهرسُ على `text` إمّا يُرفَض أو يُقصَّ إلى بادئةٍ تصطدم.

**⚠️ والتفرّدُ `(user_id, endpoint_hash)`، لا `endpoint_hash` وحدَه.** ملفُّ متصفّحٍ واحدٍ له
اشتراكٌ واحدٌ لكلِّ أصل، فـ**وليٌّ وطفلُه يتشاركانِ هاتفاً** — الفئةُ التي بُنيَت لها علاقاتُ
الأوصياءِ في 013 — حسابانِ على عنوانٍ واحد. بتفرّدٍ عالميٍّ يسرق آخرُ من يشترك الصفَّ، ويتوقّف
الآخرُ عن تلقّي **كلِّ شيء** بما فيه `security_alert` الإلزاميُّ ورسائلُ الغياب، بلا خطأٍ
وبلا سطرٍ في أيِّ سجلّ. والنصفُ الثاني: مهاجمٌ مصادَقٌ يحمل عنوانَ غيرِه يستولي على صفِّه.
بالتفرّدِ المزدوجِ يتعايش الصفّان ويصل الإشعارانِ إلى الجهازِ المشترك — وهو الصحيحُ لجهازٍ
مشترَك، **والحمولةُ العامّةُ هي ما يجعله مقبولاً**: عنوانٌ قصيرٌ ورابطٌ، لا نصُّ الرسالة.

**والكتابةُ `upsert()` على المفتاحِ المزدوج**، لا `updateOrCreate` — فتلك `firstOrNew` +
`save`، أي فحصٌ ثمّ كتابة: لسانانِ يعيدانِ التسجيل (سلوكُ عاملِ الخدمةِ المعتاد) يصطدمانِ
فيكون ٥٠٠ على المسارِ السعيدِ للميزة.

**ولا `workspace_id`.** الدستورُ يوجب اختباراً في الـPR نفسِه بالاتّجاهَين. ⚠️ **والاتّجاهُ
الأوّلُ لا بابَ له**: لا مسارَ يقرأ فيه مدرّسٌ هذا الجدول، فاختبارٌ مكتوبٌ كاستعلامِ نموذجٍ
عارٍ لا يُثبِت شيئاً — يُكتب على أنّه **تأكيدُ غياب**: لا مسارَ ولا Resource ولا Filament
يمسّه، ويفشل إن أُضيف. والاتّجاهُ الثاني يحتاج **حسابَين على `endpoint_hash` واحد**، وإلّا
مرّ فوقَ العطلِ الذي يوجَد لالتقاطِه.

**والحذفُ بابانِ ولا ثالث**: إلغاءٌ من المتصفّحِ بـ`endpoint` مقيَّداً بـ`user_id` المصادَقِ
عليه، و`410 Gone` من خدمةِ الدفع. لا مسارَ يقبل uuidَ اشتراكٍ من الخارج.

### ما يُعدَّل

| الملفّ | التعديل |
|---|---|
| `Channels/WebPushChannel.php` **جديد** | سطرُ `->tag('notification.channels')` واحد |
| `Support/NotificationType.php` | **لا تغيير.** `Push` تبقى خارجَ `defaultChannels()` |
| `Actions/SavePushSubscription.php` **جديد** | `upsert()` + كتابةُ تفضيلاتِ أوّلِ اشتراك |
| `Support/NotificationsPersonalData.php` | `push_subscription` في الأربعِ مشيات |
| `database/seeders/DataCategorySeeder.php` | فئةُ `push_subscription` + هجرةٌ تنادي `run()` |
| `database/seeders/DataProcessorSeeder.php` | صفُّ `push` + هجرةُ ردم |

**⚠️ `Push` خارجَ `defaultChannels()`، والاشتراكُ هو ما يكتب التفضيل.** ثلاثةُ أسبابٍ
مقروءةٌ من ملفّاتٍ مسمّاة: `DispatchNotification.php:141-146` يكتب صفَّ التسليمِ من
`isEnabled()` وحدَه ويُطلق الوظيفة، و`canReach()` لا يُسأل إلّا في
`DeliverNotificationJob.php:102` — أي صفٌّ ووظيفةٌ لكلِّ إشعارٍ لكلِّ مستلِمٍ على المنصّة،
والأغلبيّةُ بلا اشتراك؛ و`WhatsAppDefaultsTest:68-69` يؤكّد `SecurityAlert->defaultChannels()`
بـ`toBe` على `[InApp, WhatsApp]` بالضبط فينكسر؛ و`PreferenceResolver.php:37-46` يدمج
الافتراضاتِ **فوقَ** التفضيلِ للنوعِ الإلزاميّ، فيصير دفعاً **لا يُطفأ**.

والمخرجُ في `PreferenceResolver.php:35`: `$chosen = $stored ?? $type->defaultChannels()` —
**التفضيلُ المخزَّنُ يستبدل الافتراضاتِ لا يُرشِّحُها**. فمن اشترك يملك صفوفاً تسمّي `push`،
ومن لم يشترك كلفتُه صفر. **وأوّلُ اشتراكٍ يكتب تلك الصفوفَ لفئاتِ `Sessions` و`Balance`
و`Account`** — مقروءةً من `NotificationCategory` القائمِ لا من قائمةٍ رابعةٍ باليد — وإلّا
لم يصلْ أحداً شيءٌ أبداً. سببُها في جملة: قيمتُها في وصولِها قبل الزيارةِ القادمة.

**ولا جدولَ تفضيلاتٍ جديد**: `notification_preferences` يحمل القناةَ منذ 003، و
`notification_deliveries.channel` يقبل `push` بنصِّ FR-005 — لا هجرةَ لأيٍّ منهما. وFR-035
يتحقّق بلا سطر: `canReach()` تُرجع `false` بلا اشتراك، والقناةُ تُسجَّل **متخطّاةً لا فاشلة**.

**⚠️ وصفُّ `data_processors` يفشل البناءَ بغيابِه**: `ProcessorAllowlistTest` يشتقّ قائمتَه
من وسمِ `notification.channels` ويفشل لأيِّ قناةٍ خارجيّةٍ بلا صفّ. وليس طقساً — FCM و
Mozilla و Apple أطرافٌ ثالثةٌ تستقبل مُعرِّفَ جهازٍ لكلِّ مستخدم.

---

## US3 — غرف المذاكرة

### `study_rooms`

```
id                    bigint PK
uuid                  uuid unique                         -- هو الدعوةُ القابلةُ للمشاركة
workspace_id          unsignedBigInteger index
host_user_id          unsignedBigInteger index
concept_id            unsignedBigInteger null index
difficulty            string(8) null
question_count        unsignedTinyInt                     -- المُسلَّمُ فعلاً، ≤ 30
max_participants      unsignedTinyInt                     -- ⚠️ سقفٌ، ≤ 30
duration_minutes      unsignedSmallInteger
starts_at             timestamp
ends_at               timestamp                           -- ⚠️ هذا هو الإغلاق، وحدَه
created_at index                                           -- للكنسِ بالعمر
timestamps

index   (workspace_id, ends_at)
index   (host_user_id, ends_at)
```

**«مغلقة» تعني `now() >= ends_at` — قراءةٌ لا وظيفة.**

> **⚠️ تصحيحٌ بعدَ التنفيذ (2026-08-31): `closed_at` حُذِف.** كان في هذا المخطَّطِ
> ونُفِّذَ ثمّ أُزيل. السبب: **لا متطلَّبَ في US3 يطلبُ إغلاقاً مبكّراً** — FR-016
> يقول «انتهاءُ الغرفةِ يعرضُ النتيجةَ ويغلقها»، وهو الساعة — فالعمودُ كان
> سيبقى بقارئٍ واحد (`state()`) و**صفرِ كُتّاب**. وهذا بعينِه العطلُ الذي يسجّلُه
> `CLAUDE.md`: «قيمةٌ بثلاثةِ قرّاءٍ وبلا كاتبٍ متطلَّبٌ ظنَّ الجميعُ أنّه
> مُنفَّذ». وتعليقٌ يقول «هذا بلا كاتب» لا يمنعُ القارئَ التاليَ من التفريعِ
> عليه. الإغلاقُ شرطٌ واحدٌ الآن، بلا جوابٍ ثانٍ يخالفُه.
>
> وإن لزمَ الإغلاقُ المبكّرُ يوماً فهو **عمودٌ ومسارٌ يختمُه وفحصُ مضيفٍ في تغييرٍ
> واحد**؛ إضافةُ العمودِ وحدَه لا تشتري شيئاً وتكلّفُ قارئاً. لا `->delay()`
ولا وظيفةُ إغلاق، وتسقط معها عائلةُ أعطالٍ كاملة: تأجيلٌ يُنفَّذ فوراً على `sync` فيُغلق
الغرفةَ داخلَ الطلبِ الذي أنشأها، و`Queue::fake()` العاري يبتلع المستمعينَ المطبورين. والغرفةُ
لا تملك ما يبرّر الوظيفةَ أصلاً: لا موفّرَ بثٍّ يُغلَق، ولا مقعدَ يُحرَّر، ولا أجرَ يُستحقّ.

**والعمودُ المشتقُّ أصدقُ من المكتوب**: غرفةٌ انتهت وعاملُها متوقّفٌ تبقى مفتوحةً بالعمودِ
ومغلقةً بالساعة. FR-016 شرطٌ واحدٌ في `JoinStudyRoom`.

**⚠️ لكنّ حالةً مشتقّةً لا تُطلق حدثاً** — انظر التحوّلاتِ أدناه.

**`max_participants` سقفٌ في الـAction، وليس تجميلاً**: الدعوةُ uuid قابلٌ للتحويل، وكلُّ
زميلٍ مؤهَّلٍ ينضمّ. بلا سقفٍ يكون البثُّ N صفّاً لـN مشتركاً لكلِّ إجابة، والإجاباتُ N×M.

**والمضيفُ يغادر** — الحالةُ الحدّيّة: الغرفةُ تستمرّ إلى `ends_at`. لا شيءَ فيها يحتاجه بعد
التجميد، وإغلاقُها بمغادرتِه يعاقب من بقي.

**⚠️ و`spec.md › Key Entities` يقول «وحالتها»** — والحالةُ هنا **مشتقّةٌ ومعروضة**، لا عمودٌ.
«حالة» في المواصفةِ تصف ما يراه المستخدم، لا ما تخزّنه قاعدةُ البيانات.

### `study_room_questions`

```
id                    bigint PK
workspace_id          unsignedBigInteger index
study_room_id         unsignedBigInteger
question_id           unsignedBigInteger
order                 unsignedTinyInt
points                unsignedSmallInteger default 1      -- ≤ 100، مفروضٌ في الـAction
snapshot              json
created_at · updated_at                                    -- ⚠️ تُمرَّر صراحةً

unique  (study_room_id, order)
unique  (study_room_id, question_id)
```

**تُكتَب مرّةً واحدةً وقتَ الإنشاء.** «يحلّون **المجموعةَ نفسَها** في وقتٍ واحد» بنصِّ القصّة.
واللقطةُ قاعدةُ `attempt_items` نفسُها: سؤالٌ عُدِّل بعد بدءِ الغرفةِ لا يغيّر ما رآه من فيها.

**⚠️ والكتابةُ الجُمْليّةُ تُمرِّر `created_at` و`updated_at` صراحةً.** `insert()` لا يُشغّل
النموذج، وأعمدةُ الطوابعِ تقبل `NULL` على المحرّكَين بلا خطأ — و`attempt_items` يُكنَس
بـ`created_at`، فشرطُ العمرِ فوقَ `NULL` لا يطابق شيئاً وتلك الصفوفُ **لا تنتهي صلاحيّتُها
أبداً**. السابقةُ `CreditLedger::writeEntry()` تمرّر `uuid` و`created_at` بيدِها للسببِ نفسِه.

**ولا `uuid`** — الصفُّ لا يُعنوَن من الخارج؛ الحمولةُ تحمل `order` والمحتوى.

**و`points` مسقوفٌ في الـAction**: `score` مجموعُ نقاطِ ثلاثينَ سؤالاً في `unsignedSmallInteger`
(٦٥٥٣٥)، فسؤالٌ بألفِ نقطةٍ يفيض — MySQL الصارمةُ ترفض وSQLite تقبل بصمت.

### `study_room_participants`

```
id                    bigint PK
uuid                  uuid unique
workspace_id          unsignedBigInteger index
study_room_id         unsignedBigInteger
user_id               unsignedBigInteger
attempt_id            unsignedBigInteger
score                 unsignedSmallInteger default 0
answered_count        unsignedTinyInt default 0
joined_at             timestamp
finished_at           timestamp null                      -- يختمه إكمالُ المجموعة
created_at index
timestamps

unique  (study_room_id, user_id)
index   (user_id, joined_at)                               -- ⚠️ قائمةُ الطالب
index   (study_room_id, score)
```

**⚠️ `index(user_id, joined_at)` ليس زائداً.** الطالبُ ليس عضواً في أيِّ مساحةِ عمل، فلا
فهرسَ على `study_rooms` يخدم «غرفي»؛ والفهرسُ المركّبُ `unique(study_room_id, user_id)`
يُقرأ من عمودِه الأوّلِ فقط. بدونه: مسحٌ كاملٌ للجدولِ لكلِّ فتحةِ صفحة.

**الاستئنافُ قراءةُ صفٍّ لا استعادةُ ذاكرة** (FR-015 · SC-007). من انقطع وعاد يقرأ
`answered_count` ويكمل، وإجاباتُه في `exam_answers` تحت `attempt_id` الخاصِّ به.

**محاولةُ تمرينٍ لكلِّ مشارك** — `is_practice = true` و`exam_id = null`، فيتحقّق FR-018
بالآليّةِ نفسِها التي تحمي الجلسةَ التكيّفية، لا بآليّةٍ ثانيةٍ تُنسى.

**⚠️ وصفُّ المشاركةِ يُطالَب أوّلاً، ثمّ تُنشأ المحاولةُ وعناصرُها، والكلُّ في معاملةٍ
واحدة.** الترتيبُ المعكوسُ يترك على الخاسرِ **محاولةً يتيمةً وN عنصراً** بلا مشاركٍ ولا كنسٍ
ولا شيءٍ يلاحظها.

**وعناصرُ المحاولةِ تُنسَخ من اللقطةِ المجمَّدة**، لا من السؤالِ الحيّ — فالورقةُ واحدةٌ
للجميعِ بحكمِ المصدر. ولهذا **لا يُعاد استعمالُ `PracticePaper::write()`**: يأخذ المجموعةَ
دفعةً واحدةً ويبني اللقطةَ من `QuestionSnapshot::of($question)` أي من السؤالِ الحيّ — عكسُ
المطلوب.

---

## التلعيبُ — صفّانِ في كتالوجٍ يُقرأ وقتَ التشغيل

| المفتاح | متى | `xp` | `coins` | `daily_cap` |
|---|---|---|---|---|
| `concept_mastered` | جلسةٌ بلغت الإتقانَ عند سقفِ فكرتِها | 25 | 10 | **3** |
| `study_room_finished` | مشاركٌ أجاب عن **كلِّ** أسئلةِ المجموعة | 15 | 5 | **2** |

**والسقفُ يجيب على «طالبٍ يتعمّد الخطأ»**: الجلسةُ التي يخسر فيها عمداً لا تُنتِج إتقاناً
أصلاً، والسقفُ يقطع التكرار. **ومرآتُه** `unique(student_user_id, concept_id)`: الفكرةُ
لا تُحصَد مرّتَين.

**ومصدرُ عدمِ الأثرِ هو صفُّ الإتقان**، لا صفُّ الجلسة — جلستانِ لهما `source_session_id`
مختلفان.

**⚠️ وهجرةُ الردمِ تُشحَن في التغيير نفسِه.** سقط المستودعُ في ذلك **ثلاثَ مرّات**:
`AwardPoints` يعود صامتاً على مفتاحٍ لا صفَّ له، وكلُّ اختبارٍ يمرّ لأنّ `tests/Pest.php`
يزرع الكتالوجَ قبل كلِّ حالة. `GamificationCatalogSeeder::seedMissing()` قائمٌ لهذا،
و`down()` **فارغةٌ**: حذفُ صفِّ فعلٍ يجعل `AwardPoints` يعود صامتاً.

---

## التحوّلات

**جلسةٌ تكيّفية**

```
running ──(إصاباتٌ متتاليةٌ = mastery_correct عند ceiling_difficulty)──► mastered
   │                                        ⇒ ConceptMastered · صفُّ concept_masteries
   └──(بلوغُ max_questions · إنهاءُ الطالب)──► ended
```

`mastered` و`ended` طرفيّتان؛ `AnswerAdaptiveStep` يعود مبكّراً عن أيٍّ منهما، على نمطِ
`CloseClassSession`. وكلاهما **يُفرِغ `running_key`** في المطالبةِ نفسِها التي تكتب الحالة.

**غرفةُ مذاكرة** — لا عمودَ حالةٍ إطلاقاً؛ الحالةُ دالّةٌ على الساعة:

```
now < starts_at            ⇒ pending    (يُقبَل الانضمام، لا تُقدَّم الأسئلة)
starts_at ≤ now < ends_at  ⇒ running    (يُقبَل الانضمامُ والإجابة)
now ≥ ends_at              ⇒ closed     (تُعرَض النتيجةُ، ويُرفَض الانضمام)
```

**⚠️ ولا حدثَ على هذا التحوّل.** حالةٌ مشتقّةٌ لا تُطلق شيئاً، وحدثٌ معلَّقٌ عليها هو
`ClassSessionStatus::Interrupted` في شكلٍ جديد: قرّاءٌ وكاتبٌ غيرُ موجود، فمفتاحُ
`study_room_finished` لا يُمنَح أبداً و`AwardPoints` يعود صامتاً. والإطلاقُ الكسولُ عند أوّلِ
قراءةٍ أسوأ: قراءةٌ تكتب، ومتصفّحانِ يفتحانِ اللوحةَ معاً يُطلقانِ مرّتَين.

```
مشارِك: joined ──(الإجابةُ التي أكملت المجموعة)──► finished
                  ⇒ finished_at مختوم · StudyRoomFinished
```

**ومن نفد وقتُه عند ٨ من ١٠ ليس «منتهياً»**، فلا نقطةَ له. «أنهى» تعني «أجاب عن كلِّ سؤالٍ
في المجموعة» — القاعدةُ تُكتب بدل أن تُترك. وعرضُ نتيجتِه النهائيةِ **قراءةٌ** تبقى كما هي.

---

## حقوقُ البيانات — خمسةُ جداولَ لا واحد

**⚠️ `PersonalDataContractCoverageTest` حارسٌ لكلِّ وحدة، وجدولٌ جديدٌ داخلَ وحدةٍ مسجَّلةٍ
غيرُ مرئيٍّ له.** هذا مكتوبٌ في `CLAUDE.md` بعد أن شحن `announcements.author_user_id` بلا
تغطية: «nothing will tell you».

| الجدول | الفئة | العمرُ | السلوك | المشيات |
|---|---|---|---|---|
| `adaptive_sessions` | `adaptive_session` | 1095 | Delete | `AssessmentsPersonalData` |
| `concept_masteries` | `concept_mastery` | 1825 | Delete | `AssessmentsPersonalData` |
| `study_room_participants` | `study_room_participation` | 1095 | Delete | `AssessmentsPersonalData` |
| `study_rooms` | `study_room` | 1095 | Delete | `AssessmentsPersonalData` |
| `push_subscriptions` | `push_subscription` | 730 | Delete | `NotificationsPersonalData` |

**والأربعُ مشياتٍ لكلِّ واحد** — `describe()` · `export()` · `erase()` · `expire()` — لا صفَّ
كتالوجٍ وحدَه. و**كلٌّ يحتاج فهرسَ `created_at`**: مُسنَدُه `2026_08_21_001000_add_created_at_indexes_for_retention.php`
الذي أضافه لكلِّ جدولٍ يمشي عليه الكنس.

**والهجرةُ تنادي `DataCategorySeeder::run()`** — `firstOrCreate` سلفاً فلا حاجةَ إلى
`seedMissing()`، وهو ما توثّقه `Store/.../backfill_store_data_categories.php`.

---

## الفهارسُ والأعدادُ والحدود

- **لا مرشَّحَ لـERROR 1690 هنا**: لا شيءَ يطرح من عمودٍ غيرِ مُوقَّع. لكنّ **مرشَّحَي فيضٍ**
  ترفضهما MySQL الصارمةُ وتقبلهما SQLite بصمتٍ: `score` (٣٠ سؤالاً × `points`) و
  `threshold_correct` من صفٍّ يعدّله مشغّل. كلاهما مسقوفٌ في الـAction، لا في عرضِ العمود.
- **لا `whereDate()` في أيِّ استعلامٍ هنا.** `ends_at` يُقارَن بـ`now()` مباشرةً ليعمل الفهرسُ
  `(workspace_id, ends_at)` — الدالّةُ حولَ العمودِ تُلغي فهرسَه، وهو ما كلّف
  `FreezePeriod::covering()` إصلاحاً.
- `index(study_room_id, score)` يجعل اللوحةَ استعلاماً واحداً مرتَّباً. **والكلفةُ الحقيقيّةُ
  للوحةِ هي الانضمامُ إلى `users` للاسم** — وذلك ما يجب أن يُحمَّل مسبقاً بـ`first_name` و
  `last_name`، لا بـ`name`.
- **⚠️ `users` لا يحمل عمود `name`** — هو accessor فوق `first_name`/`last_name`. فتحميلٌ
  مقيَّدٌ بـ`->with('user:id,uuid,name')` يُرجع **اسماً فارغاً**، وقد شحن ذلك في ستّةِ مواضعَ
  قبلَ اليوم. واللوحةُ أسوأُ من الستّة: تُدفَع إلى كلِّ مشترك، فلا شاشةَ واحدةَ يلاحظ فيها
  أحدٌ «» قبل أن يراها الجميع. **والتوكيدُ في الاختبارِ اسمٌ غيرُ فارغ**، لا عددُ صفوف.
- كلُّ فهرسٍ فريدٍ هنا يحمل أعمدةً `NOT NULL` — عدا `running_key`، وهو **مقصودٌ** بالضبط
  لأنّ `NULL` لا تصطدم بـ`NULL`: هذا ما يجعل مطالبةَ الجلسةِ الجاريةِ ممكنةً على قاعدةٍ بلا
  فهارسَ جزئيّة.
