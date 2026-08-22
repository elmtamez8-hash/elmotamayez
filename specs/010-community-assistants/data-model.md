# Data Model — المجتمع والمساعدون والتقييم المتبادل (010)

**الإصدارُ الثاني** بعد مراجعةِ الوكلاء. عشرةُ كياناتٍ جديدةٍ وثلاثةُ توسيعات. **الطبقةُ
مُعلَنةٌ قبل كتابةِ الهجرة**، كما يوجب الدستورُ **v1.2.0** — أربعُ طبقاتٍ لا ثلاث.

---

## الخريطة

| الكيان | الطبقة | لماذا |
|---|---|---|
| `assistant_assignments` | مساحة العمل | من في فريقِ المدرّس. **لا عمودَ صلاحيّات** — ق-١ |
| `assistant_scopes` | مساحة العمل | تقييدُ المساعدِ بكورساتٍ (`FR-004`) |
| `conversations` | **جسر** | `workspace_id` للسياقِ وإشارةٌ إلى الطالبِ العامّ |
| `messages` | مساحة العمل | داخلَ محادثةٍ داخلَ مساحةِ عمل |
| `conversation_participants` | مساحة العمل | من يقرأ ومتى قرأ |
| `moderation_actions` | مساحة العمل | **سجلُّ تدقيقٍ مُضاف** — لا يُحذف منه صفّ |
| `blocked_terms` | مساحة العمل | قائمةُ المدرّس (`FR-020`) |
| `periodic_reviews` | **جسر** | تقييمُ المدرّسِ لطالب |
| `announcements` | مساحة العمل | الدستورُ يدرجها بالاسم |
| `grading_schemes` | مساحة العمل | أوزانُ المدرّس |
| `report_cards` | ⚠️ **منصّيّ (أ)** | الدستورُ يدرجه بالاسم. طالبٌ واحدٌ وكشفٌ واحد |
| `report_card_segments` | **جسر** | مساهمةُ مدرّسٍ واحد |
| `reviews` *(توسيع)* | مساحة العمل | ثلاثةُ محاورَ + فترة — يبقى في `Marketplace` |
| `notifications` *(توسيع)* | جسر | `source_type` + `source_id` مفهرَسان |

⚠️ **`report_cards` هو الكيانُ المنصّيُّ الوحيدُ (أ) هنا**، فيلزمه اختبارُ `NFR-001ب`
بالاتّجاهَين في الـPR نفسِه: مدرّسٌ **لا** يرى كشفَ طالبٍ غيرِ مسجَّلٍ عنده، والطالبُ يرى
كشفَه **الواحد** عبرَ كلِّ مدرّسيه.

---

## الكيانات

### `assistant_assignments`

- `workspace_id` · `assistant_user_id` · `invited_by_user_id` · `revoked_at` (`nullable`)
- `unique(workspace_id, assistant_user_id)` · `index(assistant_user_id)`

⚠️ **لا عمودَ `abilities`.** البنودُ صلاحيّاتُ spatie تُمنَح من شاشةِ الأدوارِ القائمة —
`RolePermissionMatrix` يقول ذلك بنصِّه («المصفوفةُ تزرع افتراضاً، وشاشةُ الأدوارِ تدع المالكَ
يضع أيّاً منها على دورِ مساعدٍ مخصَّص»). الصفُّ هنا يجيب سؤالَين لا يجيبهما الدور: **من هو
مساعدٌ في هذه المساحة** (‏مفتاحُ الحائطِ الماليّ، ق-٢) و**على أيِّ كورسات**.

⚠️ **و`revoked_at` بدل الحذف** (`FR-009`)، ويُكتب بتحديثٍ شرطيّ `WHERE revoked_at IS NULL`
فلا يُنفَّذ أثرُ الإزالةِ مرّتَين.

⚠️ **والصفُّ لا يُنشئ عضوية.** `workspace_members` لا يكتبه إلا `AcceptInvitation` و
`CreateWorkspace`؛ فالتعيينُ يركب الدعوةَ المشحونةَ ويُنشَأ عند القبول.

