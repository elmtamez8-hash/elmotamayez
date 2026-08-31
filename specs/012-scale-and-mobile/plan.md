# Implementation Plan: التوسّع والتطبيق

**Branch**: `012-scale-and-mobile` | **Date**: 2026-08-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/012-scale-and-mobile/spec.md`

> **مُراجَعٌ بخمسةِ وكلاءَ في 2026-08-29** (تناقُضات · معمار · N+1 · حماية · تزامُن) قبل
> `/speckit-tasks`. النسخةُ الأولى حملت أربعةَ ادّعاءاتٍ كاذبةٍ عن الكودِ وثلاثةَ أعطالٍ
> تصميميّة؛ كلُّها مصحَّحةٌ، و[`research.md › R19`](./research.md) يجمعها **بأسبابِها**.

---

## Summary

ثلاثُ قصصٍ من خمس. حسمُ `Session 2026-08-29` أسقط **US4 (المقاصة الآلية)** لانعدامِ adapter
بوّابةٍ في الشجرة — جوابٌ مقروءٌ من `backend/config/payments.php` لا مفترَض — وأجّل
**US5 (التطبيق الأصلي)** بقرارِ المالك.

- **US1 · المسار التكيّفي** — جلسةٌ تُغيّر صعوبةَ السؤالِ التالي بأداءِ الطالب، تُبنى **داخل
  `Assessments`** وتُعيد استعمالَ `Attempt(is_practice)` و`AttemptItem` و`Answer`: دفترُ
  الأخطاءِ مشتقٌّ من `exam_answers` وحدَها، فجدولٌ موازٍ يفي بنصفِ FR-005 ويُسقط نصفَه بصمت.
  والإتقانُ يُقاس عند **أعلى صعوبةٍ متاحةٍ في الفكرة** لا عند `hard` — وإلّا لم تبلغْه أبداً
  فكرةٌ بصعوبةٍ واحدة، وهي الحالةُ الحدّيةُ التي تسمّيها المواصفةُ بنصِّها.
- **US2 · الويب كتطبيق (PWA)** — `app/manifest.ts` وعاملُ خدمةٍ بقرارِ تخزينٍ **دالّةً خالصةً
  في وحدتها** (فيصير SC-005 اختبارَ vitest)، وقناةُ `WebPushChannel` خلفَ العقدِ القائم —
  **خارجَ `defaultChannels()`**، والاشتراكُ هو ما يكتب التفضيل.
- **US3 · غرف المذاكرة** — ورقةٌ مجمَّدةٌ وقتَ الإنشاء، وعدّادٌ، ولوحةٌ تُبَثّ على قناةٍ
  **خاصّةٍ** باسمٍ مستقلّ. إغلاقُها مشتقٌّ من الساعة، **وحدثُ الإنهاءِ له كاتبٌ صريح**.

الجديدُ ستّةُ جداولَ وتبعيّةُ تعميةٍ واحدة. لا وحدةَ جديدة، ولا وحدةَ تُضاف إلى `phpstan.neon`.

---

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript 5 (Next.js 15 App Router)

**Primary Dependencies**: القائمُ كما هو. **واحدةٌ جديدة**: `minishlink/web-push`.

**Storage**: MySQL في الإنتاج · SQLite في التطوير والاختبار. ستّةُ جداولَ جديدة.

**Testing**: Pest (Feature أساساً) · vitest + jsdom · Playwright لما هو من طرفٍ إلى طرف.
**⚠️ لا تشغيلَ لمجموعةٍ كاملةٍ محليّاً** — أمرٌ قائمٌ من المالك؛ الكاملُ على CI.

**Target Platform**: خادمُ Linux + متصفّحُ هاتفٍ حديث. الإشعارُ الفوريُّ يعمل في
Chrome/Edge/Firefox، وعلى iOS **من 16.4 وللمثبَّت على الشاشةِ الرئيسيةِ فقط** — قيدُ منصّةٍ
يُذكَر للمستخدم، وFR-035 يوجب ألّا يعطّل رفضُ الإذنِ بقيّةَ المنتج.

**Performance Goals**: SC-006 — اللوحةُ خلال **٢ ثانية p95**. NFR-013 — اختيارُ السؤالِ التالي
**لا ينمو مع حجمِ البنك**، ⚠️ **لا عدداً ولا حمولةً**.

**Constraints**: SC-005 صفرُ أصلٍ محميٍّ مخزَّن · SC-003 صفرُ نتيجةٍ تدخل الدرجاتِ الرسمية ·
SC-017 صفرُ تسريبٍ بين مساحاتِ العمل.

**Scale/Scope**: ستّةُ جداول · **١٢** نقطةَ نهاية · قناةُ بثٍّ واحدة · قناةُ إشعارٍ واحدة ·
مُحدِّدانِ جديدان · أربعُ شاشاتٍ جديدة.

---

## Constitution Check

*GATE: قبل المرحلة 0، ويُعاد بعد المرحلة 1.* — الدستور **v1.2.0**.

| المبدأ | الحال | كيف |
|---|---|---|
| **I — عزل المستأجرين** | ✅ | التصنيفُ معلَنٌ لكلِّ جدول: خمسةُ **جسور** + واحدٌ **منصّيٌّ (أ)**. ⚠️ **ولكلِّ جسرٍ اختباران**: اختبارُ السمة، واختبارُ الشرطِ الصريحِ في الـAction — `WorkspaceScope` عديمُ الأثرِ لكلِّ طالب، فاختبارُ السمةِ وحدَه يمرّ بلا جهدٍ بينما الحارسُ الحقيقيُّ غيرُ مختبَر |
| **I — حارسُ رؤيةِ المدرّس** | ✅ | لا مسارَ يقرأ فيه مدرّسٌ صفَّ طالب. الأهليّةُ كلُّها عبر `PracticePool` ← `EnrollmentDirectory` |
| **II — المنطق في الـActions** | ✅ | السُّلَّمُ ومعيارُ الإتقانِ وأهليّةُ الغرفةِ والمفتاحُ كلُّها في الـAction |
| **III — استقلالُ الوحدات** | ⚠️ **بحافّةٍ معلَنة** | لا وحدةَ جديدة، والخروجُ إلى التلعيبِ والإشعاراتِ بأحداثٍ فقط. **لكنّ `Assessments` تصل إلى نماذجِ `Courses` و`Learning` و`Marketplace` سلفاً** (`PracticePool.php:10`، ~٢٠ استيراداً)، وهذه المرحلةُ تضيف حافّةً ثالثةً إلى `Tenancy\Support\Flags`. `Tenancy\Support` مشتركٌ بحكمِ الواقع (`Permissions` ×٢٢) — الحافّةُ **تُعلَن** بدل أن تُخفى خلف ✅ |
| **IV — البوّاباتُ خضراء** | ⚠️ جزئيّاً | pint · phpstan (L8، بلا baseline) · tsc · vitest محليّاً؛ **pest مستهدَفاً فقط** بأمرِ المالك، والكاملُ على CI |
| **V — التفويضُ بالسياساتِ والثوابت** | ✅ | لا صلاحيّةً جديدة: الحارسُ أهليّةٌ وملكيّةُ صفٍّ، لا اسمُ إذن. ⚠️ **قرارٌ يُسجَّل**: محاولاتُ الجلسةِ والغرفةِ صفوفُ `is_practice` في مساحةِ المدرّس، فحاملُ `ATTEMPTS_VIEW_ALL` يقرؤها بفرعِ `teaches()` في `AttemptPolicy`. مقبولٌ — لكنّه قرارٌ يُذكَر لا يُورَّث |
| **VI — العقودُ الظاهرة** | ✅ | `HasUuid` وكشفُ الـuuid · `strict_types` · DTO يرث `DataTransferObject` · لا تغييرَ كاسر. ⚠️ **واستثناءُ الخيارِ داخلَ سؤالٍ معلَن**: `sitOptionFields()` هو `['id','content']` ويؤكّده `AssessmentExposureTest` |
| **بيئة** | ✅ | لا لغةَ ولا إطارَ جديد. الأعمدةُ مراجَعةٌ مقابلَ MySQL الصارم — **مرشَّحا فيضٍ** مسقوفانِ في الـAction |
| **سيرُ العمل** | ✅ | صفرُ `[NEEDS CLARIFICATION]`، وقائمةُ الفحصِ ناجحة. **والتوثيقُ يتحرّك مع الكود**: `docs/README.md` و`docs/erd.md` في نطاقِ هذه المرحلة |

---

## Complexity Tracking

| المخالفة | لماذا لزمت الآن | البديلُ الأبسطُ ولماذا رُفِض |
|---|---|---|
| **حمولةُ بثِّ لوحةِ الغرفةِ تحمل بيانات**، خلافاً لقاعدةِ «مُعرِّفٌ فقط» | SC-006 يطلب ثانيتَين p95. واللوحةُ **هي محتوى الغرفة**، مُصرَّحٌ بها لكلِّ من فيها؛ والغرفةُ تموت بعد دقائقَ فنافذةُ «تصريحٌ لا يُسحَب» بقيّةُ عدّاد؛ **وقناةٌ خاصّةٌ** باسمٍ مستقلّ؛ والأسئلةُ تبقى على HTTP. تسقط الرخصةُ بسقوطِ أيٍّ من الأربعة | «بثُّ مُعرِّفٍ ثمّ جلبٌ من كلِّ متصفّح» هو عطلُ `ParticipantsPanel`: ٤٦٥ طلباً في دقيقتَين لغرفةٍ من ثلاثين — يُسقط SC-006 لا يحقّقه |
| **تبعيّةُ `minishlink/web-push`** | توقيعُ JWT بـES256 على P-256، وتشفيرُ `aes128gcm` باشتقاقِ ECDH لكلِّ مستلِم | كتابتُها يدوياً مسارُ تعمية، و«الأبسطُ الذي يعمل» لا يُطبَّق عليه — كما لا يُطبَّق على التحقّقِ من المدخلاتِ ولا على الأمان |
| **تعديلُ `GradeAttempt`** — مسارٌ حرجٌ قائم | استخراجُ `AnswerMarker` لازمٌ لئلّا يُنسَخ تصحيحُ الإجابةِ في مكانَين؛ وحارسُ «محاولةٌ تحمل إجاباتٍ سلفاً» يمنع عطباً دائماً يفتحه هذا التصميم | نسخُ منطقِ التصحيحِ في الـAction الجديد: هجاءانِ لقاعدةٍ واحدة، والثاني يُنسى عند أوّلِ تعديل |

**ما لا يُسجَّل هنا لأنّه ليس مخالفة**: تخزينُ الإتقانِ بدل اشتقاقِه. الاشتقاقُ صحيحٌ حيث
تكون مدخلاتُه ثابتةَ المعنى، وعتبةُ الإتقانِ صفٌّ يعدّله مشغّلٌ بنصِّ FR-007.

---

## Project Structure

### Documentation (this feature)

```text
specs/012-scale-and-mobile/
├── spec.md · plan.md · research.md · data-model.md · quickstart.md
├── contracts/api.md
├── checklists/requirements.md
└── tasks.md            # يُنتَج بـ/speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/Assessments/
├── Actions/
│   ├── StartAdaptiveSession.php      # يقرأ المفتاح، يطالب running_key، يحسب السقف
│   ├── AnswerAdaptiveStep.php        # يصحّح، يحرّك السُّلَّم، يقدّم التالي، يعلن الإتقان
│   ├── EndAdaptiveSession.php        # يختم المحاولةَ بنفسِه — لا GradeAttempt ولا AttemptFinalized
│   ├── ListAdaptiveConcepts.php      # GROUP BY واحد، لا حلقة
│   ├── CreateStudyRoom.php           # يجمّد المجموعة؛ النقصُ جوابٌ لا فشل
│   ├── JoinStudyRoom.php             # يطالب صفَّ المشاركةِ أوّلاً، ثمّ المحاولةَ وعناصرَها
│   ├── AnswerStudyRoomQuestion.php   # يصحّح، يبثّ، ويكتب StudyRoomFinished
│   ├── ListStudyRooms.php            # من study_room_participants عبر index(user_id, joined_at)
│   └── ReadStudyRoomBoard.php        # اللوحةُ عبر HTTP — الاحتياطُ بلا سوكِت
├── Support/
│   ├── AnswerMarker.php              # ⚠️ مُستخرَجٌ — يأخذ «ما أخطأ فيه سابقاً» مُعطاةً
│   ├── AdaptiveLadder.php            # اختيارُ التالي + FR-004 + السقف
│   ├── AdaptiveSettings.php          # عتباتٌ من platform_settings، بحدودٍ عند القراءة
│   ├── StudyRoomAccess.php           # ⚠️ قراءةٌ فقط — يناديها الانضمامُ والقناةُ معاً
│   └── AssessmentFieldAllowlist.php  # + الحمولاتُ الستُّ الجديدة
├── Data/                             # DTOs: AdaptiveStart · AdaptiveAnswer · StudyRoomDraft
├── Models/{AdaptiveSession,ConceptMastery,StudyRoom,StudyRoomQuestion,StudyRoomParticipant}.php
├── Enums/{Difficulty,AdaptiveStatus}.php    # Difficulty += rank/easier/harder
├── Events/{ConceptMastered,StudyRoomFinished}.php
├── Actions/GradeAttempt.php          # يستعمل AnswerMarker؛ ويرفض محاولةً محمَّلةً بإجابات
├── Http/{Controllers,Requests,Resources}/…
├── Database/Migrations/…             # خمسةُ جداول + فهارسُ created_at
└── routes/api.php

