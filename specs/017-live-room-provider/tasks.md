# Tasks: غرفة البثّ الحيّة — تنفيذ المزوّد (‏017 `live-room-provider`)

**Input**: `specs/017-live-room-provider/` — `plan.md` · `spec.md` · `research.md` ·
`data-model.md` · `contracts/` · `quickstart.md`

**Tests**: **مطلوبة.** ستّةٌ من سبعة معايير نجاح تُقاس آلياً (`SC-001` · `SC-002` ·
`SC-003` · `SC-004` · `SC-006` · `SC-007`)، و`FR-004` يوجب أن يُحاسَب التنفيذ بـ
`BroadcastProviderContractTest` القائم.

**Organization**: بالقصص الثلاث. **وكلٌّ منها تُسلَّم والبوابةُ خضراء** — انظر «آليّة
القدرات» أدناه، فهي ما يجعل ذلك ممكناً بلا فرعٍ نصفِ مبنيّ.

---

## الشكل: `[ID] [P?] [Story] الوصف + المسار`

- **[P]** — ملفٌّ مختلف وبلا تبعية على مهمّةٍ ناقصة.
- **[Story]** — في أطوار القصص وحدها.
- **⚠️ مهامّ `LiveKitBroadcastProvider.php` لا تحمل `[P]` أبداً** — ملفٌّ واحد تكتبه ثلاث
  قصص بالتناوب.

---

## ⚠️ آليّة القدرات: كيف تبقى البوابة خضراء في كلّ خطوة

`BroadcastProviderContractTest` يحاسب كلّ مزوّدٍ **على ما يدّعيه، لا أكثر**. فالمُهايئ
يُولَد في الطور الثاني وكلُّ قدراته `false`، و**كلّ قصّةٍ تقلب قدرتها إلى `true` في نفس
المهمّة التي تنفّذها**:

| القصة | تقلب | الدالّة التي تُنفَّذ |
|---|---|---|
| **US1** | `liveMedia` · `screenShare` | `createRoom` · `issueTicket` · `closeRoom` |
| **US2** | `hostControls` | `hostAction` |
| **US3** | `recording` | ‏`Egress` في `createRoom` · `recording()` |

**فالمُهايئ صادقٌ عن نفسه في كلّ لحظة**، ودمجُ `US1` وحدها ينتج مزوّداً يعمل بصوتٍ وصورة
ويرفض ضوابط المضيف بصوتٍ عالٍ — لا مزوّداً يكذب. وهذا بالضبط ما بُنيت له `BroadcastCapabilities`
في ‎٠٠٥‎.

---

## ⚠️ ما لا يُبنى، مكتوباً هنا كي لا يُبنى سهواً

| ما قد يبدو مطلوباً | القرار | السند |
|---|---|---|
| هجرةٌ أو عمودٌ أو جدول | **لا مهمّة له** — الأعمدة الستّ كافية | `spec.md` §Key Entities · `data-model.md` |
| عمود `egress_id` | **لا مهمّة له** — التصدير تلقائيّ ويُقرَأ بـ`listEgress($roomName)` | `research.md` §R6 |
| `WebhookReceiver` ومسارٌ عامّ لأحداث المزوّد | **لا مهمّة له** — `FR-013`/`FR-014` سحبٌ لا دفع | `research.md` §R6 |
| جدول `session_bans` | **لا مهمّة له** — نافذةُ عشر دقائق يغلقها إلغاءُ الحجز | `research.md` §R9 |
| تخزين التذكرة | **ممنوع** — الأهليّة تُراجَع عند كلّ إصدار | `FR-008` |
| `trackSid` في `hostAction()` | **ممنوع** — يُسرّب مفردات المزوّد إلى العقد | `research.md` §R8 |
| قراءة الحضور من حدثٍ أو خطّافٍ من المزوّد | **ممنوع صراحةً** | `FR-015` |
| صلاحيةٌ جديدة · سياسةٌ جديدة · مسار API جديد | **لا مهمّة له** — `SESSIONS_HOST` و`ClassSessionPolicy::host()` مشحونتان | `plan.md` §Constitution |
| `BunnyMediaProvider` ورفعٌ إلى باني | **خارج النطاق** — إتمامُ ‎٠٠٤‎، يليها مباشرةً | `Q8` · `research.md` §R15‑د |
| كودُ حذفٍ من R2 | **لا مهمّة له** — قاعدةُ دورة حياةٍ في الدلو | `contracts/configuration.md` |
| ‏`room.auto_create: false` | **لا مهمّة له** — النشرة سحابيّة، والحارس حارسُنا | `Q7` · `FR-009` |