### `assistant_scopes`

- `assistant_assignment_id` · `course_id` · `unique(assistant_assignment_id, course_id)`

**غيابُ الصفوفِ = كلُّ الكورسات.**

⚠️ **والمحادثةُ الخاصّةُ لا تحمل كورساً**، فالنطاقُ عليها يُقيَّم بتقاطعِ **التسجيل**:
`AssistantScopeDirectory` يسأل `EnrollmentDirectory` عن كورساتِ الطالبِ في هذه المساحةِ
ويقاطعها بالنطاق. بلا ذلك يقرأ مساعدٌ مقيَّدٌ بكورسٍ واحدٍ **كلَّ** محادثةٍ خاصّةٍ في المساحة.

### `conversations`

- `workspace_id` · `kind` (`private` · `session` · `lesson`)
- `student_user_id` (`nullable`) · `class_session_id` / `lesson_id` (`nullable`)
- `last_message_id` (`nullable`)
- `unique(workspace_id, student_user_id)` — **عمودان عاديّان، بلا عمودٍ محسوب**: `NULL` لا
  يصطدم بـ`NULL`، فالمحادثاتُ العامّةُ تتعايش بلا حدّ، والخاصّةُ واحدةٌ لكلِّ طالب
- `index(workspace_id, last_message_id)`

⚠️ **و`POST /conversations` سباقٌ**: جهازان يفتحان معاً، كلاهما لا يجد شيئاً، كلاهما يُدرج.
المسارُ الخاسرُ **مُعلَن**: يُلتقَط خرقُ الفريدِ ويُرجَع الفائز — نمطُ `BookSeat`.

⚠️ **و`last_message_id` يُكتب بشرط** `WHERE last_message_id IS NULL OR last_message_id < ?`:
رسالتان في اللحظةِ نفسِها قد تكتب الأخيرةُ منهما المعرّفَ **الأصغر**، فتُرتَّب المحادثةُ
برسالةٍ ليست آخرَها إلى الأبد.

### `messages`

- `workspace_id` · `conversation_id` · `sender_user_id`
- `body` (`text`) · `hidden_at` (`nullable`) · `is_helpful` (`bool`)
- `index(workspace_id, conversation_id, id)`

⚠️ **`hidden_at` لا `deleted_at`.** الاسمُ الثاني يجتذب `SoftDeletes`، ونطاقُه العامُّ يضيف
`deleted_at IS NULL` لكلِّ قراءة — فيمحو الأرشيفَ الذي يعد به `FR-015` **ويقصّر كلَّ صفحةِ
خمسين** بصمت.

⚠️ **والفهرسُ ثلاثيٌّ يبدأ بـ`workspace_id`**، لأن النطاقَ العامَّ يضيف ذلك الشرطَ لكلِّ
استعلامٍ من مدرّسٍ أو مساعد؛ فهرسٌ `(conversation_id, id)` وحدَه لا يُستعمَل كاملاً.

⚠️ **و`workspace_id` يُنسَخ من المحادثةِ صراحةً، لا من السياق**: الطالبُ عضوٌ في لا مساحةَ
عمل، فتعبئةُ `BelongsToWorkspace` التلقائيّةُ تكتب `null` أو — أسوأُ — `last_workspace_id`.

### `conversation_participants`

- `conversation_id` · `user_id` · `last_read_message_id` (`nullable`)
- `unique(conversation_id, user_id)` · ⚠️ `index(user_id, conversation_id)`

⚠️ **الفهرسُ الثاني هو الذي يجيب سؤالَ الشاشة** («في أيِّ محادثاتٍ أنا؟»). الفريدُ عمودُه
القائدُ `conversation_id` فلا يخدمه، ولا يعوّضه فهرسُ `conversations` لأن الطالبَ لا يُصدِر
شرطَ `workspace_id` أصلاً.

