# Implementation Plan: الحصص المباشرة والحضور والتجميد

**Branch**: `005-live-sessions-attendance` | **Date**: 2026-08-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/005-live-sessions-attendance/spec.md`

---

## Summary

المنصة تعرف الكورس والدرس المسجَّل ولا تعرف **الحصة** — الوحدة التي يدور حولها النموذج
التشغيلي كلّه. الدليل في الكود: `teacher_profiles` تحمل `completed_sessions_count` و
`attendance_rate` و`first_session_at` منذ 001 و**لا شيء يكتب فيها**، وجدول التوفّر الأسبوعي
منشور في السوق العام ولا يمكن الحجز فيه. هذه المرحلة تبني المُنتِج المفقود.

**المقاربة التقنية** — أربعة قرارات تحكم كل ما بعدها، وكلها مشتقّة من سوابق قائمة في المستودع:

1. **الحضور لا يمرّ بمزوّد البث.** مصدره نبض يرسله عميل الغرفة إلى مسارنا، والخادم يجمع
   (R3) — نفس شكل حلقة تجديد المنحة في 004. النتيجة: القصة الثالثة كاملةً — السُّلَّم الزمني
   والعتبة والتجميع والكشف — قابلة للبناء والإثبات **قبل** توقيع أي عقد بثّ.
2. **مزوّد البث خلف واجهة** بتنفيذ `NullBroadcastProvider` واحد يعلن قدراته صادقةً (R2)،
   بنفس شكل `VideoProviderInterface` و`PaymentProviderInterface` — وكلاهما شحن بتنفيذ واحد.
3. **تسجيل الحصة يصبح درساً** فيرث ضوابط 004 كاملةً بلا سطر حماية جديد، والأهلية تُوسَّع
   بعقد مشترك ثالث بجانب `EnrollmentDirectory` (R8).
4. **كل قرار زمني وظيفة مؤجّلة متماثلة الأثر**، لا مسحاً دورياً (R4): تجميد المقاعد عند مهلة
   الإلغاء، والغياب عند العتبة، والإغلاق عند النهاية، والتقرير بعده.

**الحجم**: ٦٣ متطلباً وظيفياً · ١٢ متطلباً غير وظيفي · ٢٥ معيار نجاح · ٦ قصص مستخدم.
وحدة خلفية جديدة، ٥ جداول، ٤ أحداث نطاق، ٦ صلاحيات، ٥ شاشات واجهة.

---

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript 5 (Next.js 15 App Router)

**Primary Dependencies**: صفر تبعية جديدة. `BroadcastProviderInterface` بتنفيذ محلّي بلا
شبكة، بنفس سبب `LocalVideoProvider` في 004. ولا SDK بثّ في الواجهة قبل توقيع المزوّد (R15).

**Storage**: MySQL في الإنتاج · SQLite محلياً وفي الاختبارات. الطوابير Redis عبر Horizon في
الإنتاج و`database` محلياً. تنبيه `CLAUDE.md` ساري: SQLite يقبل أي عدد صحيح في
`unsignedInteger` — أعمدة المقاعد والثواني تُراجَع مقابل MySQL الصارم.

**Testing**: Pest — اختبارات Feature هي شبكة الأمان. `FakeBroadcastProvider` يُربَط في
الاختبارات (NFR-011: صفر نداء شبكي حقيقي). الوظائف المؤجّلة تُختبر بـ`Queue::fake()` لإثبات
الدفع، وبتنفيذ مباشر لإثبات الأثر، وبـ`travelTo()` لإثبات **لحظة** الكتابة (SC-021).

**Target Platform**: خادم Linux (الهجرات في `Database/Migrations` بحرف M كبير — الخطأ
يحمّل صفر هجرات بصمت هناك وحده).

**Project Type**: أحادية معيارية Laravel + واجهة Next.js منفصلة.

**Performance Goals**: SC-011 — جدول شهر لمدرّس له ٢٠٠ حصة و٥٠ طالباً خلال ٥٠٠ms (p95)
بعدد استعلامات ثابت ≤ ١٥. النبض مسار ساخن: كتابة واحدة لكل نبضة لكل مشارك، بلا قراءات
مصاحبة.

**Constraints**: صفر تجاوز للمقاعد تحت أي تزامن (SC-001) · تسجيل `Absent` عند العتبة لا عند
النهاية (SC-021) · صفر أثر مالي لحالة الحضور (SC-024) · صفر تسريب بين مساحات العمل (SC-013).

**Scale/Scope**: وحدة `LiveSessions` جديدة · ٥ جداول · ٤ أحداث · ٦ صلاحيات · ٤ وظائف مؤجّلة
· ٢ نوع إشعار جديد · ٥ شاشات.

---

## Constitution Check

*GATE: يجب أن تمرّ قبل البحث، وتُعاد بعد التصميم.* المرجع: `.specify/memory/constitution.md` v1.1.0

### قبل التصميم

| المبدأ | الحالة | كيف |
|---|---|---|
| I — عزل المستأجرين | ✅ | تصنيف كل كيان في الجدول أدناه، وحالة في `WorkspaceIsolationTest` لكل كيان مملوك لمساحة عمل، واختبار حارس الرؤية لكل كيان مملوك للمنصة |
| II — المنطق في Actions | ✅ | قواعد الحجز والأهلية والحضور والتجميد كلها في `Actions/`؛ الأهلية تُقيَّم عند الحجز **وعند الدخول** (FR-046) في الـ Action نفسه |
| III — التكامل بالأحداث | ✅ | ٤ أحداث نطاق؛ `Media` تقرأ الأهلية بعقد مشترك لا بنموذج؛ الوحدة تُضاف إلى `phpstan.neon` |
| IV — البوابات خضراء | ✅ | الأربع + المسارات الحرجة الثمانية في `AGENTS.md` |
| V — التفويض بالسياسات | ✅ | ٦ ثوابت جديدة في `Permissions`، صفر اسم نصّي، سياسة لكل نموذج |
| VI — العقود الظاهرة | ✅ | `HasUuid` وكشف uuid فقط، `declare(strict_types=1)`، DTOs ترث `DataTransferObject`، مزوّد البث خلف واجهة |

### تصنيف الكيانات (المبدأ I — إلزامي)

| الكيان | الطبقة | الحارس |
|---|---|---|
| `ClassSession` | **مملوك لمساحة العمل** | `BelongsToWorkspace` + حالة في `WorkspaceIsolationTest` |
| `SessionBooking` | **جسر** | يحمل `workspace_id` للسياق ويشير إلى الطالب العام |
| `Attendance` | **جسر** | كذلك — والقراءة محروسة بملكية الطالب أو بالتسجيل عند المدرّس |
| `ClassSessionFeedback` | **جسر** | كذلك |
| `FreezePeriod` | **مملوك لمساحة العمل** | `BelongsToWorkspace`؛ الصفّ الفردي يشير إلى الطالب العام |
| **جدول الطالب** (`StudentSchedule`) | **مملوك للمنصة** | **ليس جدولاً** — مسار قراءة عابر لمساحات العمل، حارسه ملكية الطالب للصفوف. اختبار إلزامي: مدرّس **لا** يرى حصص طالبه عند غيره، والطالب يرى جدولاً **واحداً** عبر كل مدرّسيه |
| غرفة البث | **أعمدة على `ClassSession`** | لا جدول — الحصة لها غرفة واحدة، وجدول لعلاقة واحد-لواحد تجريد بلا مشكلة قائمة تبرّره |

**حارس رؤية المدرّس (NFR-001أ)**: كل قراءة يقوم بها مدرّس لصفّ يخصّ طالباً تمرّ بشرط وجود
**حجز في حصة داخل مساحة عمله** أو تسجيل نشط فيها. الكيان الجسر بلا هذا الحارس مكشوف تماماً،
تماماً كما أن `WorkspaceScope` عديم الأثر للزائر.

### بعد التصميم (Phase 1)

| الفحص | النتيجة |
|---|---|
| كيان جديد بلا تصنيف معلن | لا شيء — الجدول أعلاه يغطّي السبعة |
| نموذج مملوك لمساحة عمل بلا `BelongsToWorkspace` | لا شيء |
| `exists:table,id` على جدول تابع لمستأجر | لا شيء — `WorkspaceRules::exists()` في كل `FormRequest` |
| استدعاء مباشر عبر الوحدات | لا شيء — أحداث + عقد `SessionAttendanceDirectory` |
| اسم صلاحية نصّي | لا شيء — ٦ ثوابت |
| `WorkspaceContext::set()` في وظيفة | لا شيء — `forWorkspace()`، ويحرسه اختبار بنفس شكل `TrustScoreJobIsolationTest` |
| تجريد بلا مشكلة قائمة | واحد مبرَّر: واجهة مزوّد البث (R2) — والمشكلة قائمة: NFR-011 تمنع النداء الحقيقي في الاختبار، أي أن المزيّف مكتوب على أي حال |
| تبعية جديدة | صفر |

**لا انتهاكات.** جدول `Complexity Tracking` فارغ عمداً.

---

## Project Structure

### Documentation (this feature)

```text
specs/005-live-sessions-attendance/
├── plan.md              # هذا الملف
├── research.md          # Phase 0 — ١٦ قراراً
├── data-model.md        # Phase 1 — الكيانات والانتقالات والفهارس
├── quickstart.md        # Phase 1 — سيناريوهات التحقّق
├── contracts/
│   ├── api.md                  # مسارات HTTP
│   └── broadcast-provider.md   # عقد المزوّد وقدراته
├── checklists/requirements.md  # ٢٢/٢٢ ✅
└── tasks.md             # Phase 2 — يولّده /speckit-tasks
```

### Source Code

```text
backend/app/Modules/LiveSessions/
├── Actions/
│   ├── GenerateSessionsFromAvailability.php   # من جدول التوفّر القائم
│   ├── ScheduleClassSession.php               # حصة واحدة + منع التداخل
│   ├── CancelClassSession.php
│   ├── BookSeat.php                           # التحديث الشرطي الذرّي (R5)
│   ├── CancelBooking.php                      # قبل/بعد المهلة
│   ├── IssueJoinTicket.php                    # إعادة تقييم الأهلية (FR-046)
│   ├── RecordPresencePing.php                 # مصدر الحضور (R3)
│   ├── CloseClassSession.php                  # الكشف الكامل + SessionDelivered
│   ├── OverrideAttendance.php                 # التحضير اليدوي داخل النافذة
│   ├── SubmitSessionFeedback.php
│   ├── CreateFreezePeriod.php
│   └── PublishSessionRecording.php            # المستمع على MediaAssetReady
├── Contracts/BroadcastProviderInterface.php
├── Data/ (RoomHandle · JoinTicket · RecordingArtifact · BroadcastCapabilities · DTOs)
├── Database/Migrations/                        # M كبيرة — إلزامي
├── Enums/ (ClassSessionStatus · ClassSessionType · AttendanceStatus · AttendanceSource · BookingStatus)
├── Events/ (SessionScheduled · SessionCompleted · SessionDelivered · AttendanceConfirmed)
├── Http/ (Controllers · Requests · Resources)
├── Jobs/ (FreezeBillableSeatsJob · MarkAbsenteesJob · CloseClassSessionJob
│          · SendSessionReportsJob · IngestSessionRecordingJob · SyncTeacherCountersJob)
├── Listeners/ (NotifySessionCancelled · PublishRecordingAsLesson · UpdateTeacherCounters)
├── Models/ (ClassSession · SessionBooking · Attendance · ClassSessionFeedback · FreezePeriod)
├── Policies/ · Providers/NullBroadcastProvider.php · Support/EloquentSessionAttendanceDirectory.php
├── routes/api.php
└── LiveSessionsServiceProvider.php

