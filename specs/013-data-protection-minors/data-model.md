# Phase 1 — Data Model: حماية بيانات القُصّر وحقوق البيانات

**السبيك**: [spec.md](./spec.md) · **الخطة**: [plan.md](./plan.md) · **البحث**: [research.md](./research.md)

كلُّ كيانٍ هنا يحمل **طبقةَ ملكيته** — قرارٌ مُلزِمٌ بالدستور v1.2.0 §I يُوثَّق قبل كتابة
الهجرة، وكيانٌ بلا تصنيفٍ معلَنٍ يُرفَض في المراجعة.

---

## ما لا يُبنى

| ما يتوقّعه قارئُ السبيك | الحقيقة |
|---|---|
| `processing_consents` | **قائمٌ باسم `terms_consents`** منذ 006، و`ConsentDocument::DataProcessing` مكتوبةٌ فيه. لا جدول، لا نموذج، لا هجرة — راجع [research.md §R1](./research.md) |
| `recording_consents` | **مشطوبٌ في `Q4`**: الظهورُ صنفٌ لازمٌ داخل الموافقة الواحدة |
| `policy_versions` | صفٌّ في `platform_settings` (`consents.versions.data_processing`)، والنصُّ ملفُّ Markdown |

---

## الجداول الجديدة

### 1. `data_categories` — كتالوجُ ما يُجمَع · **منصّة (ب)**

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | `HasUuid` |
| `key` | `string(64)` unique | `student_name` · `phone` · `class_recording` … |
| `label_ar` · `purpose_ar` | `string` · `text` | بلغةٍ مفهومةٍ لا نصٍّ قانونيٍّ مكثّف (`FR-004`) |
| `audience` | `string(64)` | من يطّلع عليه |
| `is_required` | `boolean` | اللازمُ يُميَّز عن الاختياريّ صراحةً، و**يُمنع** إخفاءُ الفارق (`FR-004`) |
| `owning_module` | `string(64)` | الوحدةُ التي تملكه — وهو ما يجعل `SC-002` قابلاً للقياس |
| `table_hint` · `column_hint` | `string` nullable | يُقارَنان بالمخطّط الفعليّ |

**الحارس**: لا مالكَ فرداً، فالكتابةُ بصلاحية `compliance.registry.manage` **المنصّية**، ومعها
اختبارٌ يردّ حاملَ أعلى دورِ مستأجرٍ بـ403 (‏قاعدةُ الصنف (ب) في الدستور).

**`is_required` وأثرُه على `Q4`**: صفُّ `class_recording` يُزرَع `is_required = true` — وهو
التمثيلُ الوحيدُ لقرارِ «الظهورُ صنفٌ لازم». فلا كيانَ موافقةٍ ثانياً، ولا علَمَ على المستخدم.

---

### 2. `retention_rules` — مدّةُ صنفٍ وسلوكُ انقضائه · **منصّة (ب)**

| العمود | النوع | ملاحظة |
|---|---|---|
| `data_category_id` | FK unique | صفٌّ واحدٌ لكلّ صنف |
| `retain_days` | `unsignedInteger` | **افتراضيٌّ سخيّ**، والقيمةُ الحاكمةُ صفٌّ في `platform_settings` (`FR-031أ`) |
| `expiry_behaviour` | enum | `delete` · `anonymise` · `archive` (`FR-027`) |

⚠️ **`retain_days` صفرٌ ليس «فوراً»** — وهو خطأُ المُدخِل الوحيدُ الذي يمحو بياناتَ المنصّةِ
كلَّها في ليلةٍ واحدة. القيمةُ الدنيا مفروضةٌ في الـAction لا في التحقّق فقط، لأن `SeedCommand`
يشغّل الـSeeders داخل `Model::unguarded()`.

---

### 3. `data_processors` — من يصله بيانٌ خارجَ خادمنا · **منصّة (ب)**