**و`muted_at` أُسقط**: كتمٌ ثانٍ بجانبِ `notification_preferences` المنصّيّةِ بلا قارئ.

### `moderation_actions`

- `workspace_id` · `actor_user_id` · `subject_type` + `subject_id` · `verdict` · `reason`
- `expires_at` (`nullable`) · `index(workspace_id, subject_type, subject_id, verdict)`

⚠️ **مُضافٌ فقط، ورفعُ الحظرِ صفٌّ جديدٌ لا حذف.** الجدولُ **هو** سجلُّ `FR-021`؛ حذفُ الصفِّ
يمحو من حظر ولماذا. والقراءةُ «هل هو محظورٌ الآن؟» أحدثُ صفٍّ يفوز.

⚠️ **والفهرسُ ليس `(workspace_id, created_at)`**: الشرطُ لا يذكر `created_at`، فيُستعمَل عمودٌ
قائدٌ واحدٌ ويُمسَح تاريخُ الإشرافِ كلُّه عند **كلِّ إرسالِ رسالة**.

⚠️ **و`expires_at` يُقرأ بشرطٍ مُجمَّع**: `(expires_at IS NULL OR expires_at > now())`. بلا
تجميعٍ، `NULL > now()` هو `NULL`، فحظرٌ دائمٌ ينتهي فوراً — عائلةُ عطلِ التعليقِ القانونيّ.

**ونطاقُ الحظرِ مُعلَن**: مساحةُ العملِ كلُّها. فمحظورٌ لا يكتب في شاتِ حصّةٍ ولا في محادثةٍ
خاصّةٍ ولا يفتح واحدةً جديدة، وقارئٌ واحدٌ يُسأل من `PostMessage` و`StartConversation` معاً.

### `blocked_terms`

- `workspace_id` · `term` · `policy` (`block` · `mask` · `review`)
- `unique(workspace_id, term)`

⚠️ **تُزرَع بمستمعٍ ثانٍ على `WorkspaceCreated` مُسجَّلٍ في `CommunityServiceProvider`** — لا
داخلَ `SeedDefaultRoles`، فذاك مستمعُ `Tenancy` وكتابتُه في جدولِ `Community` خرقُ المبدأ
الثالث. **ومعها هجرةُ تعبئةٍ بـ`chunkById`** لكلِّ مساحةٍ قائمة، وإلا فالمرشِّحُ خاملٌ لكلِّ
من يوجد اليومَ — ومرشِّحٌ يسمح بكلِّ شيءٍ بصمتٍ هو أسوأُ أشكالِ الغياب.

### `periodic_reviews`

- `workspace_id` · `student_user_id` · `teacher_user_id` · `period_start` · `period_end`
- `commitment` · `participation` · `homework` · `improvement` (١–٥) · `note`
- `published_at` (`nullable`) — يُكتب بتحديثٍ شرطيٍّ فيصل الإشعارُ مرّةً
- `unique(workspace_id, student_user_id, period_start, period_end)`

### `announcements`

- `workspace_id` · `author_user_id` · `scope` (`all` · `course` · `session`) · `scope_id`
- `body` · `is_urgent` · `published_at` · `hidden_at`

⚠️ **`published_at` يُطالَب به بتحديثٍ شرطيّ** `WHERE published_at IS NULL`، والحدثُ يُطلقه
المطالِبُ وحدَه — وإلا فضغطتان تُفرّعان الإعلانَ مرّتَين على ثلاثِمئةِ طالب.

**ولا مرفقات**: `FR-042` يذكرها ولا عمودَ ولا نقطةَ رفعٍ لها. تعديلٌ مُعلَن — تُضاف عبر
`RequestUploadTicket` القائمِ متى طُلبت.

### `grading_schemes`

- `workspace_id` · `course_id` (`nullable`) · `period_start` · `period_end`
- `weights` (`json`) · `unique(workspace_id, course_id, period_start, period_end)`

