# Tasks — `030-guardian-link-accept`

**Input**: `plan.md` · `research.md` (R1…R15) · `data-model.md` · `contracts/http.md`

**⛔ ثلاثُ مهامٍّ تسبقُ كلَّ شيءٍ لأنّها عيوبٌ تُشحَنُ صامتة**: `T004` (`$fillable`) ·
`T010` (حارسُ الطالب) · `T031` (محرِّرُ الصلاحيّات).

---

## Phase 1 · Setup & المقياسُ القبليّ

- [X] T001 قِسْ حالاتِ الصفوفِ قبلَ أيِّ هجرة: `php artisan tinker --execute="print_r(DB::table('parent_student_relations')->selectRaw('status, count(*) n')->groupBy('status')->pluck('n','status')->all());"` — احفظِ الناتجَ لمقارنةِ `SC-007`. **لا `migrate:fresh`.**

## Phase 2 · Foundational (تحجبُ كلَّ القصص)

- [X] T002 هجرةٌ في `backend/app/Modules/Identity/Database/Migrations/2026_09_09_000100_add_decision_to_parent_student_relations.php` — **إغلاقان**: (١) `unique(['guardian_user_id','student_user_id','live_slot'], 'psr_live_unique')` ثمّ `dropUnique(['guardian_user_id','student_user_id'])`؛ (٢) الأعمدةُ الثلاثةُ معاً. `live_slot` قبلَ الفهرسِ في ترتيبِ الكتابة. **لا `DB::table()->update()` في أيِّ سطر.**
- [X] T003 في الهجرةِ نفسِها: `down()` يقولُ فوقَ نفسِه إنّه **لا يستطيعُ استعادةَ الفهرسِ القديم** (زوجانِ ملغَيانِ قد يوجدان) — سابقةُ `_000600_drop_exam_id_from_questions`. ووثِّقْ أنّ الهجرةَ لا تكتبُ صفّاً (`FR-011`).
- [X] **T004** ⛔ `backend/app/Modules/Identity/Models/ParentStudentRelation.php`: `requested_by_user_id` و`accepted_at` في `$fillable`؛ `live_slot` **خارجَه** بتعليقٍ يقولُ لماذا؛ `'accepted_at' => 'datetime'` في `casts()`؛ ثلاثةُ سطورِ `@property`.
- [X] T005 [P] `decidableBy(User): bool` على النموذجِ نفسِه — الشروطُ الأربعةُ كما في `data-model.md`، بتعليقٍ يقولُ إنّها **الهجاءُ الوحيد** الذي يقرؤُه البابُ والزرُّ والإجراء.
- [X] T006 [P] `backend/database/factories/Modules/Identity/ParentStudentRelationFactory.php`: `requested_by_user_id` افتراضُه **الوصيّ** — لا `null`، وإلّا قاسَ كلُّ اختبارِ قبولٍ فرعَ الـ٤٠٣ صامتاً.
- [X] T007 `backend/app/Modules/Identity/Actions/RevokeRelation.php`: يُضافُ `'live_slot' => $relation->getKey()` إلى `forceFill` القائم. **بلا تغييرِ بصمة.**

## Phase 3 · US1 — يبتُّ الطرفُ الذي لم يطلب (P1)

**اختبارٌ مستقلّ**: `LinkGuardian` ← `accept` ← `childrenOf()` تُرجِعُ الطالبَ، **بلا `status => 'active'` في أيِّ تركيبة**.

- [X] T008 `backend/app/Modules/Identity/Actions/LinkGuardian.php`: `'requested_by_user_id' => $guardian->getKey()` في `create()`.
- [X] T009 نفسُه: `alreadyLinked()` ⇒ `whereIn('status', [Pending, Active])`، بتعليقٍ يقولُ إنّ الفهرسَ هو الحارسُ وهذه الرسالةُ اللطيفة.
- [X] **T010** ⛔ نفسُه: يُرفَضُ `student_uuid` من فاعلٍ `platform_role !== PlatformRole::Parent` **بجملةِ `resolveStudent` نفسِها**. تعليقٌ يسمّي السلسلة (`R11`).
- [X] T011 `backend/app/Modules/Identity/Actions/RegisterStudent.php`: `'requested_by_user_id' => $student->getKey()` في `firstOrCreate`؛ ومرشِّحُ الحالةِ `[Pending, Active]` على شرطِ المطابقة (`R15`)؛ و`actionUrl: '/family'` على الإشعارِ القائم؛ **وتصحيحُ التعليقِ البائتِ** «Empty, and filled on acceptance» الذي يناقضُه سطرُه التالي.
- [X] T012 `backend/app/Modules/Identity/Actions/AcceptRelation.php` (جديد): الرفضُ **قبلَ** المطالبة (`decidableBy`)؛ ثمّ `query()->whereKey()->where('status','pending')->update([...])`؛ صفرُ صفوفٍ ⇒ `refresh()` ⇒ `active` يعودُ · `revoked` يرمي «انتهى هذا الطلب.». **لا `forceFill()->save()` ولا `lockForUpdate()`** — والسببانِ مكتوبانِ فوقَهما.
- [X] T013 `backend/app/Modules/Identity/Policies/ParentStudentRelationPolicy.php`: `accept()` ⇒ `$relation->decidableBy($user)`.
- [X] T014 `backend/app/Modules/Identity/Http/Controllers/FamilyController.php`: `accept()` — `firstOrFail` بالـuuid، `abort_unless(can('accept'))`، الإجراء، ثمّ `->load(['guardian','student:id,uuid'])`.
- [X] T015 نفسُه: `->load(['guardian','student:id,uuid'])` على `store` و`update` و`destroy` — ثلاثةُ ردودٍ تُسقِطُ اسمَ الوصيِّ بصمتٍ اليوم.
- [X] T016 `backend/app/Modules/Identity/routes/api.php`: `POST /family/relations/{uuid}/accept`؛ و`throttle:family-link` على `POST /family/relations` **وحدَه**.
- [X] T017 `backend/app/Providers/AppServiceProvider.php`: معدَّلُ `family-link` بحدَّين — `perMinute(3)` على `pair:{user}|{student_uuid}` و`perHour(20)` على `user:{user}`. **بلا احتياطٍ إلى `student_name`.**