---

## Phase 1: Setup — التبعيات والإعدادات

**Purpose**: ما تحتاجه كلّ قصّةٍ بعدها، ولا يغيّر سلوكاً قائماً.

- [X] T001 ثبّت حزمة الخادم: `composer require agence104/livekit-server-sdk` من `backend/`، وتحقّق أن `composer.lock` تغيّر وأن `./vendor/bin/phpstan analyse` ما زال نظيفاً. ⚠️ **الاسم بلا لاحقة `-php`** (‏كانت الوثائق تسمّيه `…-sdk-php` وهي حزمةٌ غير موجودة على Packagist — صُحِّح في ‎2026-08-16). ⚠️ **وعلى ويندوز يلزم** `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`: ‏`laravel/horizon` يشترطهما وهما إضافتان يونكسيّتان، فيرفض composer الحلَّ كلّه لسببٍ لا علاقة له بالحزمة المطلوبة
- [X] T002 [P] ثبّت حزمتَي الواجهة: `npm install @livekit/components-react livekit-client` من `frontend/`
- [X] T003 في `backend/config/sessions.php` أضف `ticket_ttl_minutes => 10` و`max_participants => 50` وكتلة `livekit` (‏`url` · `key` · `secret` · `egress` بخمسة مفاتيح من `env()` بلا قيمةٍ محليّة) — الشكل الكامل في `contracts/configuration.md` §ب
- [X] T004 [P] في `backend/.env.example` أضف `BROADCAST_PROVIDER` · `LIVEKIT_URL` · `LIVEKIT_API_KEY` · `LIVEKIT_API_SECRET` · `LIVEKIT_EGRESS_{BUCKET,ENDPOINT,REGION,KEY,SECRET}` — **أسماءً بلا قيمةٍ واحدة** (`FR-005`)
- [X] T005 في `backend/app/Modules/LiveSessions/Support/SessionSettings.php` أضف `ticketTtlMinutes()` و`maxParticipants()` تقرآن من `PlatformSettings` بالاحتياط من `config('sessions.*')` — نفس شكل الدوالّ العشر القائمة، **ولا `config()` من داخل Action**

---

## Phase 2: Foundational — المُهايئ يُولَد صادقاً

**Purpose**: الملفّ والربط والبوابة. ⚠️ **حاجزٌ يسبق كلّ القصص.**

- [X] T006 أنشئ `backend/app/Modules/LiveSessions/Providers/LiveKitBroadcastProvider.php` مُنفِّذاً `BroadcastProviderInterface`: `identifier()` تُعيد `'livekit'`، و`capabilities()` تُعيد **الأربع `false`** و`maxParticipants` من `SessionSettings`. ⚠️ **هذا هو الملفّ الوحيد الذي يستورد `Agence104\LiveKit\*`** (`FR-002`)

  ⚠️ **صُحِّح أثناء التنفيذ (‏2026-08-16): «والخمس الباقيات ترمي `UnsupportedCapability`» كان
  خطأً يُسقط `T009` في نفس اللحظة.** ‏`BroadcastProviderContractTest` يشترط القدرة على
  `hostAction` **وحدها**؛ أمّا `createRoom` و`issueTicket` و`closeRoom` و`recording` فيُحاسَب
  عليها كلُّ مزوّدٍ **بلا شرط** (‏غرفةٌ مُتماثلة الأثر · تذكرةٌ تنتهي · إغلاقٌ مرّتين ·
  `null` لا استثناء). فلو رمت الخمس، لسقطت خمسُ حالاتٍ من ستّ. **فالأربع تُكتب كاملةً هنا**
  (‏أي `T011`–`T015` و`T033`) و`hostAction` وحدها ترمي حتى `US2`. **والقدرات تبقى `false`**،
  فالمُهايئ صادقٌ عن نفسه كما تقتضي آليّة القدرات — ما تغيّر هو **متى** يُكتب الجسد، لا متى
  يُعلَن الادّعاء