backend/app/Modules/Notifications/
├── Channels/WebPushChannel.php       # سطرُ ->tag('notification.channels') واحد
├── Models/PushSubscription.php       # منصّيٌّ (أ) — لا workspace_id
├── Actions/{SavePushSubscription,ForgetPushSubscription}.php
├── Support/NotificationsPersonalData.php    # + المشياتُ الأربع
├── Database/Migrations/…             # push_subscriptions + created_at
└── routes/api.php

backend/
├── config/{assessments.php,webpush.php}
├── app/Modules/Tenancy/Support/PlatformSettings.php   # + assessments.adaptive.*
├── app/Providers/AppServiceProvider.php               # + adaptive-step + study-room-write
├── routes/channels.php                                # + study-room-board.{uuid} (خاصّة)
├── lang/ar/validation.php                             # + عشرةُ حقول
├── database/factories/Modules/{Assessments,Notifications}/…   # ستّةُ مصانع
└── database/seeders/{GamificationCatalogSeeder,DataCategorySeeder,DataProcessorSeeder}.php

frontend/src/
├── app/manifest.ts
├── app/offline/page.tsx
├── app/(app)/(shell)/practice/adaptive/page.tsx
├── app/(app)/(shell)/study-rooms/{page.tsx,[uuid]/page.tsx}
├── components/practice/{AdaptiveRunner,StudyRoomBoard}.tsx
├── components/app/{ServiceWorkerRegistrar,PushPermissionPrompt}.tsx
├── lib/sw-cache-policy.ts + sw-cache-policy.test.ts   # ⚠️ دالّةٌ خالصة = SC-005
├── lib/service-worker.ts
├── lib/{adaptive.ts,study-rooms.ts,push.ts}
├── lib/labels.ts                                      # + وسومُ الصعوبةِ والحالة
└── lib/errors.ts                                      # + feature_off · room_closed · not_eligible