backend/app/Shared/Contracts/SessionAttendanceDirectory.php   # تستهلكه Media (R8)

frontend/src/
├── app/(app)/(shell)/schedule/page.tsx              # جدول الطالب + الحصة القادمة
├── app/(app)/(shell)/manage/sessions/page.tsx       # جدول المدرّس والتوليد
├── app/(app)/(shell)/manage/sessions/[uuid]/page.tsx # الحصة: المقاعد والكشف والتقييم
├── app/(app)/(shell)/sessions/[uuid]/room/page.tsx  # الغرفة + حلقة النبض
├── app/(app)/(shell)/manage/freeze/page.tsx         # فترات التجميد
├── components/sessions/ (BroadcastStage · PresenceLoop · AttendanceSheet
│                         · NextSessionCountdown · SeatBadge · SessionCard)
└── lib/sessions.ts
```

**Structure Decision**: وحدة خلفية واحدة جديدة تُكتشَف تلقائياً بـ`ModulesServiceProvider`
(**يُمنع** تسجيلها في `bootstrap/providers.php`). الواجهة تتبع التقسيم القائم: ما يخصّ الطالب
تحت الجذر، وما يخصّ المدرّس تحت `manage/`. **كل شاشة تُربط من الشريط الجانبي أو ممّا يسبقها
في الرحلة، ويثبت ذلك اختبار e2e يدخل من التنقّل** — الطريق الذي لا يمشيه اختبار هو الطريق
الذي لا يوجد.

---

## Deferred Verification *(أثر تأجيل مزوّد البث — مُعلَن لا مُخفى)*

| المتطلب | يُثبَت اليوم | ينتظر المزوّد |
|---|---|---|
| FR-013 غرفة بصوت وصورة | دورة حياة الغرفة والتذاكر ونافذة الدخول | البثّ نفسه |
| FR-016 أدوات المضيف | القرار على الخادم ومن يملكه | الكتم والإخراج عند المزوّد |
| FR-028 رفع التسجيل | السلسلة كاملة بمزيّف يعلن `recording: true` | ملف حقيقي |
| SC-007 نسبة الرفع | المنطق وحدّ المحاولات والإبلاغ | القياس الفعلي |

الجدولة · الحجز · التزامن · السُّلَّم الزمني · العتبة · التجميع · الكشف · التجميد · العدّادات
· التقارير: **تُثبَت اليوم بالكامل**، لأن أياً منها لا يمرّ بالمزوّد.

---

## Complexity Tracking

> يُملأ فقط عند وجود انتهاك للدستور يحتاج تبريراً.

لا انتهاكات. التجريد الوحيد (واجهة مزوّد البث) يحلّ مشكلة قائمة الآن لا متوقّعة: NFR-011
تمنع النداء الشبكي الحقيقي في الاختبارات، فالمزيّف مكتوب على أي حال — والواجهة هي ما يجعله
مزيّفاً لعقد بدل أن يكون فرعاً في الكود.
