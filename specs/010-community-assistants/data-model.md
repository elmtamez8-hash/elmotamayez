# Data Model — المجتمع والمساعدون والتقييم المتبادل (010)

أحدَ عشرَ كياناً جديداً وتوسيعٌ واحد. **الطبقةُ مُعلَنةٌ لكلِّ واحدٍ قبل كتابةِ هجرته**،
كما يوجب الدستورُ (§I) — وكيانٌ بلا تصنيفٍ معلَنٍ يُرفَض في المراجعة.

---

## الخريطة

| الكيان | الطبقة | لماذا |
|---|---|---|
| `assistant_assignments` | **مساحة العمل** | ما ينتجه المدرّس: من في فريقه وبأيّ بنودٍ وعلى أيّ كورسات |
| `assistant_scopes` | **مساحة العمل** | تقييدُ المساعدِ بكورساتٍ بعينها (`FR-004`) |
| `conversations` | **جسر** | تحمل `workspace_id` للسياقِ وتشير إلى الطالبِ العامّ — شكلُ `Enrollment` نفسُه |
| `messages` | **مساحة العمل** | تعيش داخلَ محادثةٍ داخلَ مساحةِ عمل |
| `conversation_participants` | **مساحة العمل** | من يقرأ ومتى قرأ آخرَ مرّة |
| `moderation_actions` | **مساحة العمل** | فعلُ المدرّسِ أو من فوَّض |
| `blocked_terms` | **مساحة العمل** | قائمةُ المدرّسِ يحرّرها (`FR-020` · research §R10) |
| `periodic_reviews` | **جسر** | تقييمُ المدرّسِ لطالبٍ: سياقُ المدرّسِ + الطالبُ العامّ |
| `announcements` | **مساحة العمل** | الدستورُ يدرج «الإعلانات» في هذه الخانةِ بالاسم |
| `grading_schemes` | **مساحة العمل** | أوزانُ المدرّسِ لكورسِه |
| `report_cards` | ⚠️ **مملوكٌ للمنصّة (أ)** | الدستورُ يدرج «كشف التقديرات» في هذه الخانةِ بالاسم. طالبٌ واحدٌ وكشفٌ واحدٌ عبرَ كلِّ مدرّسيه |
| `report_card_segments` | **جسر** | مساهمةُ مدرّسٍ واحدٍ داخلَ كشفٍ مملوكٍ للمنصّة |
| `reviews` *(توسيع)* | مساحة العمل | قائمٌ من ٠٠١، يكسب ثلاثةَ محاور |

⚠️ **`report_cards` هو الكيانُ الوحيدُ من الصنف (أ) هنا**، فيلزمه — في الـPR نفسِه —
اختبارُ `NFR-001ب` بالاتّجاهَين: مدرّسٌ **لا** يرى كشفَ طالبٍ غيرِ مسجَّلٍ عنده، والطالبُ يرى
كشفَه **الواحد** عبرَ كلِّ مدرّسيه بلا تكرار. و**يُمنع** أن يحمل `BelongsToWorkspace`:
لو حمله لصار لكلِّ مدرّسٍ كشفٌ منفصلٌ لنفسِ الطفل — العطلُ الصامتُ الذي يظهر متأخّراً بعد
تراكمِ البيانات، وهو المرآةُ التي يمسكها `PlatformOwnershipTest` من الجهتَين.

---

## الكيانات

### `assistant_assignments`

مساعدٌ واحدٌ في مساحةِ عملٍ واحدة.

- `workspace_id` · `assistant_user_id` · `invited_by_user_id`
- `abilities` (`json`) — البنودُ الممنوحةُ من `AssistantAbility`
- `revoked_at` (`nullable`)
- `unique(workspace_id, assistant_user_id)` · `index(assistant_user_id)`

**البنودُ خمسةٌ** (`AssistantAbility`): `grade_essays` · `reply_chat` · `record_attendance` ·
`upload_content` · `view_student_balance`.