docs/{README.md,erd.md}               # قسمُ spec 012 في كليهما
```

**Structure Decision**: البنيةُ القائمةُ بلا تغيير. القصصُ الثلاثُ تسكن وحدتَين قائمتَين —
وحدةٌ ثالثةٌ **تزيد** الترابطَ ولا تقلّله: خمسةُ عقودٍ جديدةٍ في `Shared\Contracts` لمستهلكٍ
واحد.

**والواجهةُ تلتزم أعرافَها**: ألوانٌ من `@theme` حصراً — ⚠️ **أربعةُ رموزٍ غيرِ معرَّفةٍ
شُحنت قبلَ اليوم فطُليَ بها لا شيء**، وهذه المرحلةُ تضيف شاراتِ درجةٍ وشاراتِ صعوبة، وهو
بالضبط موضعُ تكرارِ العطل؛ و`components/ui/` بلا `className` حرّ؛ وخصائصُ منطقيّةٌ
(`ms-*`, `start-*`)؛ ووسومٌ عربيّةٌ من `labels.ts`؛ و`userMessage()`/`fieldErrors()` لكلِّ
رفض.

---

## ترتيبُ التنفيذ

1. **US1 — المسار التكيّفي.** أرخصُها وأعلاها عائداً بنصِّ المواصفة، وأخطرُ ما فيها استخراجُ
   `AnswerMarker` من `GradeAttempt` — مسٌّ لمسارٍ حرجٍ قائم — فيُنجَز أوّلاً تحت اختباراتِه
   القائمةِ كاملةً.
2. **US2 — الـPWA.** مستقلّةٌ تماماً، ونصفُها الخلفيُّ لا يمسّ شيئاً خارجَ `Notifications`.
3. **US3 — غرف المذاكرة.** آخرُها لأنّها الوحيدةُ التي تستفيد من الاثنَين: تصحيحُها هو
   `AnswerMarker` نفسُه، وإشعارُ «الغرفةُ بدأت» يركب القناةَ الجديدة.

---

## المخاطرُ الثمانِ التي تستحقُّ الذكر

1. **⚠️ محاولةٌ مُعطَبةٌ إلى الأبد.** `POST /attempts/{attempt}/submit` مربوطٌ ضمنيّاً
   ويُفوَّض بالملكيّة، ومحاولةُ الجلسةِ ملكُ الطالب. `GradeAttempt` يكتب صفَّ إجابةٍ لكلِّ
   عنصرٍ بـ`Answer::create()` ⇒ اصطدامٌ **مضمون** ⇒ `QueryException` ⇒ ٥٠٠. **والقاتل**:
   `claimForGrading()` **خارجَ** `DB::transaction()`، فالمعاملةُ تتراجع والمطالبةُ لا، وتبقى
   المحاولةُ عند `grading` **بلا كاتبٍ في الشجرةِ يعيدها**. الحارس: `exists()` على
   `exam_answers` **قبل** المطالبة ⇒ ٤٢٢؛ ومعه تحريرُ المطالبةِ في `catch` (عطلٌ سابقٌ لهذه
   المرحلةِ تجعله هي قابلاً للوصول).
2. **⚠️ غرفةٌ تسرّب امتحاناً لم يُقدَّم.** `withheldQuestionIds()` **لكلِّ طالبٍ على حدة**،
   والورقةُ تُجمَّد من مجمَّعِ **المضيف**. الحارس: مجمَّعُ **المنضمِّ** يحتوي كلَّ سؤالٍ
   مجمَّد — استعلامٌ واحدٌ عند الانضمام. ⚠️ **واختبارٌ يقيسه لا بدّ أن يبني مضيفاً قدّم
   امتحاناً ومنضمّاً لم يقدّمْه**؛ الاختبارُ عبرَ مساحتَي عملٍ يمرّ فوقَ العطلِ تماماً.
3. **⚠️ مفتاحٌ يردّ ٤٠٣ على الجميع.** `(int) null === 0` يخاطب صفَّ المنصّةِ المطفأ، وسياقُ
   الطالبِ `null` دائماً. الحارس: المعرّفُ من `teacher` أو من `study_rooms.workspace_id`؛
   والقراءتانِ بلا معاملٍ تُرشِّحانِ ولا ترفضان.
4. **⚠️ حدثٌ بلا كاتب.** `StudyRoomFinished` مربوطاً بحالةٍ مشتقّةٍ لا يُطلَق أبداً، ومفتاحُ
   التلعيبِ لا يُمنَح، و`AwardPoints` يعود صامتاً. الكاتب: `AnswerStudyRoomQuestion` عند
   إكمالِ المجموعة.
5. **⚠️ صفٌّ كتالوجيٌّ لا يصل قاعدةً قائمة.** سقط المستودعُ في ذلك ثلاثَ مرّات. الحارس: هجرةُ
   ردمٍ في التغيير نفسِه — `GamificationCatalogSeeder::seedMissing()` للمفتاحَين،
   و`DataCategorySeeder::run()` (`firstOrCreate` سلفاً) للفئاتِ الخمس، وصفُّ
   `data_processors` للدفعِ **وإلّا فشلَ البناءُ** (`ProcessorAllowlistTest` يشتقّ من الوسم).
6. **⚠️ اسمٌ فارغٌ يُدفَع إلى كلِّ مشترك.** `users` لا يحمل عمود `name`. الحارس: تحميلٌ
   بـ`first_name`/`last_name`، وتوكيدُ **اسمٍ غيرِ فارغ** لا عددِ صفوف.
7. **⚠️ رأسٌ منسوخٌ من دليلِ Next يُسقط كلَّ فيديو.** منطقةُ Bunny تردّ **403** على طلبٍ بلا
   `Referer` مهما صحّ توقيعُه. الحارس: الافتراضُ القائم `strict-origin-when-cross-origin`،
   والسببُ مكتوبٌ فوقَه.
8. **⚠️ عاملُ خدمةٍ بنطاقٍ ضيّقٍ يعمل ولا يفعل شيئاً.** عاملٌ يُخدَم من `/_next/static/…`
   نطاقُه ذلك المسارُ ما لم يُسجَّل بـ`scope: '/'` **ويحمل الردُّ `Service-Worker-Allowed: /`**.
   الحارس: **أوّلُ مهمّةٍ في القصّةِ هي قراءةُ النطاقِ المسجَّل** في
   `DevTools › Application › Service Workers`. تسجيلٌ صامتٌ بنطاقٍ ضيّقٍ يعني تطبيقاً مثبَّتاً
   لا يخزّن شيئاً ولا يستقبل دفعاً، بلا خطأ.
