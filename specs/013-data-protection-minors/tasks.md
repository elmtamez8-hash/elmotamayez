---

description: "Task list — حماية بيانات القُصّر وحقوق البيانات (٠١٣)"
---

# Tasks: حماية بيانات القُصّر وحقوق البيانات (Minors' Data Protection & Data Rights)

**Input**: Design documents from `/specs/013-data-protection-minors/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) ·
[data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md) ·
**[review-findings.md](./review-findings.md)**

**Tests**: **مطلوبةٌ صراحةً** — المواصفةُ تعلّق **٢٢** معياراً على حرّاسٍ آليّة، **وستّةٌ منها
تصف عيباً لا يظهر إلّا باختبارٍ مصاغٍ بشكلٍ معيّن** (`SC-004` · `SC-006` · `SC-010` · `SC-016` ·
`SC-018` · `SC-022`). فالاختبارُ هنا تسليمٌ لا توثيقُه.

**Organization**: بالقصص، لتكون كلُّ قصّةٍ زيادةً قابلةً للتسليم والاختبار وحدها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يمكن التوازي (‏ملفّاتٌ مختلفة، بلا تبعيّة)
- **[Story]**: القصّة التي تخدمها المهمّة (`US1`…`US6`)

## Path Conventions

الخلفيّة `backend/` أحاديّةٌ معياريّة · الواجهة `frontend/src/`. الوحدةُ الجديدة
`backend/app/Modules/Compliance/`، **والمفرداتُ المشتركةُ في `backend/app/Shared/`**.

---

## ⚠️ اقرأ هذا قبل `T001`

**سبعةُ أشياءَ في هذه المرحلة يمرّ تنفيذُها الخاطئ أخضر.** كلٌّ مكتوبٌ في مهمّته، وتُجمَع هنا
لأنّ نسيانَ أيٍّ منها يُبطل معياراً كاملاً — وستّةٌ منها أخطاءٌ وقعت في نسخةٍ أولى من التصميم
نفسِه، فلا تُعَدّ احتمالاتٍ نظرية.

1. **`date_of_birth` لا يصير `NOT NULL` أبداً** (`T041`). `->change()` يُعيد التصريحَ ويهدم جدولاً
   يحمل `unique('user_id')` **ولا اختبارَ في الشجرة يؤكّد وجودَه**، ولا مصدرَ يملأ كلَّ صفّ،
   و`NULL` **هو** تمثيلُ `FR-009ج`. وهجرةٌ على قاعدةٍ فارغةٍ تنجح دائماً، فالبوّابةُ الخضراءُ
   لا ترى هذا.
2. **إشعارٌ بلا قالبٍ مُعتمَدٍ يُسقَط بصمت** (`T029`–`T031`). `DispatchNotification` يُسجّل ولا
   يُفشل، و`tests/Pest.php` يزرع القوالبَ قبل كلّ اختبار — **فكلُّ تأكيدٍ على إشعارٍ بلا قالبٍ
   ينجح فراغاً**. ستُّ نقاطِ إشعارٍ هنا.
3. **`Queue::fake()` فارغاً يبتلع ما يجب أن يعمل** (كلُّ اختبارٍ يمشي على خطٍّ زمنيّ). و`->delay()`
   يعمل فوراً على `sync`. تُفاك **وظائفُ محدّدةٌ بالاسم** لا فراغ.
4. **الكنسةُ تُشغَّل مرّتين في اختبارها** (`T110`). تشغيلٌ واحدٌ أخضرُ للأبد **ويُثبت العكس**.
   والثابتُ **ثلاثيّ**: صفوفُ بياناتٍ متطابقة · صفُّ تشغيلٍ **واحدٌ بالضبط** · صفر أثرٍ مُهلِك.
5. **انقضاءُ تسجيلٍ يُطلق `CourseStructureChanged`** (`T105` · `T111`). اختبارٌ يفحص حذفَ الأصل
   وحده **يمرّ على العطل بلا أن يراه**: عنصرٌ يبقى في مقام النسبة ولا يُمكن إتمامه يسقف كلَّ طالبٍ
   دون ١٠٠٪ فلا تصدر شهادةٌ **أبداً**. `T111` يتحقّق من **صدور الشهادة** لا من حذف الصفّ.
6. **كلُّ اختبارِ قراءةٍ منصّيةٍ يحتاج مساحتَي عملٍ** (`T075` · `T121`). `WorkspaceContext::id()`
   يرتدّ إلى `last_workspace_id` **لمدير المنصة أيضاً**، فقراءةٌ متروكةٌ في النطاق **تنجح في
   اختبارها على تجربةٍ بمساحةٍ واحدة**.
7. **تأكيدُ التسريب بعبارةٍ عربيةٍ صادقٌ فراغاً** (`T072`). `getContent()` يُهرِّب غيرَ ASCII، فأيُّ
   `not->toContain('حل…')` يمرّ أيّاً كانت الحمولة. تُستعمل `JSON_UNESCAPED_UNICODE` أو مَحرمٌ ASCII.

---

## Phase 1: Setup — البنيةُ والأسماءُ والإعدادات

- [X] T001 أنشئ `backend/app/Modules/Compliance/ComplianceServiceProvider.php` يرث `App\Shared\Modules\Module` — يُكتشَف تلقائياً، **ويُمنع** تسجيلُه في `bootstrap/providers.php`
- [X] T002 أنشئ شجرةَ الوحدة: `backend/app/Modules/Compliance/{Actions,Support,Jobs,Models,Enums,Policies,Http/{Controllers,Requests,Resources},Database/Migrations,routes}` — ⚠️ `Database/Migrations` بحرف **M** كبير، وخطأُ الحالة يُحمّل **صفر** هجراتٍ على Linux بصمت
- [X] T003 أضف `app/Modules/Compliance/Database/Migrations` إلى قائمة `scanDirectories` في `backend/phpstan.neon` — بلا ذلك يعود كلُّ نموذجٍ «undefined property» على المستوى ٨
- [X] T004 [P] أضف `"ext-zip": "*"` إلى `require` في `backend/composer.json` — الامتدادُ من PHP نفسِه فـ«صفر تبعيةٍ جديدة» يبقى صحيحاً، لكن خادماً بلا الامتدادِ يفشل على أوّل تصديرٍ برسالةٍ لا تدلّ على السبب
- [X] T005 [P] أضف خمسَ صلاحياتٍ **منصّيةٍ** إلى `Tenancy\Support\Permissions`: `compliance.requests.execute` · `compliance.registry.manage` · `compliance.holds.manage` · `compliance.offboarding.execute` · `compliance.breaches.manage` — **وأضفها إلى `Permissions::all()`**، فالقائمةُ مكتوبةٌ بيدٍ والحمايةُ تبدأ منها لا من الثابت
- [X] T006 [P] أضف `compliance` إلى `PermissionLabels::SUBJECTS` والأفعالَ الخمسةَ إلى `ACTIONS` في `backend/app/Modules/Tenancy/Support/PermissionLabels.php` — بلا تسميةٍ عربيةٍ تُصيَّر نصّاً منقّطاً على لوحةٍ عربيةٍ فقط
- [X] T007 [P] أضف دورَ `compliance-officer` إلى `backend/app/Modules/Tenancy/Support/Roles.php` وإلى `backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php` **بوصفه دوراً بلا فريق** — بلا صفٍّ في `platform_staff` تصل الصلاحياتُ `is_super_admin` وحده، فيصير «من نفّذه» في `FR-026` شخصاً واحداً على المنصّة كلِّها
- [X] T008 [P] عرِّف مُحدِّدَ المعدّل **المُسمّى** `data-rights` في `AppServiceProvider::registerRateLimiters()` **مُفتَرَساً على المستخدم لا على `ip`** — أسرةٌ خلف موجّهٍ واحدٍ تشترك عنواناً، فحدٌّ على العنوان يمنع الأخَ الثاني لأنّ أخاه طلب. و`throttle:N,M` مضمَّناً ممنوع
- [X] T009 [P] أنشئ `backend/config/compliance.php` باحتياطيّات المهل والمدد، وأضف كلَّ مفتاحٍ جديدٍ إلى `PlatformSettings::KEYS` في `backend/app/Modules/Tenancy/Support/PlatformSettings.php` — القائمةُ **allowlist صريحة**، ومفتاحٌ خارجها لا يُحرَّر من اللوحة ولا يرتدّ إلى `config`
- [X] T010 [P] أضف مُشرِفَ طابور `compliance` إلى `defaults` **و**`environments` في `backend/config/horizon.php` بمهلةٍ مُقاسةٍ لـ`SC-014`، **وأضف `redis:compliance` و`redis:maintenance` إلى `waits`** — التعليقُ هناك يقول بنصّه إنّ زوجاً غائباً **لا يُراقَب بحدٍّ افتراضيٍّ بل لا يُراقَب**، وما يتأخّر هنا مهلةٌ قانونية
- [X] T011 [P] انشر `backend/config/scout.php` بـ`'queue' => env('SCOUT_QUEUE', true)` — لا وجودَ للملفّ اليوم فالافتراضُ `false`، ونداءُ `unsearchable()` صفّاً صفّاً يصير نداءَ شبكةٍ حاجزاً لكلّ صفّ
- [X] T012 [P] أضف `Compliance` إلى مسحِ `tests/Feature/Settlement/ContextIsolationTest.php` — يمسح `Modules/Settlement/` و`Modules/Payments/` وحدهما اليوم، **فالحارسُ المُستشهَدُ به لا يحرس هذه المرحلة** قبل التوسيع

---

## Phase 2: Foundational — تحجب كلَّ القصص

### المفرداتُ المشتركة — في `Shared` لا في `Compliance`

- [X] T013 أنشئ `backend/app/Shared/Support/ErasureMode.php` — enum `delete` · `anonymise` · `retain`. ⚠️ في `Shared` لا في `Compliance`: وسيطُ عقدٍ تنفّذه **١٣** وحدةً، ولا يجوز أن تستورد ثلاثَ عشرةَ وحدةً enum من وحدةٍ واحدة. سابقتُه `Shared\Support\GuardianPermission` بتعليقها «مفرداتٌ مشتركةٌ بين وحدتَين»
- [X] T014 [P] أنشئ `backend/app/Shared/Support/ExpiryBehaviour.php` — enum `delete` · `anonymise` · `archive`
- [X] T015 أنشئ `backend/app/Shared/Data/DataSubject.php` يرث `DataTransferObject`: `user` · `workspaceIds` · `enrollmentIds` · `grantedScope`. ⚠️ **يُحلّ مرّةً ويُمرَّر**: `erase(User)` يجعل ١٣ وحدةً تُشغّل استعلامَ مساحاتِ العمل بنفسها، وعلى كلٍّ منها أن تُصيب `forWorkspace()` وحدها — ثلاثةَ عشرةَ موضعاً لخطأٍ واحد
- [X] T016 أنشئ `backend/app/Shared/Contracts/PersonalDataOwner.php` بـ**خمسِ** دوالّ: `moduleKey()` · `describe()` · `export(DataSubject): iterable` · `erase(DataSubject, ErasureMode, int): int` · `expire(string, CarbonImmutable, ExpiryBehaviour, int): int` — راجع [`contracts/personal-data-owner.md`](./contracts/personal-data-owner.md)
- [X] T017 [P] أضف الحالةَ **السادسة** `DataRights` إلى `backend/app/Shared/Support/GuardianPermission.php` بتسميتها العربية — الخمسُ القائمةُ لا واحدةَ منها عن حقوق البيانات، **فاليومَ لا يوقّع وليٌّ موافقةَ معالجةِ بياناتِ ابنه إلا إن كان مُخوَّلاً بالمدفوعات**
- [X] T018 [P] أنشئ `backend/app/Shared/Contracts/ConsentDirectory.php` بخمسِ دوالّ (‏ومنها `consentedCategories` و`record`) — راجع [`contracts/consent-directory.md`](./contracts/consent-directory.md). **والوثيقةُ سلسلةٌ نصّيةٌ لا enum**، ولا قيمةَ افتراضيةَ للوسيط
- [X] T019 [P] أنشئ `backend/app/Shared/Contracts/SettlementClearance.php` بـ`outstandingFor()` و`isCleared()` — راجع [`contracts/settlement-clearance.md`](./contracts/settlement-clearance.md). كان «عقداً في Shared» **بلا اسمٍ ولا ملفّ**

### الكتالوجُ وسجلُّ المعالِجين

- [X] T020 أنشئ هجرةَ `data_categories` في `Compliance/Database/Migrations/` — و`table_name`/`column_name` **غيرُ قابلَين للعدم** (كانا `*_hint` قابلَين، فآليةُ `SC-002` نفسُها كانت اختياريّة)، و`retain_days` **`unsignedSmallInteger`** nullable
- [X] T021 في هجرةِ `T020` داخل `backend/app/Modules/Compliance/Database/Migrations/`: اكتب في تعليقها سببَ `unsignedSmallInteger` — `created_at + INTERVAL n DAY` بعد سنة ٩٩٩٩ يرفع **ERROR 1441** على MySQL **فيقتل الكنسةَ كلَّها**، وSQLite يُرجع `NULL` فلا ينقضي الصفُّ أبداً: **لا خطأَ في أيٍّ من الاتجاهَين محلّياً**
- [X] T022 [P] أنشئ هجرةَ `data_processors` في `Compliance/Database/Migrations/` بـ`erasure_capability` enum
- [X] T023 [P] أنشئ `Compliance/Models/{DataCategory,DataProcessor}.php` بـ`HasUuid` و`declare(strict_types=1)` — **وبلا `BelongsToWorkspace`**: الطبقةُ منصّةٌ (ب)، ولا مالكَ فرداً لها
- [X] T024 أنشئ `Compliance/Actions/{SaveDataCategory,SaveDataProcessor}.php` — **والحدُّ الأدنى والأقصى لـ`retain_days` مفروضان هنا** لا في التحقّق فقط: `SeedCommand` يشغّل كلَّ الـSeeders داخل `Model::unguarded()`، و`retain_days = 0` ليس «فوراً» بل محوُ بياناتِ المنصّةِ في ليلة
- [X] T025 [P] أنشئ `database/seeders/DataCategorySeeder.php` — ويُزرَع `class_recording` بـ`is_required = true`، وهو **التمثيلُ الوحيدُ** لقرار `Q4`
- [X] T026 [P] أنشئ `database/seeders/DataProcessorSeeder.php` بستّة صفوفٍ منها **`livekit`** و**`bunny`** — لم يكونا موجودَين يوم كُتبت السبيك (‏كان مزوّدُ البثّ `NullBroadcastProvider` والوسائطُ قرصَنا)، وكلٌّ منهما يحمل صوتَ قاصرٍ وصورتَه
- [X] T027 أنشئ `Compliance/Support/PersonalDataRegistry.php` يجمع وسمَ `compliance.personal_data` **ويرتّب بـ`moduleKey()`** — أرشيفٌ يختلف ترتيبُ ملفاته بين تشغيلَين يجعل أيَّ مقارنةٍ آليةٍ ضجيجاً
- [X] T028 [P] أنشئ `Compliance/Support/ComplianceSettings.php` تقرأ كلَّ مهلةٍ ومدّةٍ من `platform_settings` ثمّ `config/compliance.php` — **ولا ثابتَ في الكود**: مهلةٌ لا تتغيّر إلا بنشرِ كودٍ تُصبح خاطئةً يومَ يُحدِّث المنظِّمُ إرشادَه ولا ينتبه أحد

### الإشعاراتُ — قبل أيّ مُطلِقٍ لها

- [X] T029 أضف ستَّ حالاتٍ إلى `backend/app/Modules/Notifications/Support/NotificationType.php`: `guardian_consent_required` · `data_ownership_transferred` · `data_request_created` · `data_request_completed` · `guardian_consent_conflict` · `teacher_offboarding_notice`
- [X] T030 أضف ستَّةَ قوالبَ **مُعتمَدةً** إلى `database/seeders/NotificationTemplateSeeder.php` — ⚠️ `TemplateRenderer` يرفض قالباً مفقوداً أو غيرَ مُعتمَدٍ و`DispatchNotification` **يُسجّل ولا يُفشل**، فبلا هذه الصفوفِ تُسقَط الستُّ بصمتٍ **وكلُّ تأكيدٍ عليها ينجح فراغاً**
- [X] T031 أنشئ `backend/tests/Feature/Compliance/NotificationTemplateCoverageTest.php` — **`SC-018`**: يقارن أنواعَ الإشعارات التي تُطلقها هذه المرحلةُ بصفوف الـSeeder ويفشل على نوعٍ بلا قالب
- [X] T032 [P] أنشئ `Compliance/Support/ComplianceAuditSubjects.php` — مرشّحُ `activity_log` الخاصّ بها، عضوٌ ثالثٌ في عائلة `BillingAuditSubjects`/`SettlementAuditSubjects`. **و`activity_log` جدولٌ واحدٌ تكتب فيه سبعُ وحداتٍ**، فقارئٌ يجلبه ثمّ يُسقط صفوفَ غيرِه فرعٌ منسيٌّ واحدٌ بعيدٌ عن كشفِ ما لا يخصّه

**Checkpoint**: الكتالوجُ والمفرداتُ والإشعاراتُ قائمة. تبدأ القصصُ.

---

## Phase 3: US1 — موافقةُ وليّ الأمر على معالجة بيانات ابنه (P1) 🎯 MVP

**الهدف**: لا يُفعَّل حسابُ قاصرٍ قبل موافقةٍ صريحةٍ مسجَّلةٍ بنسختها ووقتها وعنوانها وأصنافها.

**اختبارٌ مستقلّ**: أتمِمْ تسجيلَ طالبٍ قاصرٍ وتحقّق من رفض التفعيل بلا موافقة، ومن محتوى السجلّ.

### الموافقةُ — تعديلٌ على المشحون

- [X] T033 [US1] أنشئ هجرةً في **`Payments/Database/Migrations/`** تُضيف `categories` json nullable و`decision` enum(`granted`,`refused`) default `granted` إلى `terms_consents` — ⚠️ الهجرةُ في Payments لأن الجدولَ لها، **فادّعاءُ «صفر تعديلٍ في Payments» يسقط صراحةً**
- [X] T034 [US1] في `T033`: أضف `unique(student_user_id, document, version, consented_at)` — الجدولُ اليومَ فهارسُ عاديةٌ فقط، فضغطتان تكتبان صفَّين ويُطلَق الحدثُ مرّتين فيُفعَّل حسابٌ مرّتين ويُشعَر وليٌّ مرّتين. و`consented_at` في المفتاح لأن السحبَ صفٌّ ثانٍ **مشروعٌ** لنفس الثلاثيّ
- [X] T035 [US1] أضف `consentedCategories(User, string): ?array` إلى `Payments\Support\ConsentRegistry` تقرأ **أحدثَ** صفٍّ للنسخة السارية — و`has()`/`everAccepted()`/`forStudentIds()` **لا تتغيّر**: تجيب «هل وقّع النسخةَ السارية» وهو ما تعتمد عليه الفوترةُ، **ولا يجوز أن ينقلب معناه**
- [X] T036 [US1] عدِّل `has()` في `backend/app/Modules/Payments/Support/ConsentRegistry.php` لتصير «يوجد `granted` ولا يوجد `refused` أحدثُ منه لأيّ مُوقِّعٍ مُخوَّل» — ⚠️ بلا هذا **«الرفضُ يغلب» غيرُ قابلٍ للتعبير**: مع وليٍّ موافقٍ وآخرَ رافضٍ يفوز الأسبقُ إدراجاً
- [X] T037 [US1] أضف وسيطَي `$categories` و`$decision` إلى `handle()` في `backend/app/Modules/Payments/Actions/RecordTermsConsent.php`، **واسأل `GuardianPermission::DataRights`** لا `Payments` عند الوثيقة `data_processing`
- [X] T038 [US1] أنشئ `Payments\Support\EloquentConsentDirectory.php` يفوّض القراءةَ إلى `ConsentRegistry` والكتابةَ إلى `RecordTermsConsent`، واربطه في `PaymentsServiceProvider` — ⚠️ **لا يُربَط `ConsentRegistry` مباشرةً**: قرأتُ الصنفَ كلَّه وهو **بلا `record()`**، فالربطُ إليه لا يصحّ. مطابقٌ `EloquentEnrollmentDirectory` المشحون
- [X] T039 [US1] [P] أنشئ `backend/tests/Feature/Compliance/ConsentCategoriesTest.php` — يؤكّد أنّ الأصنافَ تُحفَظ وتُقرأ، وأنّ `null` ≠ `[]` (‏الأولى «وثيقةٌ بلا أصناف»، والثانية «وافق على لا شيء»)

### تاريخُ الميلاد وحالةُ التفعيل — في `Identity`

- [X] T040 [US1] أنشئ هجرةً **واحدةً** في `Identity/Database/Migrations/` تُضيف `date_of_birth` (date nullable) و`dob_is_estimated` (boolean nullable **بلا `default`**) و`ownership_transferred_at` (timestamp nullable) إلى `student_profiles`
- [X] T041 [US1] في هجرةِ `T040` داخل `backend/app/Modules/Identity/Database/Migrations/`: اكتب في تعليقها **الأسبابَ الثلاثةَ لعدم وجود `NOT NULL` أبداً** — لا مصدرَ يملأ كلَّ صفّ (‏من سجّل بنفسه لا صفَّ له في `parent_student_relations`) · `->change()` يُعيد التصريحَ ويهدم جدولاً يحمل `unique('user_id')` **ولا اختبارَ يؤكّد وجودَه** · و`NULL` **هو** تمثيلُ `FR-009ج` ومع `NOT NULL` يحتاج الكودُ تاريخاً وهميّاً **وتاريخٌ وهميٌّ عيدُ ميلادٍ يُطلق انتقالَ ملكيةٍ حقيقياً**
- [X] T042 [US1] في هجرةِ `T040` داخل `backend/app/Modules/Identity/Database/Migrations/`: أنشئ صفوفَ `student_profiles` الناقصةَ لكلّ `users.platform_role = student` — ⚠️ هجرةُ 004 عبّأت الملفّاتَ لمن له `grade_level_slug` أو `registered_by_parent` وحدهم، **فطالبٌ أقدمُ بلا واحدٍ منهما لا صفَّ له** و`SC-017` منصوصٌ على «حساباتِ الطلاب» فيصير التأكيدُ **صادقاً فراغاً** لهم
- [X] T043 [US1] في هجرةِ `T040` داخل `backend/app/Modules/Identity/Database/Migrations/`: عبِّئ `date_of_birth` من **`MIN(student_age)`** بـ**`chunkById`** — الجدولُ فريدٌ على `(guardian, student)` فلطالبٍ عدّةُ أعمارٍ كتبها أشخاصٌ مختلفون و`UPDATE … JOIN` يختار **عشوائياً** على MySQL، وسنةٌ عند حدّ الثامنةَ عشرة هي الفرقُ بين من يوقّع بنفسه ومن لا يجوز له. و`chunk` يُصفّح بـOFFSET والمُسنَدُ يتقلّص **فيقفز ويُبلِّغ بالنجاح**
- [X] T044 [US1] أنشئ `Identity/Support/UserStatus.php` — enum `active` · `pending_guardian_consent`. ⚠️ **الحالةُ تُنشأ هنا ولم تكن موجودة**: `users.status` قيمتُه الافتراضيةُ `'active'` ولا شيءَ في Identity يكتبه **ولا شيءَ يحرسه**. ولا تعبئة: كلُّ الحسابات القائمةِ `active` وتبقى
- [X] T045 [US1] أنشئ `Identity/Actions/ActivateStudentAccount.php` تسأل `ConsentDirectory::hasCurrent()` وترفض بدونها — ⚠️ **في `Identity` لا في `Compliance`**: Action في Compliance يكتب `users.status` هو بعينه خرقُ المبدأ III الذي وُجد العقدُ لتجنّبه
- [X] T046 [US1] عدِّل `Identity/Actions/RegisterStudent.php` ليكتب `date_of_birth` و`dob_is_estimated = false`، ويضع `status = pending_guardian_consent` لمن هو دون الثامنةَ عشرة **أو مجهولِ التاريخ** (`FR-009ج`)
- [X] T047 [US1] عدِّل `backend/app/Modules/Identity/Actions/RegisterStudent.php` ليُنشئ `ParentStudentRelation` بحالة **`Pending`** من اتّصالِ الوليّ ويُشعِره بـ`guardian_consent_required` — ⚠️ **العلاقةُ هي الدعوة** ولا آليةَ رموزٍ ثانية: `Pending` **لا يمنح شيئاً اليوم** لأن `EloquentGuardianDirectory` يسأل `->active()` في مواضعه الثلاثة، فهي دعوةٌ بلا صلاحيةٍ **بحكم البناء** لا بحكم فحصٍ يُنسى
- [X] T048 [US1] عدِّل `Identity/Http/Requests/RegisterStudentRequest.php` و`Identity/Data/RegisterStudentData.php` بحقلَي تاريخِ الميلاد واتّصالِ الوليّ
- [ ] T049 [US1] أضف مُدخلاتِ الحقولِ الجديدةِ إلى `attributes` في `backend/lang/ar/validation.php` — بلا مُدخلٍ يُصيَّر `date_of_birth` نصّاً إنجليزياً على شاشةٍ عربيةٍ فقط
- [X] T050 [US1] عدِّل `Identity/Actions/StartAuthSession.php` ليرفض `pending_guardian_consent` بـ`403 code: pending_guardian_consent` **ولا يَسُكّ رمزاً إطلاقاً** — سَكُّ رمزٍ ثمّ تقييدُه يجعل أيَّ خللٍ في التقييد دخولاً كاملاً، بنفس منطقِ التحقّق الثنائيّ
- [X] T051 [US1] أطلِق `ProcessingConsentGranted` من `backend/app/Modules/Payments/Actions/RecordTermsConsent.php` عند الوثيقة `data_processing`، واستمع له في `backend/app/Modules/Identity/IdentityServiceProvider.php` بـ`Event::listen()` ليُنادي `ActivateStudentAccount`

### نقاطُ النهايةِ والشاشات

- [X] T052 [US1] [P] أنشئ `Compliance/Http/Controllers/PrivacyCategoryController.php` + Resource لـ`GET /privacy/categories` — واللازمُ مُميَّزٌ عن الاختياريّ، **ومدّةُ الاحتفاظِ عمودان على نفس الجدول** بعد قطع `retention_rules` فلا استعلامَ لكلّ صنف
- [X] T053 [US1] [P] أنشئ `GET /privacy/policy` في نفس المتحكّم — يُصيَّر نصُّ Markdown بـ`MarkdownRenderer` القائم، **يُجرِّد HTML الخام** فقائمةُ المسموح هي مجموعةُ ميزات Markdown ولا مُنظِّفَ يُضبَط خطأً
- [X] T054 [US1] أنشئ `PUT /privacy/consents/categories` في `Compliance/Http/Controllers/PrivacyConsentController.php` — **بالمجموعةِ الكاملةِ والنسخةِ المقروءة**، ويكتب صفَّ موافقةٍ جديداً. ⚠️ **ولا يُعدَّل صفٌّ قديمٌ أبداً**: الصفُّ سجلُّ توقيعٍ في لحظةٍ ومحوُه يمحو الدليلَ على ما وُقِّع. وفرقٌ مُطبَّقٌ على حالةٍ قُرئت قبل ثانيةٍ هو التحديثُ المفقود — نفسُ صيغةِ إعادةِ الترتيب في 016
- [X] T055 [US1] ⚠️ **لا تُنشئ `POST/GET /privacy/consents` ولا `RecordProcessingConsent`** — `Payments\Http\Controllers\TermsConsentController::index/store` يخدمهما اليوم لكلّ `ConsentDocument` بما فيها `data_processing`، بـip وuser-agent ونسخةٍ من السجلّ وتفويضِ وليّ. اربِط الواجهةَ بالنقطةِ القائمة
- [X] T056 [US1] [P] استبدِل `PolicyPlaceholder` بنصّ السياسةِ الحقيقيّ في `frontend/src/app/(public)/privacy/page.tsx` — ⚠️ الصفحةُ **قائمةٌ اليوم** ومربوطةٌ من كلّ تذييل، فوضعُ السياسة خلف مصادقةٍ يترك الزائرَ يقرأ «لم يُكتب النصُّ بعد» **ويُصادِم مسارَين باسم `privacy`**
- [X] T057 [US1] [P] أنشئ `frontend/src/components/compliance/ConsentScreen.tsx` — الأصنافُ وأغراضُها ومددُها، واللازمُ مُميَّزٌ عن الاختياريّ. ألوانٌ من `@theme` وحده · خصائصُ منطقية · نصوصٌ من `labels.ts`
- [X] T058 [US1] [P] أضف حقلَ تاريخِ الميلاد واتّصالِ الوليّ إلى `frontend/src/app/(public)/signup/**` — الأخطاءُ عبر `fieldErrors()` تحت حقلها
- [X] T059 [US1] [P] أضف شاشةَ موافقةِ الوليّ إلى `frontend/src/app/(app)/(shell)/family/` — الشاشةُ **قائمةٌ** وهي موضعُها الطبيعيّ
- [X] T060 [US1] [P] أنشئ `frontend/src/lib/compliance.ts` والتسمياتِ العربيةَ في `frontend/src/lib/labels.ts`

### حرّاسُ US1

- [X] T061 [US1] [P] أنشئ `backend/tests/Feature/Compliance/MinorConsentGateTest.php` — **`SC-001`**: صفر حسابِ قاصرٍ مفعَّلٍ بلا موافقةٍ مسجَّلةٍ بنسختها ووقتها وعنوانها
- [X] T062 [US1] [P] أنشئ `backend/tests/Feature/Compliance/MinorActivationGateTest.php` — **`SC-017`** على **مسار التسجيل الذاتيّ** تحديداً (‏المسارُ الذي لا وليَّ أمرٍ فيه اليوم إطلاقاً)، **ومقيسٌ من `users`** لا من `student_profiles`
- [X] T063 [US1] [P] أنشئ `backend/tests/Feature/Compliance/CategoryWithdrawalTest.php` — **`SC-019`**: سحبٌ ثمّ محاولةُ معالجةٍ تُردّ. و`FR-007` كان **بلا جدولٍ ولا Action ولا نقطةٍ ولا اختبار**
- [X] T064 [US1] [P] أنشئ `backend/tests/Feature/Compliance/ConsentConflictTest.php` — وصيّان مختلفان: **الرفضُ يغلب**، ويُبلَّغ الطرفان **بلا كشفِ من رفض** (‏قد يكونان في خلافِ حضانةٍ ورسالةٌ تقول «أمُّك رفضت» بيانٌ شخصيٌّ عن ثالثٍ في إشعارٍ آليّ)
- [X] T065 [US1] [P] أنشئ `frontend/src/components/compliance/ConsentScreen.test.tsx` — **`SC-003`**: تأكيدٌ على **نصّ الشاشة** أنّ «الظهورُ في تسجيلات الحصص (‏صوتاً وصورةً)» بين **اللازم**
- [X] T066 [US1] [P] أنشئ `backend/tests/Feature/Compliance/CategoryRegistryCoverageTest.php` — **`SC-002`**: يقارن `data_categories` بالمخطّط الفعليّ ويفشل على عمودٍ شخصيٍّ غيرِ مُعلَن
- [X] T067 [US1] [P] أنشئ `backend/tests/Feature/Compliance/PlatformRegistryPermissionTest.php` — قاعدةُ الصنف (ب): حاملُ **أعلى** دورِ مستأجرٍ يُردّ بـ403 على الكتالوج والمعالِجين
- [ ] T068 [US1] [P] أنشئ `backend/tests/Feature/Tenancy/PlatformPermissionPivotTest.php` — ⚠️ **بابٌ ثانٍ لا يمرّ بالنموذج**: نمطُ الهجرات المشحونُ يكتب `DB::table('role_has_permissions')->insertOrIgnore()` مباشرةً. يؤكّد صفر صفٍّ يصل صلاحيةً منصّيةً بدورٍ ذي `team_id` غيرِ معدوم
- [X] T069 [US1] [P] أنشئ `backend/tests/Feature/Tenancy/PermissionConstantCoverageTest.php` — يقارن ثوابتَ `Permissions` بـ`all()`. القائمةُ مكتوبةٌ بيدٍ، واسمٌ ناقصٌ لا يُزرَع أبداً **وكلُّ فحصٍ يفشل حتى لمدير المنصة**

**Checkpoint**: `US1` قابلةٌ للتسليم وحدها — **هذا هو الـMVP**.

---

## Phase 4: US2 — الظهورُ صنفٌ لازمٌ مُعلَن، والتسجيلُ يُعلَن قبل بدئه (P2)

**الهدف**: الموافقةُ على الظهور صريحةٌ ومقروءةٌ قبل منحها، والتسجيلُ مُعلَنٌ في الغرفة.

**اختبارٌ مستقلّ**: اقرأ شاشةَ الموافقة وتحقّق أنّ الظهورَ مُسمًّى بين اللازم؛ وافتح غرفةً مسجَّلةً
وتحقّق من الإعلان قبل بدء التسجيل.

- [X] T070 [US2] [P] أضف مكوّنَ إعلانِ التسجيل إلى صفحة الغرفة في `frontend/src/app/(app)/(shell)/**` وسطحَه في `LiveSessions` — **`FR-013`**، وهو أحدُ سطرَين نجَوا من `Q4` وكان **بلا موضعٍ** في نسخةٍ أولى
- [X] T071 [US2] [P] أنشئ `backend/tests/Feature/Compliance/RecordingAnnouncementTest.php` — الإعلانُ **قبل** بدء التسجيل لا بعده
- [X] T072 [US2] [P] أنشئ `backend/tests/Feature/Compliance/RecordingAccessTest.php` — `FR-012`: صفر حرمانٍ من مشاهدة تسجيلِ حصةٍ حضرها، وصفر وصولٍ لمن لم يحضر. ⚠️ **والمَحرمُ ASCII أو `JSON_UNESCAPED_UNICODE`**: `getContent()` يُهرِّب غيرَ ASCII فتأكيدٌ بعبارةٍ عربيةٍ **صادقٌ فراغاً** أيّاً كانت الحمولة
- [X] T073 [US2] [P] أضف حالةً إلى `CategoryRegistryCoverageTest` تؤكّد `class_recording.is_required = true` **وصفر كيانِ موافقةٍ ثانٍ في المخطّط** — `SC-003`

**Checkpoint**: `US1` + `US2` قابلتان للتسليم.

---

## Phase 5: US3 — حقُّ الاطّلاع والتصدير (P3)

**الهدف**: طلبٌ واحدٌ يُنتج ملفاً منظّماً يشمل كلَّ الوحدات، بلا بياناتِ غيرِه.

**اختبارٌ مستقلّ**: اطلب تصديراً لطالبٍ له بياناتٌ في كلّ وحدةٍ وطابِقِ الناتجَ بالمصادر.

### الطلب

- [X] T074 [US3] أنشئ هجرةَ `data_requests` في `backend/app/Modules/Compliance/Database/Migrations/` بالفهارس الأربعة: `(subject_user_id, type, status)` · `(status, due_at)` · `(status, last_attempt_at)` · `(export_expires_at)` — ⚠️ **`due_at` هو مهلةُ `FR-043` القانونيةُ ولم يكن لها فهرسٌ تُسأل به**
- [X] T075 [US3] في هجرةِ `T074` داخل `backend/app/Modules/Compliance/Database/Migrations/`: أضف `open_key` **nullable unique** — «طلبٌ مفتوحٌ واحدٌ لكلّ (شخص · نوع)» قراءةٌ ثمّ إدراجٌ بفهرسٍ عاديّ، وتبويبان في ثانيةٍ يمرّان معاً. **ولا فهارسَ جزئيةً في MySQL** فالشكلُ هو `captured_order_id`، **وغيرُ `$fillable`**: قابلاً للإسناد الجَمْعيّ يصير باباً ثانياً لانتزاع القفل
- [X] T076 [US3] أنشئ `Compliance/Models/DataRequest.php` + `Enums/{DataRequestType,DataRequestStatus}.php` — **بلا `BelongsToWorkspace`**: طلبٌ واحدٌ يشمل بياناتِ الطالب عند كلّ مدرّسيه، **والتصديرُ الجزئيُّ ليس حقاً مُنفَّذاً**
- [X] T077 [US3] أنشئ `Compliance/Policies/DataRequestPolicy.php` يسأل **`GuardianDirectory::isAuthorised($caller, $subject, GuardianPermission::DataRights)`** — ⚠️ **ويُمنع `ParentStudentRelationPolicy`**: يستقبل صفَّ علاقةٍ لا (وليّاً · طالباً) فسؤالُه دائريّ · **ولا يفحص `status`** فيمرّ `Pending` الذي يُنشئه أيُّ مستخدمٍ لأيّ معرّفِ طالبٍ ويمرّ `Revoked` لوليٍّ نزعته الأسرة · **وفرعُه الثالثُ مبنيٌّ للمدرّسين** بصلاحيةٍ يحملها كلُّ مدرّسٍ ومساعد
- [X] T078 [US3] أنشئ `Compliance/Actions/CreateDataRequest.php` — ويُشتقّ `due_at` من `ComplianceSettings`، ويُكتب `granted_scope` من `permissions` الوليّ حين لا يكون الطالبُ نفسَه هو الطالب
- [X] T079 [US3] في `backend/app/Modules/Compliance/Actions/CreateDataRequest.php`: **رفضٌ واحدٌ لا يميّز** «لا يوجد» عن «ليس لك» — `LinkGuardian` يوحّدهما عن قصدٍ اليوم (‏ولذلك أُسقطت قاعدةُ `exists` من طلبه)، ورمزان مختلفان يجعلان النقطةَ **عرّافاً** يؤكّد أن معرّفاً مُقدَّماً لحسابٍ حقيقيّ

### تنفيذُ العقدِ في ١٣ وحدة — `describe()` و`export()`

- [X] T080 [P] [US3] [P] أنشئ `Identity/Support/IdentityPersonalData.php` ووسِمْه في مزوّدها بـ`compliance.personal_data`
- [X] T081 [P] [US3] [P] أنشئ `Learning/Support/LearningPersonalData.php` — ⚠️ `lesson_progress` **بلا عمودِ مستخدم**، يُبلَغ عبر `enrollment_id`؛ **تُقطَّع قائمةُ المعرّفات عند ٥٠٠** فالضخمُ هو القائمةُ الأبوَّةُ لا المشي
- [X] T082 [P] [US3] [P] أنشئ `Assessments/Support/AssessmentsPersonalData.php` مُركِّباً `AssessmentFieldAllowlist` القائمة — ⚠️ **أثقلُ وحدة**: `attempt_items` صفٌّ لكلّ عنصرٍ من كلّ محاولةٍ **وكلٌّ يحمل لقطةَ JSON**، فهو الجدولُ الشخصيُّ الوحيدُ الذي صفوفُه كيلوبايتاتٌ لا بايتات
- [X] T083 [P] [US3] [P] أنشئ `Certificates/Support/CertificatesPersonalData.php`
- [X] T084 [P] [US3] [P] أنشئ `Payments/Support/PaymentsPersonalData.php` مُركِّباً `PaymentFieldAllowlist` — ⚠️ **ويذكر وجودَ الإيصال وتاريخَه ولا يُضمّن `receipt_url` ولا صورتَه**: صورةُ حوالةٍ يرفعها إنسانٌ قد تحمل اسمَ صاحبِ حسابٍ **ثالثٍ** ورقمَه، وهو ما يمنعه `SC-005`. تضمينُ صورةٍ لم نقرأها في أرشيفٍ يُسلَّم نشرُ ما لا نعرف محتواه
- [X] T085 [P] [US3] [P] أنشئ `LiveSessions/Support/LiveSessionsPersonalData.php` — ويُستثنى صفُّ المضيف بـ`Attendance::scopeExcludingHost()`
- [X] T086 [P] [US3] [P] أنشئ `Media/Support/MediaPersonalData.php` مُركِّباً `MediaFieldAllowlist`
- [X] T087 [P] [US3] [P] أنشئ `Notifications/Support/NotificationsPersonalData.php`
- [X] T088 [P] [US3] [P] أنشئ `Marketplace/Support/MarketplacePersonalData.php` مُركِّباً `PublicFieldAllowlist`
- [X] T089 [P] [US3] [P] أنشئ `Settlement/Support/SettlementPersonalData.php` مُركِّباً `TeacherFieldAllowlist`
- [X] T090 [P] [US3] [P] أنشئ `CMS/Support/CmsPersonalData.php`
- [X] T091 [P] [US3] [P] أنشئ `Courses/Support/CoursesPersonalData.php` — ⚠️ **مُضافةٌ في المراجعة**: كانت مستثناةً بحُجّة «تملك ما ينتجه المدرّس»، **و`FR-034` يطلب أن يستقبل المدرّسُ الخارجُ نسخةً من محتواه** وهو `courses`/`course_sections`/`course_chapters`/`lessons` ولا يُنشئها غيرُها — فـ`content_export_path` كان **بلا ما يناديه**
- [X] T092 [P] [US3] [P] أنشئ `Tenancy/Support/TenancyPersonalData.php` — ⚠️ **مُضافةٌ في المراجعة**: `invitations` تحمل `email` مجرَّداً و`accepted_by → users`، فبريدُ شخصٍ محويٍّ يبقى فيها وهو خرقُ `FR-020` و`FR-023`. والاستثناءُ الذي كان مكتوباً لها **سببُه غيرُ صحيح**، وهو أسوأُ من لا استثناء

### التصديرُ والتسليم

- [X] T093 [US3] أنشئ `Compliance/Support/ExportFieldAllowlist.php` — تحمل **ما لا تملكه وحدةٌ فقط**. ⚠️ **وفي الشجرة ستُّ قوائمَ لا ثلاث** (`AssessmentFieldAllowlist` · `MediaFieldAllowlist` · `PaymentFieldAllowlist` · `PublicFieldAllowlist` · `StudentBalanceAllowlist` · `TeacherFieldAllowlist`)، **أربعٌ منها تملكها الوحدات** — فقائمةٌ مركزيةٌ سابعةٌ جوابٌ ثانٍ يتباعد
- [X] T094 [US3] أنشئ `Compliance/Actions/ExecuteDataExport.php` تمشي على `PersonalDataRegistry` **وتكتب في الـzip أثناء التوليد** — ⚠️ `array` يمنع البثَّ: ثلاثَ عشرةَ مصفوفةً كاملةً ثمّ `addFromString` على تشفيرِ كلٍّ منها = ذروةٌ ضعفُ الحجم المُسَلسَل، والسقفُ `memory => 256` بـ`tries: 1`
- [X] T095 [US3] في `backend/app/Modules/Compliance/Actions/ExecuteDataExport.php`: أضف `README.md` عربياً داخل الـzip يشرح ما في كلّ ملفّ — `FR-016` يطلب «قابلةً للقراءة الآلية **والبشرية**»
- [X] T096 [US3] أنشئ `Compliance/Jobs/FulfilDataRequestJob.php` تستقبل **معرّفَ الطلب وحده** بـ`$queue = 'compliance'` و`$timeout` صريح — ⚠️ **لا تُمرَّر الحمولة**: Laravel يُسَلسِل وسائطَ المُنشئ والطابورُ Redis، فمصفوفةُ تصديرٍ **تحلّ في Redis** وعند الفشل في **`failed_jobs.payload`**، جدولٌ لا يمحوه شيء
- [X] T097 [US3] في `backend/app/Modules/Compliance/Jobs/FulfilDataRequestJob.php`: طالِبِ الطلبَ بتحديثٍ شرطيٍّ واحد `WHERE id = ? AND status = 'pending'` يوسم `last_attempt_at` — صفرُ صفوفٍ يعني «طُولِب سلفاً». **ومشغّلان ينفّذان طلباً واحداً** بلا هذا يُنتجان ملفَّي تصديرٍ وموقَّعَين لكلّ ما تعرفه المنصةُ عن قاصر. وأبداً `lockForUpdate()` — عديمُ الأثر على SQLite فاختبارٌ عليه لا يُثبت شيئاً عن MySQL
- [X] T098 [US3] أنشئ `Compliance/Jobs/RetryStalledDataRequestsJob.php` تكنس `status = processing` الأقدمَ من مهلةٍ بـ`last_attempt_at` — ⚠️ الطلبُ يُرسَل **مرّةً** بـ`tries: 1` **ولا شيءَ يكنس `processing`**، فيبقى للأبد ويمرّ `due_at` بلا أن يُخبَر أحد: عائلةُ `recording_status = 'ingesting'` بعينها
- [X] T099 [US3] [P] أنشئ `Compliance/Jobs/PruneExpiredExportsJob.php` — بلا ها يتراكم الأرشيفُ على القرص إلى الأبد، وهو «كلُّ ما تعرفه المنصةُ عن قاصرٍ في مكانٍ واحد»
- [ ] T100 [US3] أضف أسطرَ الجدولة الأربعةَ إلى `backend/routes/console.php` في **ساعاتٍ حرّة** — ⚠️ ٠٣:٣٠ · ٠٣:٤٥ · ٠٤:١٠ · ٠٤:٢٥ · ٠٤:٣٥ · ٠٤:٤٥ · ٠٤:٥٥ · ٠٥:١٥ مشغولةٌ كلُّها بأسبابٍ مكتوبةٍ عن الابتعاد عن المحوِ الجَمْعيّ
- [X] T101 [US3] أنشئ `Compliance/Http/Controllers/DataRequestController.php` بـ`GET`/`POST /privacy/requests` و`GET /privacy/requests/{request}/download` يُرجع **`302`** — ⚠️ **ولا مسارَ في الحمولة إطلاقاً**: نفسُ قاعدةِ `PlaybackGrantResource`، ومسارٌ في JSON رابطٌ يُنسَخ ويبقى. **و`throttle:data-rights` على `/download` أيضاً**
- [X] T102 [US3] [P] أنشئ شاشةَ «طلباتي وأصنافي» في `frontend/src/app/(app)/(shell)/privacy/`

### حرّاسُ US3

- [X] T103 [US3] [P] أنشئ `backend/tests/Feature/Compliance/PersonalDataContractCoverageTest.php` — **`SC-004`**: القائمتان مُشتقّتان من الهجرات **ويشمل جذرَ `database/migrations/`** حيث يعيش **`users`** نفسُه وكان خارجَ الاشتقاق، **ويُثبت تصديرَ الجداول لا تسجيلَ الوحدة** (‏جدولٌ يُضاف داخل وحدةٍ مسجَّلةٍ سلفاً غيرُ مرئيٍّ لفحصِ التسجيل — وهو الانحرافُ الذي يدّعي المعيارُ مسكَه)
- [X] T104 [US3] [P] أنشئ `backend/tests/Feature/Compliance/ExportCompletenessTest.php` — **`SC-005`** بـ**مساحتَي عملٍ** ومَحرمٍ ASCII
- [X] T105 [US3] [P] أنشئ `backend/tests/Feature/Compliance/ExportScaleTest.php` — **`SC-014`**: ٥٠٬٠٠٠ صفٍّ بلا قفلِ جدولٍ حيّ **وبلا تجاوزِ الذاكرة**. والذاكرةُ هي ما يفشل لا عددُ الاستعلامات
- [X] T106 [US3] [P] أنشئ `backend/tests/Feature/Compliance/StalledRequestSweepTest.php` — **`SC-021`**: قتلُ عاملٍ في المنتصف، والكنسةُ تُعيد الإرسال
- [X] T107 [US3] [P] أنشئ `backend/tests/Feature/Compliance/GuardianScopeTest.php` — وليٌّ مُنِح «الحضورَ» وحده **لا يستقبل** النتائجَ ولا المدفوعاتَ ولا التسجيلات؛ وصاحبُ البيان يستقبل الكلّ
- [X] T108 [US3] [P] أنشئ `backend/tests/Feature/Compliance/TeacherExportRefusalTest.php` — **مدرّسٌ له طالبٌ مسجَّلٌ نشطاً يُردّ بـ403** على تصدير ذلك الطالب. `RELATIONS_VIEW_STUDENT` **لا يفتح طلبَ حقوقٍ إطلاقاً**
- [X] T109 [US3] [P] أنشئ `backend/tests/Feature/Compliance/OpenRequestRaceTest.php` — طلبان في ثانيةٍ واحدةٍ يُنتجان صفّاً واحداً، وتنفيذان متزامنان ينتجان ملفاً واحداً

**Checkpoint**: `US1`–`US3` قابلةٌ للتسليم.

---

## Phase 6: US4 — حقُّ الحذف بحدوده (P4)

**الهدف**: تُحذَف البياناتُ الشخصيةُ ويبقى ما يلزم قانونياً بعد فصله عن هويته، بلا مسِّ إجماليّ.

**اختبارٌ مستقلّ**: اطلب حذفاً لطالبٍ له تاريخٌ ماليّ وتحقّق من إخفاء هويته وسلامة السجلّ.

- [X] T110 [US4] أنشئ هجرةَ `legal_holds` بفهرسَي `(subject_user_id, released_at)` و`(released_at)` — الثاني لأن الكنسةَ تسأل «كلُّ التعليقات السارية» **مرّةً لكلّ تشغيل** لا صفّاً صفّاً. و`whereNull('released_at')` **لا عمودٌ منطقيٌّ ثانٍ**: `is_active` بجانبه جوابان لسؤالٍ واحدٍ يتباعدان
- [X] T111 [US4] [P] أنشئ `Compliance/Models/LegalHold.php` و`Actions/{PlaceLegalHold,ReleaseLegalHold}.php` — و`PlaceLegalHold` **يُطالِب الطلبَ بتحديثٍ شرطيّ** (`WHERE status IN ('pending','processing')` ← `on_hold`) فتتسلسل الكتابتان على صفٍّ واحدٍ بدل أن تتسابقا
- [X] T112 [US4] أنشئ `Compliance/Support/Anonymiser.php` — **قيمٌ محايدةٌ ثابتةٌ ومعرّفٌ يُقطَع، ولا تجزئةَ لاسمٍ ولا لهاتف**: مجالُ رقمِ هاتفٍ قطريٍّ صغيرٌ بما يُعكَس بالقوة الغاشمة في دقائق
- [X] T113 [US4] أنشئ `Compliance/Actions/ExecuteDataErasure.php` تنادي `erase()` في حلقةٍ حتى `< $limit` — **ومعاملةٌ لكلّ دفعةٍ على الأكثر، ولكلّ شخصٍ في الإخفاء+قطعِ المؤشّر**: نصفُ إخفاءٍ ثغرةُ إعادةِ تعريف
- [X] T114 [US4] في `backend/app/Modules/Compliance/Actions/ExecuteDataErasure.php`: أعِدْ قراءةَ `legal_holds` **في رأس كلّ دفعة** — ⚠️ **للتعليق بابٌ ثالث**: وضعُه **أثناء** محوٍ جارٍ. `erase()` يمشي دقائق، فقراءةٌ عند بدء الوظيفة **قديمةٌ لبقيّة المشي** — والمحوُ لا يُعكَس
- [X] T115 [US4] أضف `erase()` إلى الثلاثةَ عشرَ ملفَّ `backend/app/Modules/*/Support/*PersonalData.php` — **`chunkById` للإخفاء** (‏الصفُّ يبقى فيلزم مؤشّر) **وحلقةُ `->limit(1000)->delete()` للحذف** (‏بيانٌ واحدٌ لكلّ دفعة، وهو ما يفعله `PruneOldNotificationsJob`). و`chunk` ممنوعٌ في الحالتَين
- [ ] T116 [US4] في `backend/app/Modules/*/Support/*PersonalData.php`: أضف `unsearchable()` **بصيغة الباني** `->where('workspace_id', …)->unsearchable()` لا صفّاً صفّاً — ⚠️ النموذجان الوحيدان `Searchable` لا يحملان بيانَ طالب، **فسطحُ هذا الشرط كلُّه خروجُ مدرّس**: ألفا سؤالٍ في بنكه = ألفا نداءٍ حاجزٍ داخل وظيفةٍ واحدة
- [X] T117 [US4] في `backend/app/Modules/Identity/Support/IdentityPersonalData.php`: **صفُّ `users` يُخفى ولا يُحذَف** — ⚠️ `workspaces.owner_user_id` بـ`cascadeOnDelete()` **وسائرُ الجداول تحمل `workspace_id` بلا مفتاحٍ خارجيّ**، فحذفُ مدرّسٍ يمحو مساحتَه ويترك كورساتَه وقيودَه تشير إلى معرّفٍ لا وجودَ له **مخفيّاً وراء `WorkspaceScope`**. والتسلسلُ **لا يُنشئ نموذجاً** فحارسُ الدفتر لا يعمل
- [X] T118 [US4] أضف عمودَ اسمِ عرضٍ **مُجمَّدٍ** إلى `certificates` واكتبه في `IssueCertificate`، **وأوقِفْ ضمَّ `users` في حمولة التصديق** — ⚠️ `GET /certificates/verify/{code}` عامٌّ بلا مصادقةٍ ويُرجع `student_name` بضمٍّ حيّ: تصفيرُ المعرّف يترك شهادةً تُصدَّق **لا أحد**، وحذفُ الصفّ يهدم اعتماداً استحقّه الطالبُ، وإخفاءُ `users.name` **يعيد كتابةَ إفادةٍ عامةٍ بصمت**. `ErasureMode::Retain`
- [X] T119 [US4] [P] أضف `throttle:public` إلى `GET /certificates/verify/{code}` في `Certificates/routes/api.php` — **بلا مُحدِّدِ معدّلٍ إطلاقاً** اليوم، ويؤكّد اسماً لكلّ من يحمل رمزاً
- [X] T120 [US4] أضف `DELETE`/`POST` للتعليقات والتنفيذِ والرفضِ إلى `Compliance/Http/Controllers/Manage/ComplianceRequestController.php` بالصلاحيات المنصّية — **وكلُّ قراءةٍ تُعلن `withoutWorkspaceScope()`، والتجاوزُ لكلّ نموذجٍ على حِدة**: `->with('order')` يشغّل النطاقَ داخل استعلامِ العلاقة
- [ ] T121 [US4] [P] أنشئ لوحةَ موظّف المنصة في `frontend/src/app/(app)/(shell)/manage/compliance/`
- [X] T122 [US4] [P] أنشئ `backend/tests/Feature/Compliance/ErasureCompletenessTest.php` — **`SC-006`** ويشمل تأكيدَ `unsearchable()`: `SCOUT_DRIVER=null` فلا اختبارَ يرى الفهرسَ، **والصفُّ الباقي فيه هو `FR-023`**
- [X] T123 [US4] [P] أنشئ `backend/tests/Feature/Compliance/LedgerIntegrityAfterErasureTest.php` — **`SC-007`** **بحالةٍ لصاحبِ مساحةِ عملٍ لا لطالبٍ فقط**: التسلسلُ يعمل في الاختبارات (`DB_FOREIGN_KEYS` افتراضُه صحيح) فهو **قابلٌ للقياس** وكان بلا قياس
- [X] T124 [US4] [P] أنشئ `backend/tests/Feature/Compliance/AnonymisationIrreversibilityTest.php` — **`SC-008`**: يحاول إعادةَ الربطِ من **بقيةِ بياناتِ النظام** لا فحصَ عمودٍ واحد
- [X] T125 [US4] [P] أنشئ `backend/tests/Feature/Compliance/LegalHoldTest.php` — **`SC-009`** بالأبواب **الثلاثة**: بالطلب · بالكنسة · **وتعليقٌ يُوضَع بعد الدفعة الأولى** فتتوقّف الثانيةُ ويصير الطلبُ `on_hold`
- [X] T126 [US4] [P] أنشئ `backend/tests/Feature/Compliance/PlatformReadScopeTest.php` — **بمساحتَي عملٍ**: `WorkspaceContext::id()` يرتدّ إلى `last_workspace_id` **لمدير المنصة أيضاً**، فقراءةٌ متروكةٌ في النطاق **تنجح على تجربةٍ بمساحةٍ واحدة**

**Checkpoint**: `US1`–`US4` قابلةٌ للتسليم.

---

## Phase 7: US5 — الاحتفاظُ والحذفُ التلقائي (P5)

**الهدف**: لكلّ صنفٍ مدّةٌ معلنةٌ، وعند انقضائها يُعالَج آلياً بلا أن يطلب أحد.

**اختبارٌ مستقلّ**: قدِّمْ ساعةَ النظام تجاوزاً لمدّةِ صنفٍ وتحقّق من معالجته آلياً.

- [X] T127 [US5] أنشئ هجرةَ `retention_sweep_runs` في `backend/app/Modules/Compliance/Database/Migrations/` على شكل `credit_reconciliation_runs`: `ran_at` مفهرسٌ · أعدادٌ `unsignedInteger default(0)` · `findings` json **محدودُ الحجم** بجانب `findings_count` صادق — ⚠️ `FR-031` يطلب سجلَّ كلّ تنفيذٍ **ولم يكن له جدول**، بينما كانت الخطةُ تحذّر من عرضِ عمودٍ لا وجودَ له. والفصلُ سببُه: **حتى يُفرَّق تشغيلٌ لم يجد شيئاً من تشغيلٍ لم ينظر**
- [ ] T128 [US5] أضف `expire()` إلى الثلاثةَ عشرَ ملفَّ `backend/app/Modules/*/Support/*PersonalData.php` — ⚠️ **الدالّةُ التي لولاها لا كنسةَ إطلاقاً**: مُسنَدُ نسخةٍ أولى كان عموداً لا وجودَ له و`erase()` يستقبل شخصاً لا حدَّ عمرٍ. **والحدُّ يُحسَب تاريخاً في PHP ويُقارَن نصّاً** لا بـ`whereDate()` الذي يُهدر الفهرس
- [ ] T129 [US5] أضف فهرسَ `(created_at)` في `backend/app/Modules/*/Database/Migrations/` لـ**كلّ وحدةٍ** لما تكنسه `expire()`: `attendances` · `exam_answers` · `exam_attempts` · `attempt_items` · `enrollments` · `lesson_progress` · `lesson_progress_history` — ⚠️ فهرسُ `attendances` عمودُه القائدُ `student_user_id` **فلا يُستعمل** لمُسنَدِ `created_at`، والأربعةُ الوسطى **بلا فهرسِ `created_at` إطلاقاً**. **وكلُّ فهرسٍ في هجرةِ وحدتِه** — وهو ما تُتيحه `expire()`
- [ ] T130 [US5] أنشئ `Compliance/Jobs/RunRetentionSweepJob.php` تقرأ مددَ `data_categories` وتنادي `expire()` في حلقة، وتكتب صفَّ `retention_sweep_runs`
- [ ] T131 [US5] في `backend/app/Modules/Compliance/Jobs/RunRetentionSweepJob.php`: استعمِلْ `WithoutOverlapping` **بـ`expireAfter()` صريح** — ⚠️ **`withoutOverlapping()` على `Schedule::job()` يحرس الإرسالَ لا التشغيل**: القفلُ يُؤخَذ ويُحرَّر حول `dispatchToQueue()` بالمللي ثانية، فكنسةُ ليلةٍ ما زالت تمشي حين تبدأ ليلةُ الغد تعمل نسختان على نفس الصفوف. **والتصحيحُ ليس نقلَه إلى وسيطِ الوظيفة** لأن قفلَ الوسيط **لا ينتهي** فعاملٌ مقتولٌ يُسكت الكنسةَ للأبد: **الانتهاءُ هو الجزءُ الحامل**
- [ ] T132 [US5] في `backend/app/Modules/Compliance/Jobs/RunRetentionSweepJob.php`: أطلِقْ `CourseStructureChanged` **مرّةً لكلّ كورسٍ** لا لكلّ درسٍ مُنقضٍ — ⚠️ ٥٠ درساً في كورسٍ واحدٍ يُطلق ٥٠ إعادةَ مزامنةٍ كاملةً لنفس مجموعة التسجيلات، **وهذا هو ما يهدّد مهلةَ الـ٩٠٠ ثانيةٍ لا عمليّاتُ الحذف**
- [ ] T133 [US5] [P] أضف عمودَ `archived_at` في `backend/app/Modules/*/Database/Migrations/` لكلّ صنفٍ سلوكُه `archive` — ⚠️ بلا عمودِ وسمٍ يُعاد أرشفةُ الصفِّ **كلَّ ليلةٍ للأبد**، ويُعاد تسجيلُه وعدُّه في «كم صفّاً عالجتُ»
- [ ] T134 [US5] أنشئ `Compliance/Jobs/TransferDataOwnershipJob.php` بمُسنَدِ `date_of_birth <= today − 18y` **و`whereNull('ownership_transferred_at')`**، **يوسم قبل الإرسال** — ⚠️ الاسمُ كان `ExpireDataOwnershipJob` وهو **عكسُ ما يفعل**: لا شيءَ ينقضي، الملكيةُ **تنتقل**. وفي مستودعٍ أعاد تسميةَ `Session` إلى `ClassSession` لهذا بعينه
- [ ] T135 [US5] في هجرةِ `T040` داخل `backend/app/Modules/Identity/Database/Migrations/`: أضف فهرسَ `(ownership_transferred_at, date_of_birth)` — ⚠️ بلا عمود الوسم يُعاد إشعارُ `FR-009` **كلَّ ليلةٍ للأبد** إلى كلّ بالغٍ على المنصّة: عطلُ `notified_dormant_at` بعينه، وقاعدتُه **يُوسَم قبل الإرسال** لأن كلفتَي الترتيبَين غيرُ متكافئتَين
- [ ] T136 [US5] في `backend/app/Modules/Compliance/Jobs/TransferDataOwnershipJob.php`: وزِّعِ التواريخَ المُقدَّرةَ على السنة بمعرّف المستخدم — ⚠️ عمرٌ صحيحٌ مُحوَّلٌ إلى تاريخٍ يرتكز على يوم التحويل، **فمن كان «١٧» كلُّهم يبلغون في ليلةٍ واحدة** على طابورٍ بـ`maxProcesses: 1`
- [ ] T137 [US5] انقُلْ جسمَ `PruneOldNotificationsJob` إلى `expire()` صنفِ الإشعارات **واحذِفْ سطرَ جدولته في ٠٣:٣٠** — ⚠️ يقرأ `config('notifications.retention_days')`، أي **مالكٌ ثانٍ لنفس المدّة** وخرقٌ لـ`FR-031أ` شُحن قبل وجود المتطلَّب. ومالكان لمدّةٍ واحدةٍ يعني أن ما يحرّره المشغّلُ من اللوحة **ليس ما يحذف**
- [ ] T138 [US5] في `backend/app/Modules/Media/Support/MediaPersonalData.php`: **الحذفُ عند المزوّد أوّلاً ثمّ الصفّ** — العكسُ يُيتّم فيديو مفوترٌ بلا ما يسمّيه، و`404` عند المزوّد **مجّانيٌّ عن قصد** فإعادةُ الكنسة لا تكلّف شيئاً
- [ ] T139 [US5] [P] أنشئ `backend/tests/Feature/Compliance/RetentionSweepIdempotencyTest.php` — **`SC-010`** بتشغيلَين، **والتثبيتةُ تشمل صنفاً سلوكُه `archive`**. والثابتُ **ثلاثيّ**: صفوفُ بياناتٍ متطابقة · **صفُّ تشغيلٍ واحدٌ بالضبط** (‏فالسجلُّ append-only و«حالتان متطابقتان» خاطئةٌ له) · صفر أثرٍ مُهلِك
- [ ] T140 [US5] [P] أنشئ `backend/tests/Feature/Compliance/RetentionRecordingProgressTest.php` — ⚠️ **`SC-016`**: يُنقضي تسجيلاً **كان عنصراً محسوباً** لطالبٍ أتمّ ما سواه، ويتحقّق أن نسبتَه ١٠٠٪ **وأن الشهادةَ صدرت**. اختبارٌ يفحص حذفَ الأصلِ وحده **يمرّ على العطل بلا أن يراه**
- [ ] T141 [US5] [P] أنشئ `backend/tests/Feature/Compliance/OwnershipTransferTest.php` — إشعارٌ **واحدٌ** لا واحدٌ كلَّ ليلة، وانتقالٌ بلا انقطاع خدمة

**Checkpoint**: `US1`–`US5` قابلةٌ للتسليم.

---

## Phase 8: US6 — خروجُ المدرّس ونقلُ مسؤوليته (P6)

**الهدف**: مستحقاتُه ومصيرُ طلابه ومحتواه تُحسَم في مسارٍ واحدٍ معلَن.

**اختبارٌ مستقلّ**: أخرِجْ مدرّساً له طلابٌ ومستحقاتٌ ومحتوًى وتحقّق من حسم الثلاثة.

- [X] T142 [US6] أنشئ هجرةَ `teacher_offboardings` في `backend/app/Modules/Compliance/Database/Migrations/` بفهرسَي `(workspace_id)` و`(status, created_at)`
- [X] T143 [US6] أنشئ `Compliance/Models/TeacherOffboarding.php` **مع `BelongsToWorkspace`** — ⚠️ **الإعلانُ مطلوبٌ لا مُستنتَج**: الجسران المشحونان (`Enrollment` و`SessionBooking`) يستخدمانه، ونسخةٌ أولى قالت «صفر نموذجٍ مملوكٍ لمساحة عملٍ فلا حالةَ عزلٍ جديدة» — خطأٌ ناتجٌ عن عدم الإعلان
- [X] T144 [US6] أضف حالةً لـ`teacher_offboardings` إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — **في نفس الـPR** بحكم الدستور
- [ ] T145 [US6] أنشئ `Settlement/Support/EloquentSettlementClearance.php` واربطه في مزوّدها — **والمالُ عددٌ صحيحٌ بالوحدة الصغرى**: صبُّ `decimal:2` يُرجع **نصّاً** فيمرّ كلُّ جمعٍ بعائم، مقبولٌ لمجموع طلبٍ لا على ما يقرّر راتباً
- [ ] T146 [US6] أنشئ `Compliance/Actions/RequestTeacherOffboarding.php` و`ExecuteTeacherOffboarding.php` — **والإتمامُ تحديثٌ شرطيٌّ واحد** `WHERE status = 'notice_period' AND settlement_cleared_at IS NOT NULL`، فمشغّلان يمرّان معاً بلا ذلك وآثارُ `FR-037` **لا تُعكَس**
- [ ] T147 [US6] أطلِقْ `TeacherOffboardingRequested` و`TeacherOffboardingCompleted` من `backend/app/Modules/Compliance/Actions/RequestTeacherOffboarding.php` و`ExecuteTeacherOffboarding.php` — ⚠️ **بدل Action واحدٍ يعرف خمسةَ سياقات**، وهو ما يمنعه المبدأ III
- [ ] T148 [US6] [P] اشترِكْ في `backend/app/Modules/Marketplace/Listeners/UnlistDepartedTeacher.php` (مُسجَّلاً في مزوّدها) لإيقاف الإدراج العامّ فوراً (`FR-035`) — و`is_publicly_listed` **مُشتقٌّ** فيتغيّر وحده، **و`Enrollment::accessTo()` لا يتغيّر**: الوصولُ المدفوعُ يبقى للمدّة المتبقّية. الفصلُ قائمٌ سلفاً في الكود **وهو سببُ كونِ المتطلَّب قابلاً للتنفيذ بلا عملٍ جديدٍ في Learning**
- [ ] T149 [US6] [P] اشترِكْ في `backend/app/Modules/Tenancy/Listeners/RevokeWorkspaceAccess.php` (مُسجَّلاً في مزوّدها) لإنهاء العضويات وصلاحياتِ المساعدين **في مساحةٍ واحدةٍ فقط** (`FR-037`) — مساعدٌ يعمل عند مدرّسٍ آخرَ يبقى كما هو
- [ ] T150 [US6] [P] اشترِكْ في `backend/app/Modules/Identity/Listeners/RevokeTeacherSessions.php` (مُسجَّلاً في مزوّدها) لإلغاء الرموز والجلسات
- [ ] T151 [US6] [P] اشترِكْ في `backend/app/Modules/Media/Listeners/SetDepartedTeacherRetention.php` (مُسجَّلاً في مزوّدها) لمدّةِ احتفاظِ التسجيلات (`FR-036`) — ⚠️ **لا يُعبَّر عنها بجدول المدد**: القاعدةُ لكلّ صنفٍ رقمٌ واحدٌ وسلوكٌ واحد، والمتطلَّبُ «تراعي حقوقَ الطلاب **الظاهرين فيها**» أي حقوقَ أشخاصٍ غيرِ صاحبِ الطلب. فتُشتقّ من **آخرِ حقِّ وصولٍ لمقعدٍ مدفوع** لا من تاريخ الحصة
- [ ] T152 [US6] [P] اشترِكْ في `backend/app/Modules/Notifications/Listeners/NotifyOffboardingStudents.php` (مُسجَّلاً في مزوّدها) لإخطار الطلاب وأوليائهم بمهلةٍ معلَنة (`FR-033`)
- [ ] T153 [US6] أضف وضعَ **تصديرِ محتوى المدرّس** إلى `backend/app/Modules/Courses/Support/CoursesPersonalData.php` واكتب `content_export_path` (`FR-034`)
- [ ] T154 [US6] أنشئ `POST`/`GET /teaching/offboarding` و`GET /teaching/offboarding/content` في `Compliance/Http/Controllers/TeachingOffboardingController.php` — ⚠️ **`US6` كانت بلا نقطةٍ للمدرّس** والقصةُ «مدرّسٌ يطلب الخروج». والتنفيذُ يبقى منصّياً: المدرّسُ **يطلب** ولا يُتِمّ
- [ ] T155 [US6] [P] أنشئ شاشةَ الخروج في `frontend/src/app/(app)/(shell)/teaching/offboarding/`
- [ ] T156 [US6] [P] أنشئ `backend/tests/Feature/Compliance/OffboardingSettlementGateTest.php` — **`SC-013`**: صفر خروجٍ مكتملٍ قبل الحسم، وصفر صلاحيةٍ باقيةٍ له أو لمساعديه، **وصلاحياتُ مساعدِه عند مدرّسٍ آخرَ سليمة**
- [ ] T157 [US6] [P] أنشئ `backend/tests/Feature/Compliance/OffboardingContentAccessTest.php` — الإدراجُ العامُّ يتوقّف **والوصولُ المدفوعُ يبقى** للمدّة المتبقّية

**Checkpoint**: القصصُ الستُّ كاملة.

---

## Phase 9: Cross-Cutting — الإبلاغُ عن الحوادث والتوثيقُ والحرّاسُ العامّة

> `FR-040` **بلا قصّةِ مستخدم** — فهو مسارٌ للمنصّة لا لشخصٍ في السبيك. **ويُبنى ولا يُؤجَّل.**

- [X] T158 [P] أنشئ هجرةَ `breach_reports` في `backend/app/Modules/Compliance/Database/Migrations/` بفهرسِ `(status, created_at)` و`reported_by_user_id` **قابلاً للعدم**
- [X] T159 [P] أنشئ `Compliance/Models/BreachReport.php` و`Enums/BreachStatus.php` و`Actions/{ReportBreach,AdvanceBreachReport}.php`
- [ ] T160 أنشئ `POST /privacy/breach-reports` **عامّاً بلا مصادقة** بـ`throttle:public` — ⚠️ «مسارٌ **معلَن**» يعني أن باحثاً أمنيّاً من الخارج يستعمله، وأشهرُ التسريباتِ يُبلِّغ عنها **من ليس مستخدماً**. وثلاثةُ قيودٍ تجعلها آمنة: الحدُّ · **لا تُرجع شيئاً** غيرَ تأكيدِ الاستلام فلا تصير عرّافاً · وحقولُ نطاقِ الحادث (‏الأصنافُ والعدد) **ليست في الطلب العامّ**
- [ ] T161 [P] أنشئ `GET`/`PATCH /compliance/breach-reports` بـ`compliance.breaches.manage`
- [ ] T162 [P] أنشئ `backend/tests/Feature/Compliance/BreachReportTest.php` — **`SC-020`**: بلاغٌ من **غير مستخدَم** يُقبَل ولا يُرجع ما يدلّ على وجودِ حسابٍ أو جدول
- [ ] T163 [P] أنشئ `backend/tests/Feature/Compliance/QueryBudgetTest.php` — **`SC-022`** **بضِعفِ حجمِ التثبيتة** حتى «لا يختبئ N+1 داخل السماح»، ويغطّي `GET /compliance/requests` (‏فحصُ التعليقِ صفّاً صفّاً هو الأسوأ: ٥٠ صفّاً = ٥٠ استعلاماً؛ والشكلُ الجَمْعيُّ `whereIn` واحدٌ يُوسَم على المجموعة كما `WithholdingReader::stamp()`)
- [ ] T164 [P] أنشئ `backend/tests/Feature/Compliance/LogHygieneTest.php` — **`SC-012`**: صفر بياناتٍ شخصيةٍ في السجلّات ورسائل الأخطاء، **ويشمل `failed_jobs.payload`**
- [ ] T165 [P] أنشئ `backend/tests/Feature/Compliance/ProcessorAllowlistTest.php` — **`SC-011`**: اختبارُ عقدٍ لكلّ مزوّد، صفر إرسالٍ لمعالِجٍ غيرِ مُدرَج
- [ ] T166 [P] حدِّثْ `docs/README.md` بجدولِ الوحدةِ ونقاطِ النهايةِ والصلاحياتِ الخمس — الدستورُ يُلزم به لكلّ تعديلٍ يمسّ الوحداتَ أو النقاطَ أو الأذونات، **وهجرةُ `terms_consents` نفسُها تسمّيه موضعَ قائمةِ استثناءات المحو**
- [ ] T167 [P] حدِّثْ `docs/erd.md` بالجداول الستّةِ الجديدةِ والتعديلاتِ الثلاثة
- [ ] T168 [P] حدِّثْ `CLAUDE.md` **و**`AGENTS.md` معاً بالقواعد التشغيليةِ الجديدة — الدستورُ يُلزم بالاثنين معاً، وقاعدةٌ في أحدهما وحده هي القاعدةُ التي يخالفها القارئُ الآخر
- [ ] T169 [P] أضف بنديَ نشرٍ إلى `docs/deployment.md`: **`TrustProxies`** (‏بلا ضبطٍ يُرجع `$request->ip()` عنوانَ موازِن الحمل — **العنوانَ نفسَه للجميع** — في الصفّ الذي يوجد ليُعتمَد عليه في نزاع، فيصير السجلُّ **مُضلِّلاً** لا ناقصاً) و**`SCOUT_QUEUE=true`**
- [ ] T170 حدِّثْ `docs/roadmap.md`: علّمْ ٠١٣ مُنفَّذةً، **واحذِفْ سطرَ «مدخلُها `/speckit-clarify`»** الذي صار قديماً
- [ ] T171 شغِّلِ البوّاباتِ الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` **و`npm test`** — بلا `@phpstan-ignore` وبلا baseline جديد
- [ ] T172 نفِّذِ الخطواتَ اليدويةَ في [`quickstart.md`](./quickstart.md) §ب وسجِّلِ الخمسةَ في §ج في جدول Deferred Verification

---

## Dependencies

```
Phase 1 (Setup)  →  Phase 2 (Foundational)  →  ┌ US1 (P1) ─┬→ US3 (P3) → US4 (P4) → US5 (P5)
                                               └ US2 (P2) ─┘                    ↘
                                                                                  US6 (P6)
                                                                                     ↓
                                                                              Phase 9
```

- **US1 تحجب US3–US6**: لا حقَّ بياناتٍ قبل موافقةٍ مشروعةٍ على الجمع.
- **US2 مستقلّةٌ عن US3–US6** ويمكن تسليمُها بعد US1 مباشرةً.
- **US4 تعتمد على US3**: `erase()` تُضاف إلى نفس الثلاثةَ عشرَ ملفّاً التي أنشأتها US3.
- **US5 تعتمد على US4**: `Anonymiser` و`ErasureMode` يُستعملان في سلوك الانقضاء.
- **US6 تعتمد على US3** (‏تصديرُ محتوى المدرّس) **وعلى US5** (‏مدّةُ احتفاظِ تسجيلاته).
- **Phase 9 مستقلّةٌ تماماً** ويمكن تشغيلُها بالتوازي مع US3 وما بعدها.

## Parallel Opportunities

**٤٣ مهمّةً من ١٧٢ مُعلَّمةٌ `[P]`** — ملفّاتٌ مختلفةٌ بلا تبعيّة.

| الموضع | ما يتوازى |
|---|---|
| Phase 1 | `T004`–`T012` — تسعُ مهامٍّ في تسعةِ ملفّاتٍ مختلفة |
| Phase 2 | `T014` · `T017`–`T019` · `T022`–`T023` · `T025`–`T026` · `T028` · `T032` |
| US1 | الشاشاتُ `T056`–`T060` · والحرّاسُ `T061`–`T069` (‏تسعةُ ملفّاتِ اختبارٍ مستقلّة) |
| US2 | `T070`–`T073` كلُّها |
| **US3** | **`T080`–`T092` — ثلاثةَ عشرَ ملفّاً في ثلاثةَ عشرَ وحدةً، أوسعُ توازٍ في المرحلة** · والحرّاسُ `T103`–`T109` |
| US4 | `T119` · `T121`–`T126` |
| US5 | `T133` · `T139`–`T141` |
| US6 | `T148`–`T152` (‏خمسةُ مشتركين مستقلّين) · `T155`–`T157` |
| Phase 9 | `T158`–`T169` — اثنتا عشرةَ مهمّةً |

## Implementation Strategy

**MVP = `US1` وحدها** (`T001`–`T069`). تُسلَّم قابلةً للاستعمال: قاصرٌ لا يُفعَّل حسابُه بلا موافقةِ
وليٍّ مسجَّلةٍ بنسختها ووقتها وعنوانها وأصنافها، ووليٌّ يقرأ ما يُجمَع ولماذا ويسحب الاختياريّ.
**وهي وحدها تُغلق `SC-001` · `SC-002` · `SC-003` · `SC-017` · `SC-018` · `SC-019`** — ستّةٌ من
اثنين وعشرين.

ثم بالترتيب: `US2` (‏سطران فقط) ← `US3` (‏أكبرُ زيادة) ← `US4` ← `US5` ← `US6`.
و**Phase 9 تُشغَّل بالتوازي** متى شاء المُنفِّذ، فلا شيءَ فيها يعتمد على قصّة.

⚠️ **ولا تُدمَج قصّةٌ بلا حرّاسها**: ستُّ معاييرَ في هذه المرحلة **تصف عيباً لا يظهر إلّا باختبارٍ
مصاغٍ بشكلٍ معيّن** (`SC-004` · `SC-006` · `SC-010` · `SC-016` · `SC-018` · `SC-022`) — واختبارٌ
مصاغٌ على الشكل الطبيعيّ يمرّ **على** العيب لا عليه.