## Phase 4 · US1 — الإشعارات (P1)

- [X] T018 [P] `NotificationType.php`: `GuardianLinkRequested` و`GuardianLinkDecided` + تسميتانِ عربيّتان. **خارجَ `targetsGuardians()`** بتعليقٍ يقولُ لماذا (العددُ الصريحُ **٢٦**).
- [X] T019 [P] `NotificationCategory.php`: النوعانِ بجوارِ `GuardianConsentRequired` — **وإلّا احمرَّ `NotificationCategoryTest`**.
- [X] T020 [P] `backend/database/seeders/NotificationTemplateSeeder.php`: صفّانِ `pending` (لا `approved`).
- [X] T021 هجرةُ ردمٍ `…_seed_guardian_link_templates.php` تُنادي `seedMissing()` — خامسُ تطبيقٍ للآليّة؛ بلا هجرةٍ يُسقَطُ الإشعارُ بصمتٍ على قاعدةٍ قائمة.
- [X] T022 الإرسالُ **مباشرةً** من `LinkGuardian` و`AcceptRelation` عبرَ `DispatchNotification` المحقونِ — **لا حدثَ ولا مُصغٍ** (`R8`). كلٌّ بـ`actionUrl: '/family'` و`subject`.
- [X] T023 [P] `frontend/src/lib/notification-links.test.ts`: `/family` في قائمةِ الوجهات.

## Phase 5 · US2 — الطالبُ يرى من يتابعُه (P1)