| العمود | النوع | ملاحظة |
|---|---|---|
| `key` | `string(64)` unique | `livekit` · `bunny` · `r2` · … |
| `purpose_ar` · `processing_location` | `text` · `string` | (`FR-038`) |
| `categories` | `json` | أصنافُ ما يصله |
| `erasure_capability` | enum | `full` · `partial` · `none` — وهو ما يُبيَّن للمستخدم بمقتضى `FR-024` |

**ستّةُ صفوفٍ تُزرَع من اليوم الأول**، منها **LiveKit** و**Bunny** — ولم يكونا موجودَين يوم
كُتبت السبيك، إذ كان مزوّدُ البثّ `NullBroadcastProvider` والوسائطُ قرصَنا المحلّيّ. راجع
[research.md §R8](./research.md).

---

### 4. `data_requests` — طلبُ اطّلاعٍ أو تصديرٍ أو حذف · **منصّة (أ)**

| العمود | النوع | ملاحظة |
|---|---|---|
| `subject_user_id` | FK users | من هو **عنه** |
| `requested_by_user_id` | FK users | من طلبه — **منفصلٌ عن الأول** بنفس سبب `terms_consents`: بلا إثباتِ الرابط، أيُّ مستخدمٍ يطلب باسم غيره والردُّ يؤكّد أن المعرّف لشخصٍ حقيقيّ |
| `type` | enum | `access` · `export` · `erasure` |
| `status` | enum | `pending` · `processing` · `completed` · `refused` · `on_hold` |
| `due_at` | timestamp | مُشتقٌّ من مهلةٍ في `platform_settings` (`FR-043`) |
| `export_path` · `export_expires_at` | nullable | قرصٌ خاصّ، ورابطٌ موقّعٌ قصيرُ المدة (`FR-018`) |
| `executed_by_user_id` · `completed_at` · `refusal_reason` | nullable | (`FR-026`) |

**الحارس**: `DataRequestPolicy` — صاحبُ البيان، أو وليٌّ تثبت علاقتُه عبر
`ParentStudentRelationPolicy` القائم، أو حاملُ `compliance.requests.execute` المنصّية.
**يُمنع** `BelongsToWorkspace`: طلبٌ واحدٌ يشمل بياناتِ الطالب عند كلّ مدرّسيه، **والتصديرُ
الجزئيُّ ليس حقاً مُنفَّذاً**.

**فهرس**: `(subject_user_id, type, status)` — سؤالُ «هل له طلبٌ قائمٌ من هذا النوع».

---

### 5. `legal_holds` — تعليقٌ يمنع الحذف · **منصّة (أ)**

| العمود | النوع | ملاحظة |
|---|---|---|
| `subject_user_id` | FK users | |
| `reason` | `text` | |
| `placed_by_user_id` · `placed_at` · `released_at` | | `compliance.holds.manage` |

**`released_at` قابلٌ للعدم، والاستعلامُ عنه `whereNull`** لا عمودٌ منطقيٌّ ثانٍ: علمٌ `is_active`
بجانب `released_at` هو جوابان لسؤالٍ واحدٍ يتباعدان عند أول كتابةٍ مباشرة.

⚠️ **التعليقُ يُقرأ في الكنسة وفي المحوِ بالطلب معاً** (`FR-030`، `SC-009`). قراءتُه في أحدهما
وحده هو العطلُ نفسُه من بابٍ آخر: طلبُ حذفٍ يمرّ ليلاً بلا أن يراه أحد.

---

### 6. `teacher_offboardings` — طيُّ مساحةِ عمل · **جسر**

| العمود | النوع | ملاحظة |
|---|---|---|
| `workspace_id` | FK · index | السياق — **الشيءُ الذي يُطوى** |
| `teacher_user_id` | FK users | المستخدمُ العامّ |
| `status` | enum | `requested` · `settlement_pending` · `notice_period` · `completed` |
| `settlement_cleared_at` | nullable | **يُمنع** الإتمامُ قبله (`FR-032`) |
| `students_notified_at` · `notice_ends_at` | nullable | (`FR-033`) |
| `content_export_path` | nullable | (`FR-034`) |