⚠️ **الفترةُ تاريخان لا نصّ**: الإصدارُ الأوّلُ كتب `period_label` نصّاً بينما الكشفُ يحمل
تاريخَين، فلم يكن لـ`BuildReportCard` طريقٌ مُعرَّفٌ لاختيارِ التركيبة.

### `report_cards` ⚠️ منصّيّ

- `student_user_id` · `period_start` · `period_end` · `generated_at`
- `overall_pct` · `improvement_index` · `published_at`
- `media` عبر medialibrary — **لا عمودَ `file_path`**
- `unique(student_user_id, period_start, period_end)` · **لا `workspace_id`**

⚠️ **الملفُّ يمرُّ بالآليّةِ القائمة لا بعمودٍ عارٍ.** عمودٌ خامٌّ يتجاوز حلَّ المزوّدِ وكنسَ
الاحتفاظِ وأرضيّةَ `FR-036` من ٠١٣ — ملفُّ PDF يحمل درجاتِ قاصرٍ ولا يحذفه شيءٌ أبداً.

⚠️ **ويُبنى بوظيفةٍ مجدولةٍ منصّيّة** (ق-٤). الإدراجُ `insertOrIgnore` بـ`uuid` و`created_at`
**صراحةً** ثمّ قراءةٌ راجعةٌ ترمي عند الصفر — نمطُ `CreditLedger::writeEntry()`، لأن
`insertOrIgnore` لا يُقلع النموذجَ فلا يعمل `HasUuid`، وMySQL تخفّض الخرقَ إلى تحذيرٍ وتخزّن
`''` فيصطدم كلُّ كشفٍ لاحقٍ على `unique(uuid)` ويُقرأ «مسجَّلٌ سلفاً».

⚠️ **والمجاميعُ تُحسَب داخلَ مطالبةِ النشرِ وحدَها**، لا عند كتابةِ كلِّ مقطع: حسابٌ متداخلٌ
بين مقطعَين يُنتج مجموعاً لا يطابق أيَّ مجموعةِ مقاطع، ونهائياً.

### `report_card_segments`

- `report_card_id` · `workspace_id` · `teacher_user_id` · `student_user_id`
- `components` (`json`) · `attendance_pct` · `segment_pct` · `index(report_card_id)`

⚠️ **`student_user_id` مكرَّرٌ هنا عمداً**: الدستورُ يعرّف الجسرَ بأنه «يحمل `workspace_id`
للسياقِ **ويشير إلى المستخدمِ العامّ**»، وبدونه ليس جسراً بل جدولَ تفصيلٍ منصّيٍّ بلا مالك.

⚠️ **و`components` لقطة** (`FR-052`): كشفٌ نُشر لا يُعاد حسابُه حين تتغيّر الأوزانُ غداً.
ومكوّنٌ بلا بياناتٍ **يُستبعَد وتُعاد الموازنة** — لا يُحتسَب صفراً (`FR-053`).

### `reviews` — توسيع (‏في `Marketplace`)

يكسب `punctuality` · `clarity` · `engagement` (١–٥، **قابلةً للعدم**) و`period_start`.
`rating` يبقى متوسّطَ المحاورِ **غيرِ المعدومة** المكتوب، فلا تُلمَس `average_rating` ولا
`TrustScoreCalculator`.

⚠️ **قابلةٌ للعدمِ لسببٍ يختلف بين المحرّكَين**: `NOT NULL` بلا افتراضٍ **يرفضه SQLite** على
جدولٍ عامرٍ ويقبله MySQL فيملأ **صفراً** — خارجَ المدى ١–٥ — فيصير متوسّطُ كلِّ تقييمٍ سابقٍ
صفراً ويتدفّق إلى درجةِ الثقة، وهو بالضبط ما يقيسه `SC-011`.