- [X] T024 `ParentStudentRelationResource.php`: `viewer_side` (`guardian`/`student`/**`null`** للمدرّس) · `can_decide` ⇒ `decidableBy()` **مباشرةً لا عبرَ `Gate`** · `accepted_at`.
- [X] T025 [P] `frontend/src/lib/notifications.ts`: `family.accept(uuid)` مكتوبةً `api.post<GuardianRelation>` — **لا `{data:…}`**؛ والحقولُ الثلاثةُ؛ وحقلا ٠٢٢ البائتان؛ وتصحيحُ «خمس صلاحيّات» وهي ست.
- [X] T026 [P] `frontend/src/lib/types.ts`: الحقولُ نفسُها على `ChildLink` — النسخةُ الثانيةُ لنفسِ الحمولة.
- [X] T027 `frontend/src/app/(app)/(shell)/family/page.tsx`: قسمانِ حسبَ `viewer_side` — «من أتابعهم» و«**من يتابعني**» — والثاني يرسمُ **اسمَ الوصيِّ** وصفتَه وقائمةَ ما يطّلعُ عليه.
- [X] T028 نفسُه: مكوِّنُ `RelationRow` واحدٌ للجهتَين (الصفحةُ تتجاوزُ حدَّ المكوِّنِ الإلهِ بدونِه).
- [X] T029 نفسُه: `ConfirmButton` بدلَ `Button` على القطعِ والرفض — **لا انتقالَ خارجَ `revoked`**، وإعادةُ الطلبِ صفٌّ جديدٌ يحتاجُ قبولاً ثانياً.
- [X] T030 نفسُه: `<Badge tone="warning">{relation.status_label}</Badge>` — **لا `StatusBadge`**: `labels.ts` يترجمُ `pending` إلى «بانتظار الدفع».

## Phase 6 · US3 — التضييقُ من الطرفَينِ والتوسيعُ من طرف (P2)

- [X] **T031** ⛔ `frontend/src/app/(app)/(shell)/family/page.tsx`: محرِّرُ صلاحيّاتٍ يستدعي `family.updatePermissions` — **المسارُ صفرُ مستدعين اليوم**، وبدونِه تُشحَنُ US3 حارساً على بابٍ مغلَق.
- [X] T032 `backend/app/Modules/Identity/Actions/UpdateRelationPermissions.php`: `handle(User $actor, …)` — (١) الطرفيّةُ (توقفُ الـsuper admin الذي تمرِّرُه `Gate::before`)، (٢) الاتّجاهُ (`array_diff`). جملتانِ عربيّتان.
- [X] T033 `FamilyController::update` يمرِّرُ الفاعل. **مستدعٍ واحدٌ في الشجرةِ كلِّها** — مقيس.

## Phase 7 · الاختبارات

- [X] T034 `backend/tests/Feature/Identity/GuardianLinkAcceptTest.php` (جديد): `SC-001`+`SC-002` من طرفٍ إلى طرفٍ **بلا `status => 'active'`**، منتهيةً بـ`childrenOf()` و`PurchaseBeneficiary::resolve()`. `last_workspace_id` يبقى `NULL`.
- [X] T035 نفسُه: `SC-003` بأربعةِ فاعلين — صاحبُ الطلبِ · مدرّسٌ بتسجيلٍ نشط · طالبٌ أجنبيّ · **super admin**. بلا الأخيرِ يقيسُ الملفُّ فرعاً آخر.
- [X] T036 نفسُه: `SC-006` (قبولٌ مكرَّرٌ لا يُحرِّكُ `accepted_at`) · `4أ` (صفٌّ مقطوعٌ ⇒ ٤٢٢) · `3ب` (`requested_by` فارغٌ ⇒ رفض).
- [X] T037 نفسُه: الاتّجاهُ الثاني — صفُّ `RegisterStudent` يقبلُه **الوصيّ**، ويُرفَضُ من الطالبِ صاحبِ الطلب.
- [X] T038 [P] `backend/tests/Feature/Identity/GuardianLinkRequestGuardTest.php` (جديد): `SC` غيرُ مرقَّمٍ من `R11` — حسابُ **مدرّسٍ** و**طالبٍ** يطلبُ رابطاً بـ`student_uuid` ⇒ يُرفَضُ بالجملةِ نفسِها؛ وحسابُ `Parent` ⇒ يُقبَل.
- [X] T039 [P] اختبارُ `FR-012`: قطعٌ ثمّ إعادةُ طلبٍ ⇒ صفٌّ **جديدٌ** `pending`، والصفُّ الملغى باقٍ. **لا يوجدُ اليومَ اختبارٌ في هذا الاتّجاهِ إطلاقاً.**
- [X] T040 [P] `SC-005`: الوصيُّ يوسِّعُ ⇒ ٤٢٢ · يُضيِّقُ ⇒ ٢٠٠ · الطالبُ يوسِّعُ ⇒ ٢٠٠ · **super admin يوسِّعُ ⇒ يُرفَض**.
- [X] T041 [P] `frontend/src/app/(app)/(shell)/family/page.test.tsx` (جديد): الصفحةُ **بلا اختبارٍ اليوم**. حالتان — قسمُ الطالبِ يرسمُ اسمَ الوصيِّ لا اسمَ نفسِه · زرُّ القبولِ يظهرُ على `can_decide` وحدَه.
- [X] T042 حدِّثْ `tests/Feature/Marketplace/ParentAccountTest.php` إن لزم: اسمُ حالةِ «يرفضُ الربطَ مرّتَين» صارَ أضيقَ معنى.

## Phase 8 · الإصلاحُ الجانبيُّ المدوَّن

- [X] T043 [P] `frontend/src/lib/panel-nav.tsx`: `guardian` في جمهورِ `/orders` و`/billing` — **ارتدادُ ٠٢٩** المشحون: وليُّ الأمرِ يشتري ثمّ لا يجدُ طلبَه ولا يستطيعُ استبدالَ إيصالٍ مرفوض. وحدِّثْ `panel-nav.test.ts` الذي يؤكِّدُ الحجبَ اليوم.
- [X] T044 [P] `AddChildForm.tsx:56` مكتوبةٌ `api.post<{data: ChildLink}>` بينما الجوابُ كائنٌ عارٍ ⇒ `created.data` هي `undefined`. سطرٌ واحد.

## Phase 9 · البوّابات

- [X] T045 `cd backend && ./vendor/bin/pint && ./vendor/bin/phpstan analyse`
- [X] T046 `php artisan migrate` ثمّ أعِدْ قياسَ `T001` — **الرقمانِ متطابقانِ أو الهجرةُ كتبَت ما لا يحقُّ لها** (`SC-007`).
- [X] T047 `php vendor/bin/pest tests/Feature/Identity tests/Feature/Notifications tests/Feature/Marketplace/ParentAccountTest.php` — **مرّةً واحدةً، ولا طقمانِ معاً.**
- [X] T048 `cd frontend && npx tsc --noEmit && npm test`

---

## التوازي

`T005` `T006` · `T018`–`T020` `T023` · `T025` `T026` · `T038`–`T041` · `T043` `T044`.
وكلُّ ما عدا ذلك متسلسلٌ بالملفّ.

## MVP

`Phase 2` + `Phase 3` + `Phase 4` = US1 كاملةً: الرابطُ يُقبَلُ ويُقرَأُ في `childrenOf()`،
وهو ما يفتحُ ٠٢٩ و٠٣١. و`Phase 5` تجعلُه قابلاً للاستعمالِ من شاشة.