- [X] T007 في نفس الملفّ: ابنِ `RoomServiceClient` و`EgressServiceClient` **كسولاً وقابلَين للحقن في المُنشئ** — بدونها لا يمرّ اختبارُ المُهايئ بلا شبكة (`SC-006` · `research.md` §R11)
- [X] T008 في `backend/app/Modules/LiveSessions/LiveSessionsServiceProvider.php` أضف ذراع `match` واحدة: `'livekit' => $this->app->make(LiveKitBroadcastProvider::class)` قبل `default` — ⚠️ **ولا تغيّر `default`**: بيئة الاختبار تبقى على `NullBroadcastProvider` (`FR-017`)
- [X] T009 في `backend/tests/Feature/LiveSessions/BroadcastProviderContractTest.php` أضف `'livekit' => fn () => new LiveKitBroadcastProvider(...)` إلى `dataset('broadcastProviders')` — **تمرّ خضراء من هذه اللحظة** لأن القدرات صادقة (`FR-004`)
- [X] T010 ⚠️ في نفس الملفّ **شُدّ تأكيداً قائماً**: `expect($ticket->expiresAt->diffInHours(now()))->toBeLessThan(24)` تصير «‏لا تتجاوز `SessionSettings::ticketTtlMinutes()` بهامش دقيقة». ⚠️ **لا رقماً حرفياً**: المدّة صفٌّ في `platform_settings` يعدّله مشغّل (`FR-007`)، فتأكيدٌ على ‎١٥‎ يُحمّر البوّابة على إعدادٍ مشروع. المكتبة افتراضها **٦ ساعات**، فـ`setTtl` منسيّةً تشحن خضراء بتذكرةٍ تفتح الغرفة بعد الحصّة بستّ ساعات (`research.md` §R4)

**Checkpoint**: البوابات الأربع خضراء، والمُهايئ مربوطٌ ويرفض كلّ شيءٍ بصدق.

---

## Phase 3: User Story 1 — سارة ترى أستاذها وتُسمَع (P1) 🎯 MVP

**Goal**: صوتٌ وصورةٌ في الاتجاهين داخل صفحتنا، والحضور بنفس أرقامه.

**Independent Test**: حصّةٌ بمقعدين وجهازَين، صوتٌ وصورةٌ متبادلان، وكشفُ حضورٍ **مطابقٌ**
لما ينتجه `NullBroadcastProvider` بنفس النبضات (`SC-001`).