⚠️ **والفريدُ يتغيّر**: `unique(teacher_profile_id, student_id)` المشحونُ يعني صفّاً واحداً
للأبد، فـ`FR-032` («واحدٌ لكلِّ فترة») مُرضىً مجّاناً ولا يمكن إدخالُ فترةٍ ثانيةٍ إطلاقاً.
يصير `unique(teacher_profile_id, student_id, period_start)` — **بتعبئةٍ قبلَ الفهرسة**، ترتيبُ
٠١٦ نفسُه (‏التكثيفُ قبلَ الفهرس، وإلا فشل النشرُ على بياناتٍ حيّة).

### `notifications` — توسيع (‏في `Notifications`)

يكسب `source_type` (`string 64`) و`source_id` (`unsignedBigInteger`) قابلَين للعدمِ
و`index(source_type, source_id, read_at)`.

⚠️ **`FR-046` يمنع تخزينَ العدد، لا مفتاحاً قابلاً للفهرسة.** بدونه يصير العدُّ
`JSON_EXTRACT(payload, '$.announcement_uuid')` — دالّةٌ تلتهم أيَّ فهرسٍ، على أسرعِ جداولِ
المنصّةِ نموّاً، **في قائمةٍ** فمسحٌ كاملٌ لكلِّ إعلان. وSQLite على خمسةِ صفوفٍ خضراءُ فوراً.

---

## الأحداث

| الحدث | يُطلقه | يستهلكه |
|---|---|---|
| `MessagePosted` | `PostMessage` | البثُّ (‏معرّفاً) · إشعارُ غيرِ المتّصل |
| `HelpfulAnswerMarked` | `MarkHelpful` | التلعيب — ⚠️ ويحمل `source_type`/`source_id` ليعمل مفتاحُ التعامدِ في `AwardPoints`؛ ويلزمه صفٌّ في `GamificationCatalogSeeder` وإلا فالمنحُ لا شيءَ بصمت |
| `AnnouncementPublished` | `PublishAnnouncement` | `FanOutAnnouncementJob` |
| `PeriodicReviewPublished` | `PublishPeriodicReview` | إشعارُ الطالبِ **ووليِّ أمره** |
| `ReviewSubmitted` *(قائم)* | `SubmitReview` في `Marketplace` | `QueueTrustScoreRecalculation` *(قائم)* |

⚠️ **ولا حدثَ `TeacherRated`**: `ReviewSubmitted` مشحونٌ ومربوطٌ؛ حدثٌ ثانٍ لنفسِ الواقعةِ
إمّا إعادةُ حسابٍ مرّتَين أو مستمعٌ ميّت.

---

## الفهارسُ المُعلَنة (`NFR-011`)

| الجدول | الفهرس | السؤالُ الذي يخدمه |
|---|---|---|
| `messages` | `(workspace_id, conversation_id, id)` | صفحةٌ بمفتاحٍ، والنطاقُ يضيف العمودَ الأوّل |
| `conversation_participants` | `(user_id, conversation_id)` | «في أيِّ محادثاتٍ أنا؟» |
| `conversations` | `(workspace_id, last_message_id)` | ترتيبُ قائمةِ المدرّس |
| `moderation_actions` | `(workspace_id, subject_type, subject_id, verdict)` | «هل هو محظور؟» عند كلِّ إرسال |
| `assistant_assignments` | `(assistant_user_id)` | الحائطُ الماليُّ عند **كلِّ** فحصِ صلاحيّة |
| `assistant_scopes` | `(assistant_assignment_id, course_id)` | التقاطعُ مع التسجيل |
| `periodic_reviews` | الفريدُ الرباعيّ | القراءةُ والكتابة |
| `report_card_segments` | `(report_card_id)` | تجميعُ الكشف |
| `grading_schemes` | الفريدُ الرباعيّ | اختيارُ التركيبةِ للفترة |
| `announcements` | `(workspace_id, published_at)` | قائمةُ الناشر |
| `blocked_terms` | `(workspace_id, term)` | الترشيحُ عند الإرسال |
| `notifications` *(توسيع)* | `(source_type, source_id, read_at)` | عدّادا `FR-046` |