**حسمُ المستحقات لا يُحسَب هنا**: 014 تملك الدفترَ والحساب، والاتّصالُ عبر عقدٍ في
`App\Shared\Contracts\` لا استعلامٌ يعبر الحدّ — و`ContextIsolationTest` يفشل البناءَ على مفتاحٍ
خارجيٍّ أو استعلامٍ يجمع الجدولَين في أيّ اتجاه.

---

### 7. `student_profiles.date_of_birth` + `dob_is_estimated` — تعديلٌ على جدولٍ قائم

| العمود | النوع | ملاحظة |
|---|---|---|
| `date_of_birth` | `date` | قابلٌ للعدم في الهجرة الأولى، **غيرُ قابلٍ للعدم** في الثانية بعد التعبئة |
| `dob_is_estimated` | `boolean` default `true` للمُرحَّل | تقديرٌ من `student_age`، والعلَمُ هو ما يجعل «تقدير» قابلاً للقول |

⚠️ **هجرتان لا واحدة، والترتيبُ ملزِم**: عمودٌ `NOT NULL` يُضاف مباشرةً على جدولٍ قائمٍ يُفشل
النشرَ في السطر الأول — نفسُ سببِ تشغيلِ هجرةِ التكثيف قبل هجرةِ الفهرس في 016. والتعبئةُ تمشي
بـ**`chunkById`** لا `chunk`: المُسنَد (`date_of_birth IS NULL`) يتقلّص تحت المشي، و`chunk`
يُصفّح بـOFFSET فيقفز بعددِ ما أصلحته الصفحةُ السابقة — **ويُبلِّغ بالنجاح**.

---

## Enums

| Enum | القيم | ملاحظة |
|---|---|---|
| `DataRequestType` | `access` · `export` · `erasure` | |
| `DataRequestStatus` | `pending` · `processing` · `completed` · `refused` · `on_hold` | |
| `ErasureMode` | `delete` · `anonymise` · `retain` | العقدُ يستقبلها ولا يخترعها — [research.md §R3](./research.md) |
| `ExpiryBehaviour` | `delete` · `anonymise` · `archive` | |
| `ErasureCapability` | `full` · `partial` · `none` | |
| `ConsentDocument` | **قائم** — لا تُمَسّ | `DataProcessing` مكتوبةٌ فيها منذ 006 |

---

## ما يبقى بلا لمسٍ عن قصد

| الجدول | لماذا |
|---|---|
| `ledger_entries` | `LedgerEntry::booted()` يرفع استثناءً على `updating`/`deleting` — append-only مفروضٌ على النموذج. فإخفاءُ الهوية يقع على الجدول الذي **يشير** إلى الشخص، و`SC-007` (‏فارقُ صفرٍ في الإجماليات) يتحقّق **بالبناء** لأن لا شيءَ يُكتب هناك |
| `credit_movements` | نفسُ الحُجّة — سجلٌّ لا يُعدَّل |
| `terms_consents` | سجلُّ التزامٍ قانونيّ، **مستثنًى من المحو** بنصّ تعليقِ هجرته |
| `activity_log` | سجلُّ تدقيق. يُقرأ بمرشّح `ComplianceAuditSubjects` لا بحذفِ صفوف — جدولٌ واحدٌ يكتب فيه أربعةُ سياقاتٍ الآن |
| العلامةُ المائيةُ في التسجيلات | مطبوعةٌ **عند التشغيل** لا مخبوزةٌ في الملفّ، فتسقط مع منح التشغيل. يُكتب صراحةً لأن من يفترض العكسَ يبني حذفَ أصولٍ لا داعيَ له — أو أسوأ: يفترض أنها مخبوزةٌ ويطمئنّ |