- [X] T011 [US1] في `LiveKitBroadcastProvider::createRoom()`: `RoomServiceClient::createRoom(RoomCreateOptions)` باسمٍ حتميّ `"session-{$session->uuid}"`، و`setMaxParticipants()` من `SessionSettings` (‏`FR-003` — **يُمرَّر للمزوّد لا يُعلَن فقط**)، وأعِد `RoomHandle(providerRoomId: الاسم, joinBaseUrl: config('sessions.livekit.url'))`
- [X] T012 [US1] ⚠️ في نفس الدالّة: `setEmptyTimeout()` و`setDepartureTimeout()` **محسوبتَين من الحصّة** — `joinWindow + duration + joinWindow` بالثواني. رقمٌ ثابت يعني حصّةَ ثلاث ساعات تُغلق غرفتُها في منتصفها (`research.md` §R5)
- [X] T013 [US1] في `LiveKitBroadcastProvider::issueTicket()`: `AccessToken` بـ`AccessTokenOptions->setIdentity($user->uuid)` (‏`FR-006` — **الـuuid لا الاسم**) و`->setTtl()` من `SessionSettings::ticketTtlMinutes()` (‏`FR-007`)، ومنحة `VideoGrant->setRoomJoin()->setRoomName("session-{$session->uuid}")->setCanPublish(true)->setRoomAdmin($role === ParticipantRole::Host)` (‏`FR-010`)
- [X] T014 [US1] ⚠️ في نفس الدالّة: **صفر نداءٍ شبكيّ**. لا `ensureRoom` ولا أيّ لمسٍ لعملاء المكتبة — `BroadcastController::presence()` يُعيد تشغيل `IssueJoinTicket` عند كلّ نبضة، فنداءٌ هنا يجعل انقطاعَ LiveKit يُرجع ‎٤٠٣‎ لكلّ نبضة **فيُحتسب الصفّ كلّه غائباً** (`research.md` §R3 · `FR-015`)
- [X] T015 [US1] في `LiveKitBroadcastProvider::closeRoom()`: `RoomServiceClient::deleteRoom("session-{$session->uuid}")`، **مُتماثلَ الأثر** — يُبتلَع خطأ «‏الغرفة غير موجودة» وحده، لا كلّ خطأ
- [X] T016 [US1] اقلب `liveMedia` و`screenShare` إلى `true` في `capabilities()` — الآن صارتا صحيحتَين
- [X] T017 [P] [US1] أنشئ `backend/tests/Feature/LiveSessions/LiveKitAdapterTest.php`: **فُكّ الـJWT وافحصه** — `roomJoin` لتلك الغرفة وحدها · الهويّة `users.uuid` · المدّة من الإعدادات لا ‎٦‎ ساعات · `roomAdmin` للمضيف وحده · ولا `api_secret` في أيّ حقل (`SC-005`)
- [X] T018 [US1] في نفس الملفّ: **اختبارٌ يؤكّد أن `issueTicket()` لا يستدعي عميلاً محقوناً ولو مرّة** — هذا هو `T014` مكتوباً كبوّابة، وبدونه يعود الإغراء في أوّل إعادة تشكيل
- [X] T019 [US1] في `frontend/src/components/sessions/BroadcastStage.tsx` استبدل لوحة الحالة بـ`<LiveKitRoom serverUrl={ticket.room_url} token={ticket.token}>` وتخطيطِ مشاركين من `@livekit/components-react`. ⚠️ **الألوان من `@theme` والاتجاه بخصائص منطقية** — لا `bg-gray-*` ولا `ml-*` (`CLAUDE.md`)
- [X] T020 [US1] ⚠️ حدّث تعليق `BroadcastStage.tsx` الذي يقول «‏اليوم لوحة حالة، وغداً غلافُ مكتبة مزوّد» — صار اليوم. تعليقٌ يصف ماضياً هو تعليقٌ يُصدَّق ويُضلّل
- [X] T021 [US1] ⚠️ **لا تلمس** `page.tsx` ولا `PresenceLoop.tsx` ولا `lib/class-sessions.ts` — النبضة حلقةٌ مستقلّة عن اتصال الوسائط، وذلك ما يجعل `SC-001` قابلاً للقياس
- [X] T022 [US1] شغّل `RoomAccessTest` و`PresenceAggregationTest` و`AttendanceLadderTest` القائمة وتحقّق أنها **خضراء بلا تعديل سطرٍ فيها** — أيّ تعديلٍ هنا نقضٌ لـ`FR-016`
- [X] T022أ [P] [US1] ⚠️ أنشئ `backend/tests/Feature/LiveSessions/TicketAfterCloseTest.php` — **الحارس (ب) في `FR-009`، وهو دليل `SC-003` كلّه**: طالبٌ بمقعد ينضمّ · المدرّس يُنهي بـ`host/end` · **نبضته `presence` التالية تُرفض** ولا يزيد `stay_seconds` ولا يظهر أثرٌ في الفوترة. ‏الحارس (أ) مغطّى فعلاً بـ`RoomAccessTest::'refuses every earlier ticket once the room is closed'` — **وملفٌّ جديد لا إضافةٌ إليه**، لأن `T022` يوجب بقاءه بلا تعديل سطر. ⚠️ **بدون هذه المهمّة تُشحن المخالفةُ الوحيدة المقبولة في المرحلة بلا دليلها**، ويصير جدولُ Complexity Tracking ادّعاءً (‏`plan.md` › Deferred Verification يَعِد هذا الاختبار حرفياً)

**Checkpoint**: ‏MVP قابل للتسليم. صوتٌ وصورةٌ وحضور — وضوابطُ المضيف ترفض بصوتٍ عالٍ.

---

## Phase 4: User Story 2 — خالد يُسكت ويُخرج ويشارك شاشته (P2)

**Goal**: الحصّة الجماعية تصير قابلةً للإدارة.

**Independent Test**: الأفعال الثلاثة تنجح من حساب المدرّس، وتُرفض ‎٤٠٣‎ من حساب طالب،
وكلٌّ منها يترك أثراً باسم فاعله.