⚠️ **الخامسُ قرارٌ يعدّل `FR-003` صراحةً** (‏research §R4، بقرارِ المستخدم 2026-08-22): رصيدُ
الطالبِ **بالأرصدةِ لا بالمال**، فهو خارجَ المنعِ المطلق — ويخرج من مجموعةِ الدورِ الافتراضيةِ
ليصير بنداً يمنحه المدرّس. أمّا كشفُ التسويةِ والسعرُ والوحدةُ والدفعةُ والإيصالُ والصرفُ
فمنعُها مطلقٌ لا بندَ له ولا إعدادَ يرفعه، والحارسُ على **النموذج** لا على المصفوفةِ المزروعة.

⚠️ **و`revoked_at` بدل الحذف**: `FR-009` يقول إن عملَ المساعدِ قبلَ إزالته **يُمنع** أن يُفقد
أو يُعاد نسبتُه لغيره. صفٌّ محذوفٌ يترك تصحيحاتٍ ودرجاتٍ تشير إلى معرّفٍ لا يُحلّ.

### `assistant_scopes`

- `assistant_assignment_id` · `course_id`
- `unique(assistant_assignment_id, course_id)`

**غيابُ الصفوفِ يعني كلَّ الكورسات.** جدولٌ فارغٌ = بلا تقييد، وهو أبسطُ من رايةٍ ثانيةٍ
`is_scoped` تنحرف عن محتوى الجدول.

### `conversations`

- `workspace_id` · `kind` (`private` · `session` · `lesson`)
- `student_user_id` (`nullable` — للخاصّةِ فقط)
- `class_session_id` / `lesson_id` (`nullable` — للعامّة)
- `last_message_id` (`nullable`) — لترتيبِ قائمةِ المحادثاتِ بلا تجميعٍ لكلِّ صفّ
- `unique(workspace_id, student_user_id)` حيث `kind = private` — **عمودٌ فريدٌ محسوبٌ**
  (`private_key`) لا فهرسٌ جزئيّ: MySQL لا تملك فهارسَ جزئيّة، و`NULL` لا يصطدم بـ`NULL`
  فتتعايش العامّةُ كلُّها بحرّيّة. نفسُ حيلةِ `captured_order_id` في ٠٠٧.

### `messages`

- `workspace_id` · `conversation_id` · `sender_user_id`
- `body` (`text`) · `deleted_at` (`nullable`) · `is_helpful` (`bool`)
- `index(conversation_id, id)` — ⚠️ **بالمعرّفِ لا بالوقت** (research §R7)

⚠️ **الحذفُ إخفاءٌ لا إزالة** (`FR-015`): الصفُّ يبقى لسجلِّ الإشراف. وقارئٌ عاديٌّ يرى
«حُذفت»، والمشرفُ يرى النصّ.

### `conversation_participants`

- `conversation_id` · `user_id` · `last_read_message_id` (`nullable`) · `muted_at`
- `unique(conversation_id, user_id)`

### `moderation_actions`

- `workspace_id` · `actor_user_id` · `subject_type` + `subject_id` · `action` · `reason`
- `expires_at` (`nullable`) — للحظرِ المؤقّت · `index(workspace_id, created_at)`

**السببُ إلزاميّ** (`FR-021`): إجراءٌ بلا سببٍ لا يُراجَع ولا يُنقَض.

### `blocked_terms`

- `workspace_id` · `term` · `policy` (`block` · `mask` · `review`)
- `unique(workspace_id, term)`

### `periodic_reviews`

- `workspace_id` · `student_user_id` · `teacher_user_id` · `period_start` · `period_end`
- `commitment` · `participation` · `homework` · `improvement` (‏كلٌّ ١–٥) · `note`
- `published_at` (`nullable`)
- `unique(workspace_id, student_user_id, period_start)`

### `announcements`

