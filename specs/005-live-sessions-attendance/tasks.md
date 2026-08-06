---
description: "Task list for 005-live-sessions-attendance"
---

# Tasks: الحصص المباشرة والحضور والتجميد

**Input**: `specs/005-live-sessions-attendance/` — [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبة**. الدستور IV يجعل اختبارات الميزة شبكة الأمان الأساسية، والمواصفة تربط
كل `SC-` من الخمسة والعشرين باختبار مسمّى. لا مهمة تنفيذ بلا مهمة اختبار تسبقها أو ترافقها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: قابلة للتوازي — ملفات مختلفة، بلا اعتماد على مهمة غير مكتملة
- **[Story]**: `US1`…`US6` لمهام مراحل قصص المستخدم فقط

## Path Conventions

`backend/` أحادية معيارية · `frontend/` Next.js. كل المسارات من جذر المستودع.

---

## Phase 1: Setup (البنية المشتركة)

**Purpose**: هيكل الوحدة والإعدادات والصلاحيات والمحدّدات — بلا منطق.

- [X] T001 أنشئ هيكل وحدة `LiveSessions` في `backend/app/Modules/LiveSessions/` بمجلدات `Contracts` · `Providers` · `Data` · `Enums` · `Models` · `Actions` · `Jobs` · `Listeners` · `Events` · `Http/{Controllers,Requests,Resources}` · `Policies` · `Support` · `Database/Migrations` (حرف M كبير — الدستور III يجعل الخطأ فيه يحمّل صفر هجرات على Linux بصمت) · `routes`
- [X] T002 أنشئ `backend/app/Modules/LiveSessions/LiveSessionsServiceProvider.php` يمتدّ `App\Shared\Modules\Module` بـ `protected string $name = 'LiveSessions'` — **يُمنع** تسجيله في `bootstrap/providers.php` (الاكتشاف تلقائي عبر `ModulesServiceProvider`)
- [X] T003 أضف `app/Modules/LiveSessions/Database/Migrations` إلى `databaseMigrationsPath` في `backend/phpstan.neon` — بدونها لا يستنتج Larastan أنواع خصائص النماذج الجديدة وتسقط البوابة على مستوى ٨
- [X] T004 [P] أنشئ `backend/config/sessions.php` بمفاتيح `provider` · `timezone` · `grace_minutes` · `absence_threshold_ratio` · `required_stay_ratio` · `teacher_required_stay_ratio` · `cancellation_window_minutes` · `join_window_minutes` · `presence_interval_seconds` · `attendance_edit_window_hours` · `report_delay_minutes` — كلها **افتراضيات** يعلوها `platform_settings` (research §R9)
- [X] T005 [P] أضف المحدّدَين المسمّيَين `sessions` (٦٠/دقيقة بالمستخدم) و`presence` (٢٤٠/دقيقة بالمستخدم) في `AppServiceProvider::registerRateLimiters()` بـ `backend/app/Providers/AppServiceProvider.php` — **يُمنع** أي `throttle:60,1` سطري: `ThrottleRequests` يفهرس الضيوف بـ`domain|ip` بلا مسار، فكل حدّ سطري يتشارك عدّاداً واحداً
- [X] T006 [P] أنشئ `backend/app/Modules/LiveSessions/routes/api.php` فارغاً بترويسة توضّح أن `Module` يضيف البادئة `/api/v1` ومجموعة `api` تلقائياً
- [X] T007 [P] أضف صفوف الإعدادات العشرة (`sessions.*` من research §R9) إلى `backend/database/seeders/PlatformSettingsSeeder.php` بـ`firstOrCreate` ثم `PlatformSettings::flush()`
- [X] T008 [P] أضف الثوابت الستّ `SESSIONS_VIEW` · `SESSIONS_MANAGE` · `SESSIONS_HOST` · `ATTENDANCE_VIEW` · `ATTENDANCE_OVERRIDE` · `FREEZE_MANAGE` إلى `backend/app/Modules/Tenancy/Support/Permissions.php` **وإلى `Permissions::all()`** — الغياب عن `all()` يعني صلاحية لا تُبذَر فلا تُمنح لأحد
- [X] T009 أسند الصلاحيات الجديدة للأدوار في `backend/app/Modules/Tenancy/Listeners/SeedDefaultRoles.php`: المدرّس ومالك المساحة يأخذون الستّ، والطالب يأخذ `SESSIONS_VIEW` وحدها. **`SESSIONS_HOST` للمدرّس فقط** — تقييد المساعدين يُعرَّف في 010 (research §R14)

**Checkpoint**: الوحدة مكتشَفة · PHPStan يقرأ هجراتها · المحدّدات مسمّاة · الصلاحيات مبذورة.

---

## Phase 2: Foundational (متطلّبات حاجبة)

**Purpose**: التعدادات والجداول والنماذج وعقد المزوّد والأحداث — **يُمنع** بدء أي قصة قبل اكتمالها.

**⚠️ CRITICAL**: كل قصة من الستّ تعتمد على ما في هذه المرحلة.

### التعدادات

- [X] T010 [P] أنشئ `backend/app/Modules/LiveSessions/Enums/ClassSessionStatus.php` بالحالات `scheduled` · `live` · `interrupted` · `completed` · `cancelled` · `suspended` مع `label(): string` عربية ودالّة `isTerminal()`
- [X] T011 [P] أنشئ `backend/app/Modules/LiveSessions/Enums/ClassSessionType.php` بـ`individual` · `group` — **قيمة معلنة لا مستنتَجة من عدد المقاعد** (FR-001أ)، لأن التسعير في 006 والتسوية في 014 يختلفان بالنوع
- [X] T012 [P] أنشئ `backend/app/Modules/LiveSessions/Enums/AttendanceStatus.php` بـ`present` · `absent` · `late` · `excused` — **قائمة مغلقة** (FR-048) — مع `label()` عربية
- [X] T013 [P] أنشئ `backend/app/Modules/LiveSessions/Enums/AttendanceSource.php` بـ`automatic` · `manual` و`BookingStatus.php` بـ`booked` · `cancelled_in_window` · `cancelled_late` · `released`

### الجداول

- [X] T014 أنشئ هجرة `class_sessions` في `backend/app/Modules/LiveSessions/Database/Migrations/` بكل الأعمدة في data-model §١، والفهارس الثلاثة: `(workspace_id, teacher_profile_id, starts_at)` · `(workspace_id, status, starts_at)` · `(starts_at)`
- [X] T015 [P] أنشئ هجرة `session_bookings` بأعمدة data-model §٢ **وفهرس فريد `(class_session_id, student_user_id)`** — يمنع الحجز المزدوج من الطالب نفسه، وليس هو حارس تجاوز المقاعد
- [X] T016 [P] أنشئ هجرة `attendances` بأعمدة data-model §٣، بفهرس فريد `(class_session_id, student_user_id)` وفهرس `(student_user_id, created_at)` لجدول الطالب
- [X] T017 [P] أنشئ هجرة `class_session_feedback` بفهرس فريد `(class_session_id, student_user_id)`
- [X] T018 [P] أنشئ هجرة `freeze_periods` بـ`student_user_id` **قابل للإفراغ** (`null` = كل طلاب المدرّس) وفهرس `(workspace_id, starts_on, ends_on)`
- [X] T019 أنشئ هجرة تضيف `class_session_id` (FK nullable) إلى `lessons` في `backend/app/Modules/Courses/Database/Migrations/` — الدرس المنشور من تسجيل يعرف حصته، وهو أساس مسار الأهلية الثالث (research §R8)

### النماذج والمصانع

- [X] T020 أنشئ `backend/app/Modules/LiveSessions/Models/ClassSession.php` بـ`BelongsToWorkspace` + `HasUuid`، وcasts للتعدادات والتواريخ، وعلاقات `bookings` · `attendances` · `teacherProfile` · `course` · `mediaAsset`، ودوالّ `absenceThresholdAt()` · `graceEndsAt()` · `joinWindowCovers()`
- [X] T021 [P] أنشئ `backend/app/Modules/LiveSessions/Models/SessionBooking.php` — **جسر**: `workspace_id` للسياق و`student_user_id` يشير إلى الطالب العام
- [X] T022 [P] أنشئ `backend/app/Modules/LiveSessions/Models/Attendance.php` — جسر كذلك، بـ`auto_status` منفصلاً عن `status` ليبقى المصدر الآلي ظاهراً بعد أي تعديل (FR-025)
- [X] T023 [P] أنشئ `backend/app/Modules/LiveSessions/Models/ClassSessionFeedback.php` و`FreezePeriod.php`
- [X] T024 [P] أنشئ المصانع الخمسة في `backend/database/factories/Modules/LiveSessions/` بنطاق `Database\Factories\Modules\LiveSessions` — **يُمنع** تعريف `newFactory()` على النماذج (`AppServiceProvider::guessFactoryName()` يحلّها)

### عقد مزوّد البث

- [X] T025 أنشئ `backend/app/Modules/LiveSessions/Contracts/BroadcastProviderInterface.php` بالتوقيعات السبعة في `contracts/broadcast-provider.md`، بتعليق يوضّح أن **الحضور لا يمرّ بالمزوّد** (research §R3)
- [X] T026 [P] أنشئ DTOs في `backend/app/Modules/LiveSessions/Data/`: `BroadcastCapabilities` · `RoomHandle` · `JoinTicket` · `RecordingArtifact` — كلها ترث `App\Shared\Data\DataTransferObject` بخصائص `readonly` مُرقّاة، و`ParticipantRole` و`HostAction` تعدادان
- [X] T027 أنشئ `backend/app/Modules/LiveSessions/Providers/NullBroadcastProvider.php` يعلن `liveMedia: false` · `recording: false` · `hostControls: false` صادقةً، ويصدر تذاكر موقّعة قصيرة العمر بلا شبكة
- [X] T028 اربط الواجهة في `LiveSessionsServiceProvider::register()` بـ`match ((string) config('sessions.provider'))` بنفس شكل `MediaServiceProvider` — نقطة الانعكاس التي تجعل إضافة مزوّد ملفاً وحالة واحدة
- [X] T029 [P] أنشئ `backend/tests/Support/FakeBroadcastProvider.php` يعلن القدرات الأربع كلها وينفّذها في الذاكرة — NFR-011 تمنع أي نداء شبكي حقيقي في الاختبارات
- [X] T030 [P] أنشئ `backend/tests/Feature/LiveSessions/BroadcastProviderContractTest.php` يُلزم كل تنفيذ **بما يدّعيه فقط**: قدرة معلنة `true` يجب أن تعمل، ومعلنة `false` ترمي `UnsupportedCapability` — وهو ما يجعل تأجيل المزوّد آمناً لا متفائلاً

### الأحداث والسياسات والإعدادات

- [X] T031 [P] أنشئ الأحداث الأربعة في `backend/app/Modules/LiveSessions/Events/`: `SessionScheduled` · `SessionCompleted` · `SessionDelivered` · `AttendanceConfirmed`. **`SessionCompleted` و`SessionDelivered` حدثان مختلفان عمداً** — دمجهما بعَلَم منطقي يجعل شرط التنفيذ اختيارياً للمستمع (research §R7)
- [X] T032 [P] أنشئ `backend/app/Modules/LiveSessions/Support/SessionSettings.php` يقرأ العشرة من `PlatformSettings` مع الرجوع إلى `config('sessions.*')`، ويحسب `absenceThresholdSeconds(ClassSession)` و`requiredStaySeconds(ClassSession)` — **يُمنع** قراءة `config()` مباشرةً من أي Action (FR-021أ)
- [X] T033 [P] أنشئ السياسات في `backend/app/Modules/LiveSessions/Policies/`: `ClassSessionPolicy` · `SessionBookingPolicy` · `AttendancePolicy` · `FreezePeriodPolicy` — أسماء الصلاحيات من ثوابت `Permissions` حصراً، وسجّلها بـ`Gate::policy()` في `boot()` بمزوّد الوحدة
- [X] T034 أضف حالات الكيانات الخمسة إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — النموذج المملوك لمساحة عمل بلا اختبار عزل يسرّب بصمت ولا بوابة آلية تكشفه
- [X] T035 أنشئ `backend/tests/Feature/LiveSessions/PlatformOwnershipTest.php` (NFR-001ب): مدرّس **لا** يرى صفوف طالب لا يملك حجزاً أو تسجيلاً في مساحة عمله، والطالب يرى جدوله **الواحد** عبر كل مدرّسيه بلا تكرار

**Checkpoint**: الجداول قائمة · النماذج مصنَّفة ومُختبَرة عزلاً · العقد ملزم · الأحداث معرَّفة.

---

## Phase 3: User Story 1 — جدولة الحصص وحجز الطالب مقعده (P1) 🎯 MVP

**Goal**: منصة تعرف مواعيد دروسها ومن حجز فيها — حتى لو أُديرت الحصة نفسها خارجها مؤقّتاً.

**Independent Test**: مدرّس بجدول توفّر → توليد حصص → حجز طالبين → محاولة ثالث بعد الامتلاء — بلا أي بثّ أو حضور.

### الأهلية (FR-045…047 — تسبق كل شيء لأن الحجز يستدعيها)

- [X] T036 [US1] أنشئ `backend/app/Modules/LiveSessions/Support/BookingEligibility.php` يعرّف الأهلية صراحةً كتركيبة الثلاثة: تسجيل نشط عند المدرّس أو في كورسه · لا حجب سارٍ · الحصة خارج أي تجميد يخصّ الطالب — **يُمنع** أي قاعدة ضمنية غير موثّقة (FR-047)
- [X] T037 [P] [US1] أنشئ `backend/tests/Feature/LiveSessions/BookingEligibilityTest.php`: كل شرط يُسقِط الأهلية وحده، والأهلية تُقيَّم **عند الحجز وعند الدخول معاً** لا مرة واحدة (FR-046)

### الجدولة

- [X] T038 [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/ScheduleClassSession.php`: يبني الحصة من DTO، **يمنع التداخل** لنفس المدرّس على `[starts_at, ends_at)` (FR-003)، ويطلق `SessionScheduled`
- [X] T039 [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/GenerateSessionsFromAvailability.php` يقرأ `availability_slots` القائمة منذ 001 لمدى تاريخي، ويُرجع ما أُنشئ **وما تُخطّي مع سببه** — المتخطّى الصامت هو ما يجعل المدرّس يظنّ جدوله ممتلئاً
- [X] T040 [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/CancelClassSession.php`: يضبط `cancelled_at` والسبب، ويحرّر كل الحجوزات، ويطلق حدث إبلاغ من حجز (FR-006)
- [X] T041 [P] [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/UpdateClassSession.php` — **يمنع تغيير `type` بعد وجود حجز** (FR-001ب)

### الحجز

- [X] T042 [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/BookSeat.php` بالحارس الذرّي: `UPDATE class_sessions SET seats_taken = seats_taken + 1 WHERE id = ? AND seats_taken < seats_total` ويُقبل الحجز عند `affected === 1` فقط (research §R5). **يُمنع** `count()` ثم `insert()` — هو تعريف السباق، و**يُمنع** `lockForUpdate()` لأنه بلا أثر على SQLite فاختباره يمرّ محلياً بلا أن يثبت شيئاً
- [X] T043 [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/CancelBooking.php`: قبل مهلة الإلغاء يحرّر المقعد ويُنقص `seats_taken`، وبعدها يُعلَّم `cancelled_late` **ويبقى محسوباً** (FR-009 · FR-010)
- [X] T044 [P] [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/ReleaseIneligibleBookings.php` يحرّر مقعد من انتهت أهليته قبل الموعد بحالة `released` — حالة مستقلة عن الإلغاء لأن الطالب لم يفعل شيئاً (FR-012)
- [X] T045 [US1] أنشئ `backend/app/Modules/LiveSessions/Actions/GetStudentSchedule.php` — **مسار قراءة مملوك للمنصة**، عابر لمساحات العمل عمداً بـ`withoutWorkspaceScope()` مع تعليق يشرح السبب، وحارسه ملكية الطالب للصفوف (FR-052)

### الطبقة الشبكية

- [X] T046 [US1] أنشئ `ClassSessionController` في `backend/app/Modules/LiveSessions/Http/Controllers/` بدوالّ `index` · `store` · `generate` · `show` · `update` · `cancel` — تحقّق بـFormRequest ← DTO ← Action ← Resource، بلا منطق في المتحكّم
- [X] T047 [P] [US1] أنشئ `BookingController` و`ScheduleController` بدوالّ `book` · `destroy` · `schedule` · `next`
- [X] T048 [P] [US1] أنشئ FormRequests في `Http/Requests/`: `StoreClassSessionRequest` · `UpdateClassSessionRequest` · `GenerateSessionsRequest` · `BookSeatRequest` — كل تحقّق وجود على جدول تابع لمستأجر يستخدم `WorkspaceRules::exists()` لا `exists:table,id`
- [X] T049 [P] [US1] أنشئ Resources: `ClassSessionResource` · `SessionBookingResource` · `StudentScheduleResource` — تكشف `uuid` فقط، و**يُمنع** ظهور `broadcast_provider` أو `broadcast_room_id` أو `billable_seats` في حمولة الطالب (FR-019 · contracts/api.md)
- [X] T050 [US1] سجّل المسارات في `backend/app/Modules/LiveSessions/routes/api.php` بمحدِّد `throttle:sessions` على كل مسار كتابة
- [X] T051 [P] [US1] أضف مدخلات الحقول العربية الجديدة إلى `backend/lang/ar/validation.php` تحت `attributes` — الحقل بلا مدخل يُعرَض للمستخدم باسمه البرمجي

### الاختبارات

- [X] T052 [P] [US1] أنشئ `backend/tests/Feature/LiveSessions/SchedulingTest.php`: التوليد من التوفّر · **رفض التداخل** (SC-002) · التوليد يبلّغ عن المتخطّى · منع تغيير النوع بعد الحجز
- [X] T053 [P] [US1] أنشئ `backend/tests/Feature/LiveSessions/SeatConcurrencyTest.php` (SC-001): استدعاءان متعاقبان على المقعد الأخير — الثاني **يجب** أن يُرفض، وهو نفس المسار الذي يسلكه التزامن الحقيقي
- [X] T054 [P] [US1] أنشئ `backend/tests/Feature/LiveSessions/BookingLifecycleTest.php`: الإلغاء قبل المهلة يحرّر · بعدها يُحتسب ويُعلَّم · إلغاء المدرّس لا يُحتسب على أحد · انتهاء الأهلية يحرّر تلقائياً
- [X] T055 [P] [US1] أنشئ `backend/tests/Feature/LiveSessions/StudentScheduleTest.php`: الطالب يرى حصصه عبر **كل** مدرّسيه في جدول واحد، و**مدرّس لا يرى حصص طالبه عند غيره** (المبدأ I)

### الواجهة

- [X] T056 [P] [US1] أنشئ `frontend/src/lib/sessions.ts` بأنواع تطابق الـResources حرفاً بحرف ودوالّ النداء — **اقرأ الـResource قبل كتابة النوع**: نوع لا يطابق المورد يُنتج شاشة تعرض فراغاً بلا خطأ
- [X] T057 [P] [US1] أنشئ `frontend/src/components/sessions/SessionCard.tsx` و`SeatBadge.tsx` و`NextSessionCountdown.tsx` — العدّاد يُبنى من ثوانٍ يرسلها الخادم لا من ساعة المتصفّح (SC-016)، والألوان من رموز `@theme` حصراً
- [X] T058 [US1] أنشئ `frontend/src/app/(app)/(shell)/schedule/page.tsx`: جدول الطالب الموحّد + الحصة القادمة + حالة فراغ مفهومة بلا حصص (FR-055)
- [X] T059 [US1] أنشئ `frontend/src/app/(app)/(shell)/manage/sessions/page.tsx` (جدول المدرّس والتوليد) و`manage/sessions/[uuid]/page.tsx` (المقاعد والحجوزات)
- [X] T060 [US1] **أضف عنصري تنقّل** «جدولي» (`/schedule`) و«حصصي» (`/manage/sessions`) إلى `mainNav` في `frontend/src/app/(app)/(shell)/layout.tsx` مع أيقونتين في `frontend/src/components/icons/index.tsx` — شاشة بلا رابط شاشة غير مُسلَّمة

**Checkpoint**: منصة تعرف مواعيدها ومن حجز فيها. قابلة للشحن وحدها.

---

## Phase 4: User Story 2 — الحصة الحية داخل المنصة (P2)

**Goal**: غرفة يدخلها المدرّس ومن حجز مقعده، ويُرفض غيرهما، وتُغلق فلا يُقبل رمز سابق.

**Independent Test**: منح رمز للمدرّس ولطالب حاجز ولطالب غير حاجز — قبول الأولين ورفض الثالث.

- [X] T061 [US2] أنشئ `backend/app/Modules/LiveSessions/Actions/OpenBroadcastRoom.php` يستدعي `createRoom()` **متماثل الأثر** ويكتب `broadcast_room_id` و`room_opened_at` وينقل الحالة إلى `live`
- [X] T062 [US2] أنشئ `backend/app/Modules/LiveSessions/Actions/IssueJoinTicket.php`: **يعيد تقييم الأهلية هنا** (FR-046)، يفحص نافذة الدخول من `SessionSettings`، ويطلب التذكرة من المزوّد بدور مشتق من الصلاحية لا من مدخل العميل
- [X] T063 [US2] أنشئ `backend/app/Modules/LiveSessions/Actions/RecordPresencePing.php`: يضيف `min(now − last_ping_at, 2 × presence_interval)` إلى `stay_seconds` ويضبط `last_ping_at` — من هذا السطر تأتي الخصائص الثلاث معاً: جهازان لا يضاعفان، والعودة تُجمَّع، وفجوة الانقطاع لا تُحتسب (FR-024 · research §R3)
- [X] T064 [P] [US2] أنشئ `backend/app/Modules/LiveSessions/Actions/PerformHostAction.php` (كتم · إخراج · إنهاء) محروساً بـ`Permissions::SESSIONS_HOST`، يرمي بوضوح إن لم يعلن المزوّد `hostControls`
- [X] T065 [P] [US2] أنشئ `backend/app/Modules/LiveSessions/Actions/CloseBroadcastRoom.php` — بعده **يُمنع** الدخول بأي تذكرة سابقة (FR-015)
- [X] T066 [US2] أنشئ `BroadcastController` بدوالّ `join` · `presence` · `leave` · `host` وسجّلها بـ`throttle:sessions` و`throttle:presence` على التوالي
- [X] T067 [P] [US2] أنشئ `JoinTicketResource` — يحمل `room_url` · `token` · `expires_at` · `role` فقط. **يُمنع** اسم المزوّد أو مفتاحه أو سرّه (FR-019)
- [X] T068 [P] [US2] أنشئ `backend/tests/Feature/LiveSessions/RoomAccessTest.php` (SC-003) بأربع حالات رفض: لم يحجز · قبل النافذة · بعد النافذة · تذكرة بعد الإغلاق — والرفض **بلا كشف أي معلومة عن الحصة** (السيناريو 2)
- [X] T069 [P] [US2] أنشئ `backend/tests/Feature/LiveSessions/PresenceAggregationTest.php`: نبضتان من جهازين في الوقت نفسه **لا تضاعفان** المدة · انقطاع ثم عودة يُجمَّعان في مدة واحدة · الفجوة الطويلة تُحتسب بسقف `2×interval` لا كاملةً
- [X] T070 [P] [US2] أنشئ `backend/tests/Feature/LiveSessions/HostControlsTest.php`: المدرّس يملك الأدوات والطالب لا يملكها (FR-016)
- [X] T071 [P] [US2] أنشئ `frontend/src/components/sessions/PresenceLoop.tsx` — حلقة `useEffect` تنبض كل `presence_interval` وتتوقّف عند إلغاء التركيب، وتعرض حالة الاتصال بلا خطأ خام عند فشل نبضة واحدة
- [X] T072 [P] [US2] أنشئ `frontend/src/components/sessions/BroadcastStage.tsx` — يحجز موضع مسرح الفيديو خلف مكوّن واحد، تنفيذه اليوم لوحة حالة وغداً غلاف SDK المزوّد (research §R15). **يُمنع** إدخال أي SDK بثّ الآن
- [X] T073 [US2] أنشئ `frontend/src/app/(app)/(shell)/sessions/[uuid]/room/page.tsx` يجمع التذكرة والمسرح وحلقة النبض وأدوات المضيف
- [X] T074 [US2] **اربط الغرفة** من بطاقة الحصة في `/schedule` ومن صفحة الحصة في `/manage/sessions/[uuid]` — لا يوجد عنصر تنقّل للغرفة (لا معنى لها بلا حصة)، فالرابط من الرحلة هو الطريق الوحيد إليها
- [X] T075 [P] [US2] أضف رمز `session_not_joinable` إلى `BY_CODE` في `frontend/src/lib/errors.ts` برسالة عربية تشرح النافذة الزمنية — **يُمنع** عرض خطأ خام للمستخدم

**Checkpoint**: غرفة بدورة حياة كاملة وحارس دخول مُثبَت، ونبض يتراكم.

---

## Phase 5: User Story 3 — الحضور يُحتسب بلا إدخال يدوي (P3)

**Goal**: السُّلَّم الزمني يكتب الحالة، والغياب يُسجَّل **عند العتبة**، والكشف يغطّي كل مقعد مُجمَّد.

**Independent Test**: محاكاة أربعة مواضع دخول وقراءة السجلّ الناتج.

### تجميد المقاعد

- [ ] T076 [US3] أنشئ `backend/app/Modules/LiveSessions/Jobs/FreezeBillableSeatsJob.php` يُدفَع عند الجدولة بتأخير حتى `starts_at − cancellation_window`، ويكتب `billable_seats` و`seats_frozen_at` **مرة واحدة** ويخرج بلا أثر إن كان مكتوباً (FR-059 · FR-060)
- [ ] T077 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/BillableSeatsTest.php` (SC-018): الرقم يطابق الحجوزات لحظة المهلة، و**إلغاء لاحق لا يغيّره**، وحصة بلا حجز تُعلَّم `zero_attendance` وتُدرَج للمراجعة (FR-061)

### السُّلَّم والعتبة

- [ ] T078 [US3] أنشئ `backend/app/Modules/LiveSessions/Support/AttendanceLadder.php` يحوّل (أول نبضة، مدة البقاء، إعدادات الحصة) إلى `AttendanceStatus` وفق الجدول في data-model §٣ — منطق خالص بلا قاعدة بيانات، ليُختبر مباشرةً
- [ ] T079 [US3] أنشئ `backend/app/Modules/LiveSessions/Jobs/MarkAbsenteesJob.php` يُدفَع عند بدء الحصة بتأخير يساوي العتبة، ويكتب `absent` لكل مقعد لم يصله نبض — **متماثل الأثر**: يخرج بلا أثر إن كانت الحالة قد كُتبت (research §R4)
- [ ] T080 [US3] عدّل `RecordPresencePing` ليكتب `first_joined_at` عند أول نبضة ويحدّث الحالة بـ`AttendanceLadder` — **ودخول بعد العتبة يُسمح به ولا يقلب `absent` آلياً** (FR-021ج)
- [ ] T081 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/AttendanceLadderTest.php` (SC-020) بالمواضع الأربعة: ضمن السماح · بعده وقبل النصف · لا دخول حتى النصف · دخول بعد النصف
- [ ] T082 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/AbsenceTimingTest.php` (SC-021) بـ`travelTo()`: `absent` تُكتب **عند انقضاء العتبة** لا عند نهاية الحصة — الاختبار يقيس اللحظة لا النتيجة

### الإغلاق والكشف والتنفيذ

- [ ] T083 [US3] أنشئ `backend/app/Modules/LiveSessions/Actions/CloseClassSession.php`: يغلق الغرفة، وينتج **كشفاً كاملاً يغطّي كل مقعد مُجمَّد بلا استثناء** (FR-023أ)، ويطلق `SessionCompleted` ثم `AttendanceConfirmed`
- [ ] T084 [US3] أضف شرط التنفيذ في `CloseClassSession`: `SessionDelivered` يُطلق **فقط** عند تحقّق الثلاثة — دخل المدرّس · بلغ `teacher_required_stay_ratio` · انتهت طبيعياً (FR-056 · FR-057)، ويُكتب `delivered_at`
- [ ] T085 [US3] أنشئ `backend/app/Modules/LiveSessions/Jobs/CloseClassSessionJob.php` يُدفَع بتأخير حتى نهاية الحصة + سماح، ويستدعي الـAction — متماثل الأثر
- [ ] T086 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/SessionDeliveryTest.php` (SC-017): حصة لم يدخلها المدرّس وأخرى غادرها مبكّراً — **صفر استهلاك وصفر استحقاق** في كلتيهما، و`SessionCompleted` يُطلق بلا `SessionDelivered`
- [ ] T087 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/AttendanceSheetTest.php` (SC-022): عدد صفوف الكشف يطابق `billable_seats` **بلا نقص ولا زيادة**
- [ ] T088 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/AttendanceHasNoFinancialEffectTest.php` (SC-024 · FR-023ج): حصة حضرها الجميع وأخرى غاب عنها الجميع بنفس المقاعد تُنتجان **الأثر المالي نفسه** — يُكتب الآن رغم غياب الفوترة، لأن الحدث الذي تستهلكه 006 يُعرَّف هنا

### التعديل اليدوي

- [ ] T089 [US3] أنشئ `backend/app/Modules/LiveSessions/Actions/OverrideAttendance.php`: يتطلّب `ATTENDANCE_OVERRIDE`، ويسجّل `overridden_by` و`overridden_at` و`override_reason`، **ويُبقي `auto_status` ظاهراً** (FR-022أ · FR-025)
- [ ] T090 [US3] أضف حارس النافذة إلى `OverrideAttendance`: خارج `attendance_edit_window_hours` يُرفض بـ`code: attendance_window_closed` ويحتاج صلاحية إدارية أعلى (FR-022ب)
- [ ] T091 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/AttendanceOverrideTest.php`: التحضير اليدوي يُقبل ويُسجَّل · المصدر الآلي يبقى · بعد النافذة يُرفض · **`excused` لا تُمنح آلياً أبداً** (SC-014)
- [ ] T092 [P] [US3] أنشئ `backend/app/Modules/LiveSessions/Actions/RecordRecordingWatched.php` يكتب `recording_watched_at` **واقعةً مستقلة** — و`backend/tests/Feature/LiveSessions/RecordingWatchDoesNotFlipStatusTest.php` (SC-023) يثبت أن مشاهدة كاملة بعد غياب **لا تغيّر حالة واحدة** (FR-021د)

### عدّادات المدرّس

- [ ] T093 [US3] أنشئ `backend/app/Modules/LiveSessions/Listeners/UpdateTeacherCounters.php` مسجَّلاً بـ`Event::listen()` في مزوّد الوحدة، يدفع `SyncTeacherCountersJob` — التحديث **تزايدي** لا حساب على كامل السجل عند كل قراءة (FR-027)
- [ ] T094 [US3] أنشئ `backend/app/Modules/LiveSessions/Jobs/SyncTeacherCountersJob.php` يحدّث الحقول الأربعة القائمة منذ 001 بـ`forWorkspace()` — **يُمنع** `WorkspaceContext::set()` في وظيفة (NFR-007)
- [ ] T095 [US3] احسب `attendance_rate` كنسبة **حصص المدرّس المنفَّذة إلى المجدولة**، واستبعد الملغاة والمعلّقة والمتعذّرة (FR-026 · FR-062)، وأضف تعليقاً على العمود وفي `docs/README.md`: مؤشّر يُقرأ خطأً مرة يبقى مقروءاً خطأً إلى الأبد
- [ ] T096 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/TeacherCountersTest.php` (SC-012 · SC-019): مطابقة بعد ١٠٠ حصة متسلسلة، و**غياب جماعي للطلاب لا يغيّر نسبة المدرّس ولا درجة ثقته ولو بنقطة**
- [ ] T097 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/CounterJobIsolationTest.php` يفشل البناء إن ظهر `WorkspaceContext::set()` تحت `Modules/LiveSessions/Jobs/` — بنفس شكل `TrustScoreJobIsolationTest` القائم
- [ ] T098 [US3] أنشئ `frontend/src/components/sessions/AttendanceSheet.tsx` واعرضه في `/manage/sessions/[uuid]`: الحالة والمصدر ووقت الدخول ومدة البقاء، **والمصدر الآلي ظاهراً بجانب أي تعديل**، مع زرّ التحضير اليدوي داخل النافذة

**Checkpoint**: الحضور محتسَب آلياً بالكامل، والكشف كامل، والعدّادات تتحرّك.

---

## Phase 6: User Story 4 — التسجيل يظهر كدرس بلا تدخّل بشري (P4)

**Goal**: تنتهي الحصة فيصبح تسجيلها درساً محمياً لمن حجز مقعده وحده.

**Independent Test**: إنهاء حصة مسجَّلة والتحقّق من ظهورها أصلاً محمياً متاحاً لطلابها وحدهم.

- [ ] T099 [US4] أنشئ `backend/app/Shared/Contracts/SessionAttendanceDirectory.php` بالتوقيعين في data-model — بنفس شكل `EnrollmentDirectory` بالضبط، لأن المبدأ III يمنع `Media` من لمس نماذج `LiveSessions`
- [ ] T100 [US4] أنشئ `backend/app/Modules/LiveSessions/Support/EloquentSessionAttendanceDirectory.php` واربطه في مزوّد الوحدة
- [ ] T101 [US4] أضف المسار الثالث إلى `IssuePlaybackGrant::mayWatch()` و`mayWatchMany()` في `backend/app/Modules/Media/Actions/IssuePlaybackGrant.php`: درس مرتبط بحصة يُشاهَد بحجز مقعد فيها — **لا بالتسجيل في الكورس** (FR-030)
- [ ] T102 [US4] أنشئ `backend/app/Modules/LiveSessions/Jobs/IngestSessionRecordingJob.php` يستدعي `recording()` بعد الإغلاق، وينشئ `MediaAsset` ويسلّمه إلى خط أنابيب 004 — **`null` ليست خطأً**: هي الحالة المتوقّعة في أول استدعاء
- [ ] T103 [US4] أضف إعادة المحاولة بحدّ معلن إلى `IngestSessionRecordingJob` مع `recording_attempts`، وعند الفشل النهائي أبلغ المدرّس **وأتِح الرفع اليدوي** (FR-031)
- [ ] T104 [US4] أنشئ `backend/app/Modules/LiveSessions/Listeners/PublishRecordingAsLesson.php` مستمعاً على `MediaAssetReady` القائم منذ 004، ينشئ درساً مرتبطاً بالحصة (`lessons.class_session_id`) ويضبط `recording_status = published`
- [ ] T105 [P] [US4] أنشئ `backend/tests/Feature/LiveSessions/RecordingPublicationTest.php` (SC-007) بـ`FakeBroadcastProvider` يعلن `recording: true`: السلسلة كاملةً من الإغلاق إلى درس منشور، وحالة «قيد التجهيز» تُعرَض ولا تفشل (FR-032)
- [ ] T106 [P] [US4] أنشئ `backend/tests/Feature/LiveSessions/RecordingAccessTest.php` (SC-008): من حجز يشاهد بكل ضوابط 004، **ومن لم يحجز يُمنع** — ولو كان مسجَّلاً في الكورس
- [ ] T107 [P] [US4] أضف حالة إلى `backend/tests/Feature/Media/PlaybackGrantTest.php` تؤكّد أن حمولة المنحة **لا تحمل** معرّف مزوّد بثّ ولا رابط تسجيل دائماً (FR-019)
- [ ] T108 [US4] اعرض التسجيل في `frontend/src/app/(app)/(shell)/manage/sessions/[uuid]/page.tsx` وفي بطاقة الحصة المنتهية بـ`/schedule` كرابط إلى `/learn/{lesson}` — **الدرس بلا رابط درس غير موجود**

**Checkpoint**: حزمة الحصة كاملة للغائب، بحماية 004 نفسها بلا سطر حماية جديد.

---

## Phase 7: User Story 5 — تقرير ما بعد الحصة يصل وليّ الأمر (P5)

**Goal**: وليّ الأمر يعرف بعد كل حصة: حضر ابنه أم لا، وكم بقي، وتقييم المدرّس.

**Independent Test**: إنهاء حصة وتشغيل الوظيفة والتحقّق من محتوى الرسالة لوليّ أمر مرتبط.

- [ ] T109 [US5] أضف `SessionReport` و`SessionCancelled` إلى `backend/app/Modules/Notifications/Support/NotificationType.php` مع `label()` عربية و`defaultChannels()` — و`AppointmentReminder` و`AttendanceAlert` موجودان منذ 003 بلا مُنتِج ويُستعملان كما هما
- [ ] T110 [US5] أضف قوالب النوعين إلى `backend/database/seeders/NotificationTemplateSeeder.php` — **بلا قالب يسقط الإشعار صامتاً** ويمرّ الاختبار على صفر إشعارات فيبدو أخضر (مزلق مدوَّن في `CLAUDE.md`)
- [ ] T111 [US5] أنشئ `backend/app/Modules/LiveSessions/Actions/SubmitSessionFeedback.php` — تقييم موجز لكل طالب (FR-036)، ومسار `POST /class-sessions/{uuid}/feedback`
- [ ] T112 [US5] أنشئ `backend/app/Modules/LiveSessions/Jobs/SendSessionReportsJob.php` يُدفَع بعد الإغلاق بـ`report_delay_minutes`، ويمرّ بـ`DispatchNotification` حصراً — **يُمنع** تسمية أي قناة في الـAction (يحرسه `ProviderAgnosticTest`)
- [ ] T113 [US5] عالج التوجيه في نفس الوظيفة: وليّ أمر كل طالب مرتبط، والطالب نفسه إن لم يوجد وليّ (FR-033)، ووليّان لطالب واحد يصلهما الاثنين **ويُسجَّل مرة واحدة**
- [ ] T114 [US5] أضف مستمعاً يرسل **تصحيحاً** عند تعديل حضور بعد إرسال التقرير (FR-037) — لا يُترك التقرير خاطئاً في يد وليّ الأمر
- [ ] T115 [P] [US5] أنشئ `backend/tests/Feature/LiveSessions/SessionReportTest.php` (SC-009): يصل خلال المهلة · لا يتأخّر انتظاراً لتقييم لم يُدخَل (FR-035) · وليّان يصلهما مرة واحدة · تعطيل التفضيل يمنع القناة ويُبقي السجلّ داخل المنصة (FR-038)
- [ ] T116 [P] [US5] أضف نموذج التقييم إلى `AttendanceSheet.tsx` في `frontend/src/components/sessions/` — إدخال واحد لكل طالب بجانب حالته

**Checkpoint**: الحلقة إلى وليّ الأمر مغلقة، وهي تمهيد مباشر لتنبيهات الدفع في 006.

---

## Phase 8: User Story 6 — تجميد الإجازات (P6)

**Goal**: فترة تتوقّف فيها الحصص والعدّادات بلا كسر أي التزام، وتعود بعدها من حيث توقّفت.

**Independent Test**: تجميد فترة → لا حصص ولا غياب ولا تقدّم عدّادات → استئناف.

- [ ] T117 [US6] أنشئ `backend/app/Modules/LiveSessions/Actions/CreateFreezePeriod.php`: نطاق المدرّس (`student_user_id = null`) أو طالب بعينه (FR-039)، **ويُرجع ما عُلِّق من حصص وكم طالباً أُبلغ** — التجميد الصامت الذي يُلغي حصصاً محجوزة هو ما تمنعه الحالة الحافّة الأخيرة
- [ ] T118 [US6] أضف تعليق الحصص المجدولة داخل الفترة إلى الحالة `suspended` مع إبلاغ من حجز (FR-040) — **تُعلَّق ولا تُحذف**
- [ ] T119 [US6] أضف حارس التجميد إلى `ScheduleClassSession` و`GenerateSessionsFromAvailability` و`BookSeat`: **يُمنع** إنشاء أو حجز داخل فترة سارية (FR-040 · FR-011)
- [ ] T120 [US6] أضف حارس التجميد إلى `MarkAbsenteesJob` و`SyncTeacherCountersJob`: **صفر غياب محتسَب وصفر عدّاد متقدّم** داخل الفترة (FR-041)
- [ ] T121 [P] [US6] أنشئ `FreezePeriodController` ومساراته الثلاثة محروسةً بـ`Permissions::FREEZE_MANAGE`، و`FreezePeriodResource` تكشف السبب والمنشئ لمن يملك الاطلاع (FR-044)
- [ ] T122 [P] [US6] أنشئ `backend/tests/Feature/LiveSessions/FreezePeriodTest.php` (SC-010): صفر حصة جديدة · تعليق مع إبلاغ · صفر غياب · صفر عدّاد متقدّم
- [ ] T123 [P] [US6] أنشئ `backend/tests/Feature/LiveSessions/FreezeResumptionTest.php` (FR-043): بعد الانتهاء تعود العدّادات **بقيمها نفسها بلا فقدان** — وهو ما يجعل «الاستئناف» غياب عملية لا عملية قابلة للفشل (research §R11)
- [ ] T124 [P] [US6] أنشئ `backend/tests/Feature/LiveSessions/FreezeHasNoFinancialEffectTest.php` (FR-042): التجميد **لا يمسّ** أي حقل خارج نطاق هذه المرحلة — القاعدة تُدوَّن الآن لأن 006 ستقرأ التجميد
- [ ] T125 [US6] أنشئ `frontend/src/app/(app)/(shell)/manage/freeze/page.tsx`: إنشاء فترة وعرض القائمة وما عُلِّق بسببها
- [ ] T126 [US6] **أضف رابط «فترات التجميد»** من صفحة `/manage/sessions` (لا عنصر تنقّل مستقلّ — التجميد إجراء على الجدول لا قسم قائم بذاته)، وتأكّد أن الرابط مُختبَر في e2e

**Checkpoint**: أول إجازة مدرسية لا تنهار عندها العدّادات.

---

## Phase 9: Polish & Cross-Cutting

- [ ] T127 [P] أضف الأربع المؤجّلة إلى الجدولة في `backend/routes/console.php` إن لزم تنظيف دوري (وظيفة تنظّف حصصاً عالقة في `live` تجاوزت نهايتها بساعات)، **مُزاحة** عن `03:30` و`03:45` القائمتين — عمليتا حذف جماعي في الدقيقة نفسها تنازع أقفال لم يخطّط له أحد
- [ ] T128 [P] أنشئ `frontend/e2e/sessions.spec.ts` بمسارين يدخلان **من التنقّل**: الشريط الجانبي ← جدولي ← حصة ← الغرفة، والشريط ← حصصي ← حصة ← كشف الحضور. اختبار يفتح الرابط مباشرةً **لا يثبت أن الطريق موجود** — وهو الخطأ الذي وقع مرتين
- [ ] T129 [P] أضف حالات الحصة إلى `backend/database/seeders/ScenarioSeeder.php`: حصة قادمة بمقاعد شاغرة · حصة ممتلئة · حصة منتهية بكشف حضور · فترة تجميد — بلا بيانات مبذورة تتخطّى اختبارات e2e نفسها بصمت
- [ ] T130 [P] حدّث `docs/README.md`: جدول الوحدة الجديدة ونقاط النهاية العشرين والصلاحيات الستّ والأحداث الأربعة، **ودلالة `attendance_rate`** صراحةً (حضور المدرّس لا طلابه)
- [ ] T131 [P] حدّث `docs/erd.md` بالجداول الخمسة وعلاقاتها وبعمود `lessons.class_session_id`
- [ ] T132 [P] أضف المزالق الجديدة إلى `CLAUDE.md`: الحضور لا يمرّ بالمزوّد · التحديث الشرطي لا `lockForUpdate` · `billable_seats` يُكتب مرة · `SessionCompleted` ≠ `SessionDelivered` · `ClassSession` لا `Session`
- [ ] T133 [P] حدّث `AGENTS.md` بالمسارات الحرجة الجديدة: تجاوز المقاعد · حارس دخول الغرفة · لحظة تسجيل الغياب · وصول تسجيل الحصة
- [ ] T134 [P] حدّث `docs/roadmap.md`: 005 ✅ مع الإشارة إلى مزوّد البث المؤجَّل خلف الواجهة، بنفس صيغة 004
- [ ] T135 نفّذ `specs/005-live-sessions-attendance/quickstart.md` أمراً أمراً وصحّح أي انحراف **في الملف** — الدليل الذي لم يُنفَّذ دليل غير صحيح
- [ ] T136 شغّل البوابات الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` — بلا baseline جديد ولا `@phpstan-ignore`
- [ ] T137 شغّل `npx playwright test` على **بناء إنتاج** مع `PHP_CLI_SERVER_WORKERS=8 php artisan serve` — خادم بخيط واحد يُفشل البناء نفسه قبل أول اختبار
- [ ] T138 راجع أن كل صفحة ومكوّن أُنشئا في هذه المرحلة **مربوطان ويصل إليهما اختبار من التنقّل**، وأن كل نقطة نهاية جديدة لها مستدعٍ في `frontend/src/lib/` أو سبب مكتوب لكونها خلفية فقط

---

## Dependencies & Execution Order

```
Phase 1 (Setup)
   ↓
Phase 2 (Foundational) ────── حاجبة لكل ما بعدها
   ↓
Phase 3 · US1 (P1) ─────────── MVP: منصة تعرف مواعيدها
   ↓
Phase 4 · US2 (P2) ─────────── تحتاج حصة مجدولة
   ↓
Phase 5 · US3 (P3) ─────────── تحتاج النبض من US2
   ↓          ↘
Phase 6 · US4 (P4)   Phase 7 · US5 (P5)   ← متوازيتان: كلتاهما تحتاج US3 ولا تحتاج الأخرى
   ↘          ↙
Phase 8 · US6 (P6) ─────────── تعدّل سلوك ما قبلها، فتأتي أخيراً
   ↓
Phase 9 (Polish)
```

**اعتماد خارجي**: US4 تعتمد على المرحلة 004 (مكتملة ✅). US5 تعتمد على 003 (مكتملة ✅).
لا مرحلة هنا تنتظر شيئاً غير منجَز.

**ما تنتظره مراحل لاحقة**: `SessionDelivered` و`AttendanceConfirmed` تستهلكهما 006 و014،
وكيان الحصة يستهلكه شات 010 وسلاسل 009.

---

## Parallel Opportunities

| المرحلة | متوازية معاً |
|---|---|
| Phase 1 | T004 · T005 · T006 · T007 · T008 (ملفات مختلفة كلياً) |
| Phase 2 | التعدادات T010–T013 · الهجرات T015–T018 · النماذج T021–T023 |
| Phase 3 | الاختبارات T052–T055 · مكوّنات الواجهة T056–T057 |
| Phase 4 | T068 · T069 · T070 (اختبارات) · T071 · T072 (مكوّنات) |
| Phase 5 | T081 · T082 · T086 · T087 · T088 · T091 · T096 · T097 |
| Phase 9 | T130–T134 (ملفات توثيق مختلفة) |

**قيد**: مهام تلمس الملف نفسه تبقى متسلسلة — `RecordPresencePing` يُنشأ في T063 ويُعدَّل في
T080، فلا توازي بينهما.

---

## Implementation Strategy

**MVP = Phase 1 + 2 + 3 (US1)**: منصة تعرف مواعيد دروسها ومن حجز فيها. قابلة للشحن وحدها
حتى لو أُديرت الحصة على أداة خارجية مؤقّتاً — وهي وحدها تسدّ الفجوة القائمة اليوم: جدول توفّر
منشور في السوق العام **لا يمكن الحجز فيه**.

**الزيادة الثانية (US2 + US3)**: الغرفة والحضور. هنا تُملأ عدّادات `teacher_profiles`
الأربعة القائمة بلا مُنتِج منذ 001، وتُصبح درجة الثقة مبنيّة على بيانات حقيقية.

**الزيادة الثالثة (US4 + US5)**: حزمة الحصة الكاملة — تسجيل محمي وتقرير لوليّ الأمر.

**الزيادة الرابعة (US6)**: التجميد. أخيراً لأنه يعدّل سلوك كل ما سبقه، وبناؤه أولاً يعني
كتابة حراسه في كود لم يوجد بعد.

**قاعدة سارية على كل زيادة**: لا تُعلَن مُنجَزة قبل أن يمشي اختبار e2e إلى شاشاتها **من
التنقّل**، وقبل أن يكون لكل نقطة نهاية جديدة مستدعٍ أو سبب مكتوب لغيابه.