- [X] T023 [US2] في `LiveKitBroadcastProvider::hostAction()` فرعُ `Mute`: `getParticipant($room, $user->uuid)` ثمّ `mutePublishedTrack()` **لكلّ مسار صوتٍ منشور**. ⚠️ `trackSid` يُحلّ **داخل المُهايئ** — توسيعُ توقيع الواجهة به يُلغي `FR-002` (`research.md` §R8)
- [X] T024 [US2] فرعُ `Remove`: `removeParticipant($room, $user->uuid)`
- [X] T025 [US2] ⚠️ **لا فرعَ لـ`End`** — `PerformHostAction` يحوّله إلى `CloseBroadcastRoom` قبل بلوغ الواجهة، وإضافةُ فرعٍ هنا تُنشئ مساراً ثانياً لإغلاقٍ واحد
- [X] T026 [US2] اقلب `hostControls` إلى `true` في `capabilities()`
- [X] T027 [P] [US2] في `LiveKitAdapterTest`: تأكّد أن الكتم يستعلم المشارك أوّلاً ثمّ يكتم **كلّ** مسار صوتٍ له، وأن الإخراج يمرّر الـuuid لا الاسم
- [X] T028 [US2] شغّل `HostControlsTest` القائم وتحقّق أنّ فرعه «‏مزوّدٌ بلا `hostControls` يُرجع ‎٥٠١‎» ما زال أخضر على `NullBroadcastProvider` — القدرتان تتعايشان، والاختبار يفصل بينهما
- [X] T029 [US2] في `frontend/src/components/sessions/BroadcastStage.tsx` أضف — **للمضيف وحده** — قائمة المشاركين بزرَّي «‏كتم» و«‏إخراج» تناديان `classSessions.host(uuid, action, targetUuid)` القائم. ⚠️ **الدور من `ticket.role` لا من حالةٍ في المتصفّح**
- [X] T030 [US2] عالِج ‎٥٠١‎ في تلك الأزرار عبر `userMessage()` من `lib/errors.ts` — **لا نصَّ خطأٍ خام** أمام المدرّس (`CLAUDE.md`)
- [X] T031 [US2] فعّل مشاركة الشاشة من مكوّنات `@livekit/components-react` في نفس الملفّ — لا نكتب `getDisplayMedia` بأيدينا

**Checkpoint**: الحصّة الجماعية مُدارة. والتسجيل ما زال يُعلَن `false` بصدق.

---

## Phase 5: User Story 3 — التسجيل يصير درساً محميّاً (P2)

**Goal**: حصّةٌ تُغلَق ⇒ ملفٌّ ⇒ درسٌ منشور بمنحةٍ وعلامةٍ مائية، بلا لمسة يد.

**Independent Test**: حصّةٌ تُغلَق ⇒ ملفٌّ يصل تخزيننا ⇒ درسٌ يفتحه صاحبُ المقعد ويُرفض
لغيره، وعلامته المائية تدور.