- `workspace_id` · `author_user_id` · `scope` (`all` · `course` · `session`)
- `scope_id` (`nullable`) · `body` · `is_urgent` · `published_at` · `deleted_at`

**عددُ المستلمين والقارئين لا عمودَ لهما** (`FR-046`): يُشتقّان من `notifications` بمفتاحِ
الإعلان. عمودٌ مخزَّنٌ ينحرف عن الجدولِ الذي يعدّه أوّلَ مرّةٍ يُقرأ إشعارٌ بعد الحساب.

### `grading_schemes`

- `workspace_id` · `course_id` (`nullable` — الافتراضُ للمساحة) · `period_label`
- `weights` (`json`: `exams` · `homework` · `attendance` · `participation`)

**المجموعُ ١٠٠ يُفرَض في الفعل** (`FR-050`)، لا في التحقّقِ وحدَه — الفعلُ هو المدخلُ الذي
تشترك فيه الواجهةُ ولوحةُ Filament والبذور.

### `report_cards` ⚠️ مملوكٌ للمنصّة

- `student_user_id` · `period_start` · `period_end` · `generated_at`
- `overall_pct` · `improvement_index` · `file_path` (`nullable`) · `published_at`
- `unique(student_user_id, period_start, period_end)`
- **لا `workspace_id`**

### `report_card_segments`

- `report_card_id` · `workspace_id` · `teacher_user_id`
- `components` (`json`: لكلِّ مكوّنٍ وزنُه ودرجتُه ومساهمتُه)
- `attendance_pct` · `segment_pct`

⚠️ **`components` لقطةٌ مخزَّنة** (`FR-052` · research §R8): الكشفُ المنشورُ لا يُعاد حسابُه
حين يغيّر المدرّسُ أوزانَه غداً. ومكوّنٌ بلا بياناتٍ في الفترةِ **يُستبعَد** من المجموعةِ
وتُعاد موازنةُ الباقي بنسبها — **ولا يُحتسَب صفراً** (`FR-053`).

### `reviews` — توسيع

يكسب `punctuality` · `clarity` · `engagement` (‏كلٌّ ١–٥)، ويبقى `rating` **متوسّطَها المُشتقّ
المكتوب**. `TrustScoreCalculator` لا يُلمَس (research §R5).

---

## الأحداث

| الحدث | يُطلقه | يستهلكه |
|---|---|---|
| `MessagePosted` | `PostMessage` | البثُّ اللحظيُّ (‏معرّفاً لا حمولةً) · الإشعارُ لغيرِ المتّصل |
| `HelpfulAnswerMarked` | `MarkHelpful` | **التلعيب (٠٠٩)** — ويلزمه صفٌّ في `GamificationCatalogSeeder`، وإلا فالمنحُ لا شيءَ بصمت |
| `TeacherRated` | `SubmitTeacherRating` | درجةُ الثقة (٠٠١) |
| `PeriodicReviewPublished` | `PublishPeriodicReview` | إشعارُ الطالبِ **ووليِّ أمره** |
| `AnnouncementPublished` | `PublishAnnouncement` | `FanOutAnnouncementJob` |
| `AssistantAbilitiesChanged` | `GrantAbilities` · `RemoveAssistant` | `RevokeAssistantSessions` (`FR-007`) |

---

## الفهارسُ المُعلَنة (`NFR-011`)

- `messages(conversation_id, id)` — الصفحةُ الأحدثُ أوّلاً بمفتاحٍ لا بإزاحة
- `assistant_assignments(assistant_user_id)` — «أين أعمل؟» على كلِّ طلبٍ للمساعد
- `assistant_scopes(assistant_assignment_id, course_id)`
- `conversations(workspace_id, last_message_id)` — قائمةُ المحادثاتِ مرتّبة
- `periodic_reviews(workspace_id, student_user_id, period_start)`
- `report_card_segments(report_card_id)` · `report_cards(student_user_id, period_start)`
- `moderation_actions(workspace_id, created_at)`