- [X] T032 [US3] في `LiveKitBroadcastProvider::createRoom()` أضف `RoomCreateOptions::setEgress(RoomEgress->setAutomatic(true)->setFile(EncodedFileOutput))` بوجهة **دلو R2** من `config('sessions.livekit.egress')` — التصدير يبدأ **مع فتح الغرفة** وتوقفه `deleteRoom` (`FR-011`، وبلا `startEgress`/`stopEgress` يدويّين)
- [X] T033 [US3] في `LiveKitBroadcastProvider::recording()`: `EgressServiceClient::listEgress($roomName)` → أوّل عنصرٍ حالته `EGRESS_COMPLETE` → `RecordingArtifact`. ⚠️ **وكلُّ ما عداه `null` لا استثناء** (`FR-013`) — «‏لم يكتمل بعد» هو الجواب المتوقَّع في أوّل نداءٍ بعد كلّ حصّة
- [X] T034 [US3] اقلب `recording` إلى `true` في `capabilities()`
- [X] T035 [US3] ⚠️ أنشئ `backend/app/Modules/LiveSessions/Jobs/RetryPendingRecordingsJob.php`: يلتقط الحصص `recording_status = 'pending'` التي لم تبلغ `recordingMaxAttempts()` و`room_closed_at` خلال آخر ‎٤٨‎ ساعة، ويُعيد إرسال `IngestSessionRecordingJob`. **لا منطقَ مكرَّراً، ولا `WorkspaceContext::set()`** (‏الدستور §I)
- [X] T036 [US3] في `backend/routes/console.php` أضف `Schedule::job(new RetryPendingRecordingsJob, 'maintenance')->everyFifteenMinutes()->withoutOverlapping();` — نفس إيقاع `ReleasePendingUnitsJob` الذي يقرأ نتيجتها، ونفس طابورها
- [X] T037 [US3] في `backend/app/Modules/LiveSessions/Jobs/IngestSessionRecordingJob.php` استبدل `Http::get()` ثمّ `Storage::put($response->body())` بـ**بثٍّ عبر `Http::sink()`** ومهلةٍ محسوبة من مدّة الحصّة لا ثابتِ ‎١٢٠‎ ثانية — تسجيلُ ساعتين يقارب الجيجابايت في ذاكرة عاملٍ واحد (`research.md` §R10). ⚠️ **واكتب فوقها لماذا تبقى بعد باني**: قاعدةُ الإطلاق (`T050`) تعني أن الإنتاج يمضي على `videos/fetch` بلا بايتٍ عبر عاملنا، فهذا المسار **احتياطُ أيّ مزوّدٍ بلا `ingestFromUrl` والتطويرِ المحلّي** — بلا هذا السطر يُحذف كميْتٍ أو يُصدَّق كمسارٍ حارّ
- [X] T038 [P] [US3] أنشئ `backend/tests/Feature/LiveSessions/RecordingRetryTest.php`: حصّةٌ انتهت · المزوّد يقول «‏لم يكتمل» · تُكتب `pending` والعدّاد يزيد · **المسح يُعيد الإرسال** · تتكرّر حتى الحدّ · تستقرّ `failed` ويُخطَر المدرّس. ⚠️ **يفشل على الشجرة كما هي، وذلك دليلُ العطب لا دليلُ خطأ الاختبار**
- [X] T039 [US3] في نفس الملفّ: تأكيدٌ أن `recording_attempts` **يتجاوز ‎١‎** — العدّاد لا يتحرّك اليوم لأن المُرسِل واحدٌ من `SessionCompleted` وحده (`research.md` §R7)
- [X] T040 [P] [US3] في `LiveKitAdapterTest`: `recording()` تُرجع `null` لحالات `EGRESS_STARTING`/`ACTIVE`/`ENDING` ولا ترمي، و`RecordingArtifact` مبنيّةٌ صحيحاً عند `EGRESS_COMPLETE`
- [X] T040أ [P] [US3] في `LiveKitAdapterTest`: **`FR-012` مؤكَّداً لا مُدّعى** — مضيفُ `RecordingArtifact::$downloadUrl` هو نقطةُ التصدير المضبوطة (‏دلونا)، فلا رابطَ مزوّدٍ يتسرّب. ‏سطرٌ واحد اليوم لأن الوجهة دلونا؛ وهو ما يُمسك تغييرَ الوجهة غداً مع `[٢]`
- [X] T041 [US3] شغّل `RecordingPublicationTest` و`RecordingPlacementTest` و`RecordingAccessTest` القائمة وتحقّق أنّها خضراء **بلا تعديل** — النشرُ درساً والاستحقاقُ بالمقعد مشحونان منذ ‎٠٠٥‎
- [X] T042 [US3] ⚠️ تحقّق أنّ `Settlement\Support\PackageCompletion` **لم يُمَسّ** وأنّ `AccrualTest` أخضر: قلبُ `recording` إلى `true` يجعله يحجز استحقاق المدرّس على كلّ حالةٍ غير نهائية — و`T035` هو ما يمنع «‏أجرٌ محجوز إلى الأبد»

**Checkpoint**: القدرات الأربع `true` وكلُّها صادقة. المرحلة مكتملة وظيفياً.

---

## Phase 6: Polish — القياس والتوثيق

- [X] T043 ⚠️ **قِس `SC-007` بالفرق لا بالادّعاء**: `git diff --stat master...HEAD` على `Modules/Payments` · `Modules/Settlement` · `LiveSessions/Actions` · `LiveSessions/Models` · و`Support/{BookingEligibility,AttendanceLadder,EloquentSessionAttendanceDirectory}.php` — **المتوقَّع: لا شيء**. (‏`Support/SessionSettings.php` مستثنىً عمداً: `T005` يضيف إليه دالّتين بنصّ `FR-003`/`FR-007`)
- [X] T043أ ⚠️ أنشئ `backend/tests/Feature/LiveSessions/ProviderNameContainmentTest.php`: يمشي على `Modules/LiveSessions/{Actions,Models,Http,Jobs,Policies}` و`Modules/Payments` باحثاً عن `livekit` أو `Agence104` — **يُفشل البناء عند أوّل تسرّب** (‏`FR-002`). نفس شكل `ProviderAgnosticTest` المشحون في الإشعارات. ⚠️ **والمستثنى مذكورٌ في الاختبار نفسه** بجدول `FR-002`: المُهايئ · ذراع `match` · `config` · `.env` · الـdataset · الواجهة
- [X] T044 [P] في `docs/README.md` حدّث سطر مزوّد البثّ من «‏`null` وحده» إلى `null` · `livekit`، وأضف `RetryPendingRecordingsJob` إلى جدول الوظائف المجدولة
- [X] T045 [P] في `CLAUDE.md` و`AGENTS.md` **معاً** أضف الفخّ الذي يستحقّ التدوين: «‏حلقةُ إعادة محاولة الاستيعاب لم تكن موجودة — `giveUpOrRetry` يكتب `pending` ويعود، والمُرسِل واحدٌ من `SessionCompleted`. والعطبُ كان صامتاً لأن `NullBroadcastProvider` يُعلن `recording: false` فتعود الوظيفة قبل الفرع؛ وثمنُه استحقاقُ مدرّسٍ محجوزٌ إلى الأبد عبر `PackageCompletion`»
- [X] T046 [P] في `CLAUDE.md` و`AGENTS.md` أضف الثاني: «‏`AccessToken` افتراضه ‎٦‎ ساعات، والتأكيد القائم كان `< 24h` — فمدّةٌ منسيّة تشحن خضراء»
- [X] T047 ⚠️ **لا تُحدَّث `docs/erd.md`** — صفر هجرة وصفر عمود. تحديثه بلا تغييرٍ في المخطّط يُنتج فرقاً يوهم المراجع
- [X] T048 شغّل البوابات الأربع من الجذر: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` — ⚠️ **و`BROADCAST_PROVIDER` غير مضبوط**، فالحزمة تمرّ بلا شبكةٍ ولا حساب (`SC-006`)
- [X] T049 [P] شُغِّل بإذن المالك (‏2026-08-16) بعد إيقاف خادمَيه، وبـ`PHP_CLI_SERVER_WORKERS=8`: **‏641 ناجحة · 194 متخطّاة · 27 فاشلة**.

  ✅ **ومواصفات الغرفة خضراء**، فتغييرُ `BroadcastStage` لم يكسر شيئاً.

  ⚠️ **والسبع والعشرون سابقةٌ لهذه المرحلة ولا صلة لها بها** — مُثبَتٌ لا مُدَّعى: الفشل كلُّه في `billing.spec.ts` و`sessions.spec.ts` و`discovery.spec.ts`، و`git status` يُظهر أنّ ‎٠١٧‎ لم تلمس في الواجهة إلّا `BroadcastStage.tsx` وسطراً في `room/page.tsx` — و`(shell)/layout.tsx` (‏الشريط الجانبي) **لم يُمَسّ**.

  **والسبب من `error-context.md` لا من التخمين**: الشريط الجانبي يُرشّح روابطه بالصلاحية (`permission: P.billingBalanceView` وأخواتها)، والحزمة تعمل بـ`storageState` **الطالب** — فالروابط «‏أرصدة الطلاب» و«‏وضع الامتحانات» و«‏حصصي» غير موجودة أصلاً في DOM، والنقر ينتظر ‎٣٠‎ ثانية. والاختبار نفسه يقول في تعليقه إنّ الطالب **لا** يملك `BILLING_BALANCE_VIEW` ويتوقّع حالة «‏تعذّر» من الصفحة — أي أنه كُتب على افتراض أنّ الشريط يعرض كلّ رابطٍ والصفحةَ هي التي ترفض. **إصلاحه إمّا `test.use({ storageState: TEACHER_FILE })` أو `page.goto` مباشرةً بدل النقر** — وهو من عهدة ‎٠٠٦‎. مع الخادمين، وتحقّق أنّ مواصفات الغرفة القائمة خضراء بعد تغيير `BroadcastStage`
- [X] T050 ⚠️ **دوّن قاعدة الإطلاق حيث تُقرأ وقت النشر**: في `backend/.env.example` فوق `BROADCAST_PROVIDER` — «‏لا يُضبط `livekit` في الإنتاج قبل `MEDIA_PROVIDER=bunny`؛ مسارُ الاستيعاب اليوم ينزّل الملفّ إلى قرصنا» (`Q8`)
- [X] T051 ✅ **مُشيت في ٢٠٢٦-٠٨-٢٦ مقابل مشروع LiveKit Cloud حيٍّ ودلوِ R2 حيّ، بكاميرا ومايك حقيقيَّين بإذنٍ صريح — ووجدت أربعةَ عيوبٍ لم يرَ أيّاً منها ١٩٢٦ اختباراً**: لا طالبٍ حقيقيٍّ كان يقدر يحجز حصّةً أو يفتح درساً أو يعرف لماذا مُنع (`users.last_workspace_id` عمودٌ لا يكتبه شيءٌ في مسارِ الطالب، فـ`belongsToCurrentWorkspace()` ترفض صاحبَ الصفِّ نفسَه) · وكتمُ مشاركٍ غادر كان ‎٥٠٠‎ · وانقطاعُ المزوّدِ كان ‎٥٠٠‎ بخطأِ cURL يسمّي مضيفَنا الداخليّ · ورابطُ مصدرِ التسجيلِ لم يكن موقَّعاً وكان مزوّدٌ واحدٌ يستره. **عشرٌ من الاثنتَي عشرةَ خضراء**؛ البقيّةُ مؤجَّلةٌ بسببٍ مكتوب: مشاركةُ الشاشة (‏حوارٌ أصليٌّ يحتاج يدَ المالك) ونصفُ الجهازِ الثاني (‏الكاميرا تحتاج HTTPS على أصلٍ غيرِ `localhost`). ورجلُ Bunny وحدَها محجوبةٌ على تجديدِ الحساب (‎٤٠١‎ منذ ٢٠٢٦-٠٨-٢٥) — والأنبوبةُ أُثبتت كاملةً بالمزوّدِ المحلّيّ على ملفٍّ حقيقيٍّ من ٣٩٨ م.ب. الجدولُ الكاملُ في `plan.md` › `T051`. النصّ الأصليّ: نفّذ الخطوات الاثنتَي عشرة اليدوية في `quickstart.md` §ج مقابل مشروع LiveKit Cloud ودلو R2، وسجّل نتائجها في جدول Deferred Verification في `plan.md`

---

## Dependencies

```text
Phase 1 (Setup)  ──►  Phase 2 (Foundational)  ──►  ┌─ Phase 3 (US1, P1) ─┐
                                                    ├─ Phase 4 (US2, P2) ─┤──► Phase 6
                                                    └─ Phase 5 (US3, P2) ─┘
```

- **US1 · US2 · US3 مستقلّاتٌ منطقياً** — كلٌّ تكتب دالّةً مختلفة وتقلب قدرةً مختلفة.
- **وغيرُ متوازيةٍ عملياً**: ثلاثتُها تكتب `LiveKitBroadcastProvider.php`. تُنفَّذ بالترتيب،
  أو في فروعٍ يُدمَج تعارضُها يدوياً — والأوّل أرخص.
- **US3 يعتمد على `T032` داخل `createRoom` التي يكتبها `T011`** (‏US1) — التبعيّة الوحيدة
  بين القصص، وهي على سطرٍ واحد.

---

## Parallel Opportunities

| الطور | متوازٍ |
|---|---|
| Setup | `T002` (‏الواجهة) مع `T001` (‏الخلفية) · `T004` مع `T003` |
| US1 | `T017` و`T022أ` مع مهامّ الواجهة `T019`–`T021` (‏ثلاثة ملفّاتٍ مختلفة) |
| US2 | `T027` مع `T029`–`T031` |
| US3 | `T038` و`T040` مع بعضهما ومع `T036` |
| Polish | `T044`–`T047` أربعتُها · و`T049` مع `T048` |

⚠️ **ولا توازي داخل `LiveKitBroadcastProvider.php`** — `T011`–`T016` · `T023`–`T026` ·
`T032`–`T034` سلسلةٌ واحدة.

---

## Implementation Strategy

**MVP = Phase 1 + 2 + 3** (‏`T001`–`T022`). ينتج مزوّداً بصوتٍ وصورة وحضورٍ سليم، يُعلن
ضوابط المضيف والتسجيل `false` **ويرفضهما بصوتٍ عالٍ**. قابلٌ للدمج والتسليم كما هو.

ثمّ **US2** (‏الحصّة الجماعية مُدارة)، ثمّ **US3** (‏التسجيل درساً).

⚠️ **وقاعدةُ الإطلاق تعلو على اكتمال الأطوار**: `BROADCAST_PROVIDER=livekit` لا يُضبط في
الإنتاج قبل `MEDIA_PROVIDER=bunny`، مهما بلغت المرحلة (`Q8` · `T050`).
