# خطّة التنفيذ: سبّورة المدرّس

**الفرع**: `039-teacher-whiteboard` | **التاريخ**: 2026-10-01 | **المواصفة**: [spec.md](spec.md)

**المُدخَل**: مواصفة الميزة في `specs/039-teacher-whiteboard/spec.md` (توضيحات جلسة 2026-10-01 كلها، بما فيها قرارا المالك D1 وD2، ومعها مرجع «سبّورة الصباح»)

## Summary — الخلاصة

سبّورة يرسم عليها المدرّس وحده، ويراها الطلاب عبر مشاركة الشاشة الموجودة أصلاً في الجلسة المباشرة.

**المنهج:**
- اللوحة هي Excalidraw 0.18.1 (نسخة مثبَّتة)، ونوسّعها عبر واجهتها العامّة فقط.
- صفحة 16:9 هي عنصر `frame` داخل مشهد خاصّ بها.
- النصّ العربي يمرّ عبر `@font-face` نسجّله من CSS الخاصّ بنا، بخطّ Cairo (يُختبَر في المرحلة 0).
- ملفات السبّورة هي `MediaAsset` يملكها `Board` على القرص المحلي القائم، خلف أبواب مخصّصة (على سابقة المتجر). وPDF المُصدَّر إلى درس يُرفَع من باب مرفقات الدرس القائم في Media، والسبّورة تسجّل الصلة فقط.
- الحفظ محلّي (IndexedDB خلال ثانيتين من انتهاء الضربة) وعلى الخادم (بعد 1.5 ثانية من توقّف الرسم، مع `version` تفاؤلي لكل صفحة).
- محرّر واحد في كل لحظة، عبر قفل ذرّي بجمل `UPDATE` مشروطة مع نبضة دورية (R-09).
- تُحوَّل المستندات على الخادم: Gotenberg لملفات Office، ثم poppler للصور. ملف واحد في كل مرّة على مشرف Horizon مخصّص.
- الكائنات الغنية (جدول، معادلة، نصّ قرآني) هي عنصر صورة مع `customData` وملف PNG.
- أدوات التدريس والمؤثّرات طبقة عرض خارج المشهد، فلا يُحفَظ منها شيء.

**التسليم:** بالترتيب الذي تحدّده المواصفة (spec، ترتيب التسليم):
- **المرحلة 0** تجربة استكشافية وسجلّ قرار معماري (ADR)، ثم توقّف لتقديم التقرير.
- **المرحلة 1** هي الأساس (القصص 1–8).
- ثم أدوات التدريس، والمؤثّرات، والمحتوى والأنشطة.

## Technical Context — السياق التقني

**Language/Version** (اللغة والإصدار): PHP 8.5 (Laravel 13) · TypeScript strict (Next.js 15.5, React 19)

**Primary Dependencies** (الاعتمادات الأساسية):
- **قائمة:** وحدة Media، وHorizon، وSanctum، وspatie/permission.
- **جديدة، بعد الموافقة** (research.md R-17): `@excalidraw/excalidraw@0.18.1`، `pdf-lib`، `mathlive`، `mathjax@4`، `three`، واختيارياً `canvas-confetti`.
- **على الخادم:** `gotenberg/gotenberg:8` و`poppler-utils`.

**Storage** (التخزين):
- MySQL 8 في الإنتاج وSQLite محلياً: 4 جداول جديدة (data-model.md).
- الملفات على قرص الوسائط القائم (`MEDIA_DISK=local`)، لا Spaces ولا R2.
- IndexedDB في المتصفّح للمسودّة.

**Testing** (الاختبار):
- اختبارات Pest للميزات: العزل، والسياسة، والقفل، والإصدارات، والاستيراد مع خطوة تحويل وهمية.
- vitest: الدوال الخالصة (customData، والجداول وTSV، ومقارنة المسودّة، وملاءمة الصفحة).
- Playwright: `e2e/whiteboard.spec.ts`.

**Target Platform** (المنصّة المستهدفة):
- المدرّس: متصفّح على جهاز مكتبي، Chrome/Edge أولاً. Firefox وSafari يرسمان، لكن صوت التبويب وخيارات المشاركة التجريبية متاحة في Chromium وحده.
- iOS وAndroid لا يستطيعان مشاركة الشاشة من المتصفّح (MDN، R-15).

**Project Type** (نوع المشروع): تطبيق ويب (`backend/` + `frontend/`)

**Performance Goals** (أهداف الأداء):
- الانتقال بين الصفحات في أقلّ من ثانية (SC-004).
- استيراد PDF من 50 صفحة في أقلّ من دقيقتين (SC-003).
- لا يضيع أكثر من 5 ثوانٍ من العمل (SC-005).
- لا تأخّر في الإدخال على مدى 30 دقيقة (SC-006).

**Constraints** (القيود):
- الوضوح على هاتف Android متواضع (SC-001).
- لا `dataURL` في مشهد مخزَّن.
- لا ملف SVG يُقدَّم من نطاقنا.
- لا تعديل على Excalidraw (patch) دون موافقة.
- لا مساس بكود الجلسة المباشرة دون موافقة (FR-024).

**Scale/Scope** (الحجم والنطاق):
- حتى 100 صفحة في الاستيراد الواحد (Q4) و300 في السبّورة.
- مشهد حتى 2 MB، ومجموع مشاهد السبّورة حتى 50 MB (`whiteboard.max_board_bytes`).
- التحويل ملف واحد في كل مرّة على مستوى المنصّة كلّها (Q1).

## Constitution Check — فحص الدستور

*بوّابة: يجب أن تمرّ قبل بحث المرحلة 0. أُعيد الفحص بعد تصميم المرحلة 1: النتيجة نفسها.*

| المبدأ | النتيجة | الكيفية |
|---|---|---|
| I. عزل المستأجرين | ✅ | 4 جداول مملوكة لمساحة العمل مع `BelongsToWorkspace` (data-model.md). التحقّق عبر `WorkspaceRules::exists`. سبّورة من مساحة عمل أخرى تُجيب 404. يُضاف كل جدول إلى `WorkspaceIsolationTest`. لا تُقرأ أيّ بيانات طلاب |
| II. المنطق في الـ Actions | ✅ | `CreateBoard`، `UpdateBoard`، `SaveBoardScene`، `AcquireBoardLock`، `TakeBoardLock`، `ReleaseBoardLock`، `AddBoardPage`، `DeleteBoardPage`، `ReorderBoardPages`، `RequestBoardFile`، `CompleteBoardFile`، `RequestBoardImport`، `CompleteBoardImport`، `ConvertBoardImport` (تستدعيها المهمّة)، `RecordBoardExport`، `DuplicateBoard` و`DeleteBoard` (كلتاهما مهمّة في الطابور). ويستدعي كل Action يمسّ المحتوى صنف الدعم `BoardLock`. الحدود (الصفحات، الحجم، الإصدار، القفل) تُفرَض داخل الـ Action |
| III. استقلال الوحدات | ⚠️ مبرَّر | وحدة Whiteboard **لا تُنشئ** ملفات يملكها الدرس. تستدعي `Media\Actions\CompleteMediaUpload` لملفاتها هي (سابقة `Store`/`CompleteStoreFile`)، و`DeleteMediaAsset` للمرفق القديم بعد أن يفوّضه **سياسة Media نفسها** (`Gate::authorize('delete', $old)` عبر `MediaAssetPolicy`)، وتسأل `AssistantScopeDirectory` (عقد مشترك). Media بنية تحتية مشتركة. مسجَّل في Complexity Tracking |
| IV. البوّابات | ✅ | pint، وphpstan المستوى 8 (الوحدة مضافة إلى `phpstan.neon`)، وtsc، وpest على GitHub |
| V. السياسات والثوابت | ✅ | `BoardPolicy` (contracts/api.md). يعيد استخدام `Permissions::LESSONS_MANAGE` و`Roles::TENANT_OWNER`، بلا نصّ صلاحية جديد |
| VI. العقود العامّة | ✅ | `uuid` فقط، والـ Resources فقط، و`declare(strict_types=1)`، وDTOs |
| قيود البيئة | ✅ | لا إطار عمل جديد: Excalidraw مكتبة داخل React. التكافؤ بين MySQL وSQLite: `longText` و`unsignedSmallInteger` و`timestamp(3)` مفحوصة مقابل الوضع الصارم، وركن المواضع لا يمرّ بالسالب |
| سير العمل | ✅ | لا توضيح مفتوح. أُعيد التحقّق من قائمة الفحص بعد إضافة القصص 9–12 (وهي الآن 16/16 من جديد). يُحدَّث `docs/README.md` و`docs/erd.md` مع الكود |

## Project Structure — بنية المشروع

### التوثيق (هذه الميزة)

```text
specs/039-teacher-whiteboard/
├── spec.md · plan.md · research.md · data-model.md · quickstart.md
├── contracts/api.md
├── checklists/requirements.md
└── tasks.md            # /speckit-tasks (after the per-dimension agent review)
docs/whiteboard/ADR-001.md   # Phase 0 output (requirement c)
```

### الكود المصدري

```text
backend/app/Modules/Whiteboard/
├── WhiteboardServiceProvider.php
├── Models/            Board · BoardPage · BoardImport · BoardLessonExport
├── Actions/           (listed in the Constitution Check, principle II)
├── Jobs/              ConvertBoardImportJob · DuplicateBoardJob · DeleteBoardJob
├── Support/           BoardLock · DocumentConverter (Gotenberg + poppler, one interface, a fake in tests)
├── Compliance/        WhiteboardPersonalData (export · erase · expire walks)
├── Policies/          BoardPolicy
├── Http/Controllers/  BoardController · BoardPageController · BoardLockController
│                      BoardFileController · BoardImportController · BoardLessonExportController
├── Http/Requests/ · Http/Resources/ · Data/
├── Database/Migrations/   # + backfill of data_categories (precedent: Store 2026_08_29_001300)
└── routes/api.php
backend/config/whiteboard.php            # platform_settings defaults + Gotenberg URL + poppler path
backend/config/media.php                 # + Board::class in full_allowance_owners
backend/config/horizon.php               # + supervisor-whiteboard on redis-long (defaults + environments + waits)
backend/app/Providers/AppServiceProvider.php   # + named limiters whiteboard-import · whiteboard-files · whiteboard-autosave
backend/tests/Feature/Whiteboard/        # isolation · nested ids · policy · lock (race in the gap) · scene · import · export
backend/tests/Unit/QueueTimeoutInvariantTest.php   # covers supervisor-whiteboard

frontend/src/app/(app)/(shell)/manage/boards/page.tsx          # the boards list (+ an entry in lib/panel-nav.tsx)
frontend/src/app/(app)/whiteboard/layout.tsx                   # sign-in guard via an extracted useRequireSignIn()
frontend/src/app/(app)/whiteboard/[board]/page.tsx             # full screen, outside the shell (a 16:9 tab)
frontend/src/components/whiteboard/
├── BoardCanvasClient.tsx      # 'use client' + dynamic(ssr:false)
├── BoardCanvas.tsx            # 'use client', imports Excalidraw + index.css
├── PagesSidebar.tsx · SaveIndicator.tsx · LockBanner.tsx
├── overlays/                  # ruler/protractor/compass, spotlight, timer, magnifier, curtain, wheel, effects
├── rich/                      # TableEditor · MathEditor (lazy) · QuranPicker
├── activities/                # games and 3D scenes (lazy; three/MathJax/MathLive only inside next/dynamic)
└── LessonBoardsSection.tsx    # its own component (LessonEditor is already 548 lines), reads GET /boards?lesson=
frontend/src/lib/whiteboard/
├── excalidraw-api.ts   # the only door to Excalidraw (R-02)
├── strings.ts          # every Arabic string (R-16)
├── custom-data.ts      # types + migrateCustomData
├── draft-store.ts      # plain IndexedDB
├── autosave.ts · page-model.ts · table-paste.ts · pdf-export.ts · arabic-font.ts
frontend/src/lib/permissions.ts         # + lessonsManage
frontend/src/components/ui/FloatingActions.tsx   # + "/whiteboard" in NO_FLOATING_CHROME_PREFIXES
frontend/scripts/copy-excalidraw-fonts.mjs       # cross-platform node script, run by predev + prebuild
frontend/public/excalidraw/fonts/       # copied by predev/prebuild, ignored by git
frontend/public/whiteboard/fonts/cairo-arabic.woff2   # self-hosted copy (next/font hashes its names)
frontend/e2e/whiteboard.spec.ts
docker/docker-compose.yml · docker-compose.prod.yml   # + gotenberg on its own internal:true network shared only with horizon; memory limits on both
docker/backend.Dockerfile                              # + apk add poppler-utils
```

**قرار البنية:**
- وحدة `Whiteboard` جديدة في الخلفية، تُكتشَف بالطريقة نفسها التي تُكتشَف بها الوحدات اليوم.
- السبّورة نفسها تحت `(app)` لكن **خارج** `(shell)`: هي تبويب كامل بلا شريط جانبي، لأنها الشيء الذي يُشارَك. ولهذا تحتاج (مراجعة: ق-١٠):
  - `whiteboard/layout.tsx` يحرس تسجيل الدخول عبر `useRequireSignIn()` المستخرَج من تحويل `(shell)/layout.tsx`؛
  - `"/whiteboard"` في `NO_FLOATING_CHROME_PREFIXES`، فلا يظهر زرّ واتساب في التبويب المشارَك.
- قائمتها تحت `manage/boards`، ولها عنصر في `lib/panel-nav.tsx` (`panel-nav.test` يفشل على صفحة بلا عنوان)، وتُقرأ بـ `lessonsManage` من `lib/permissions.ts`.
- الروابط الواصلة إليها (الذاكرة: «كل سطح جديد يحتاج رابطاً يصل إليه»):
  - عنصر في قائمة الإدارة؛
  - قسم «السبّورات» في محرّر الدرس (`GET /boards?lesson=`)؛
  - «افتح السبّورة» في صفحة الغرفة **يحتاج موافقة المالك على لمس كود الحصة المباشرة** (FR-022). حتى ذلك الحين لا مستدعي لمرشِّح `session=`.

## المرحلة 0 (ضمن هذه الخطّة): التجربة الاستكشافية ثم التوقّف (المتطلّبات a وb وc)

1. **تقرير الجلسة المباشرة (a):** أُنجز في research.md R-15، وينتقل إلى الـ ADR.
2. **مسار `/lab/whiteboard` (b)** على **فرع تجربة لا يُدمَج** (لا يُشحَن): Excalidraw مع `ar-SA` وقاعدتي `@font-face` تحت `Segoe UI Emoji` (R-04)، وتصدير PNG/SVG مع حقن الخطّ داخل SVG، وإطار 16:9 مع الملاءمة المحسوبة، وثلاث خلفيات، وإعدادات أقلام جاهزة. الدليل: vitest لـ `page-model` و`arabic-font`، ولقطات شاشة، وقائمة الفحص اليدوي في quickstart.md.
3. **القياسات:** ذاكرة Gotenberg على ملف PPTX من 50 صفحة؛ ووضوح العرض لدى المشاهد على الهاتف؛ و30 دقيقة من الرسم المتواصل.
4. **`docs/whiteboard/ADR-001.md` (c):** نموذج الصفحة، والتخزين والحفظ، ومسار الملفات، ومخطّط customData، والتكامل مع الغرفة، ومسار Yjs (أدناه).
5. **التوقّف** مع تقرير من سبعة أجزاء (القيد 9).

## مسار التعاون المستقبلي (Yjs): مصمَّم الآن ولا يُبنى

- **`Y.Doc` واحد لكل صفحة**، لا لكل سبّورة أبداً. وهذا يطابق `board_pages.scene` (مستند واحد لكل صفحة).
- تصبح `elements` من النوع `Y.Map<id, Y.Map>` بمفتاح `id` الثابت للعنصر. و`version`/`versionNonce` في Excalidraw يحسمان التعارض على العنصر نفسه.
- يُشار إلى الملفات بـ `fileId` فقط، فلا يحمل المستند المشترك أيّ بايتات؛ وأيّ مشارك يقرأ الملف من `GET /boards/{b}/files/{f}`.
- الحقل `v` في `customData` مع القاعدة «النوع المجهول يُحفَظ ولا يُحذَف أبداً» يحميان خليطاً من الإصدارات في جلسة واحدة.
- يبقى `SaveBoardScene` نقطة الحفظ الدائم: خادم Hocuspocus (لاحقاً) يكتب اللقطة عبر الـ Action نفسها، ويصير قفل المحرّر الواحد قفل «مقدِّم».
- يبقى عمود `version` لعمليات الحفظ عبر REST من مستخدم واحد.

## Complexity Tracking — تتبّع التعقيد

| الاستثناء | لماذا نحتاجه | لماذا رُفض البديل الأبسط |
|---|---|---|
| حاوية Gotenberg جديدة (~700 MB) | تحويل PowerPoint وWord (Q1: اختار المالك A) | مطالبة المدرّس بحفظ PDF كانت الخيار B، ورفضه المالك. وLibreOffice داخل صورة الخلفية سيضخّم صورة PHP ويعرّض PHP لانهيارات LibreOffice |
| Whiteboard تستدعي Actions وحدة Media مباشرةً | `CompleteMediaUpload` لملفات السبّورة وحدها (سابقة `Store`)، و`DeleteMediaAsset` للمرفق القديم بعد تفويض **سياسة Media نفسها**. ملف الدرس يُرفَع من باب Media القائم، فلا تُنشئ Whiteboard ملفاً يملكه الدرس | نسخها يعني مسارين لفحص البايتات السحرية. والحدث لا يستطيع إعادة تذكرة رفع |
| جدول `board_lesson_exports` | الاستبدال في المرفق (Q2) يحتاج معرفة أيّ مرفق جاء من أيّ سبّورة | المطابقة باسم الملف هشّة، وقد يعيد المدرّس تسميته |
| مشرف Horizon مخصّص بعملية واحدة | «ملف واحد في كل مرّة» (Q1) | وسيط منع التداخل في المهمّة يُسقطها بدل أن يؤجّلها (`dontRelease`) |
| قفل التحرير مع التسليم | Q3: يأخذ صاحب السبّورة (D1) التحرير بعد حفظ عمل المحرّر الحالي | قفل بسيط بلا تسليم سيُضيع الثواني الأخيرة للمحرّر أو يُقصي المالك |
| لا حذف ناعم للسبّورات | — (تبسيط) | `ponytail:` يُضاف إن أُبلغ عن حذف بالخطأ |
| `lib/whiteboard/strings.ts` بدلاً من مكتبة i18n | القيد 8 يطلب ألّا تتناثر النصوص | next-intl اعتماد جديد وتغيير في التطبيق كلّه من أجل ميزة واحدة |

## المخاطر والأسئلة المفتوحة (لتقرير المرحلة 0)

1. **العربية عبر `@font-face`** لم تُثبَت بعد. إن فشلت، فالتعديل الأدنى لإضافة عائلة خطّ يحتاج موافقة.
2. **أقلام الطباشير والرشّ** لا يمكن تنفيذها بـ freedraw عبر الواجهة العامّة. المقترح: إعدادات جاهزة (قلم تحديد، فرشاة) الآن، والملمس لاحقاً كتعديل موافَق عليه أو كضربة محوَّلة إلى صورة. المواصفة تعلّم FR-031 «قيد التقييم في المرحلة ٠».
3. **pdf-lib غير مصانة منذ 2021.** تُقارَن بـ jsPDF في المرحلة 0.
4. **هل mhchem موجود في الحزمة المجمَّعة لـ MathJax v4؟** هذا يحدّد طريقة استضافة الملفات ذاتياً.
5. **التسجيل بدقّة 720p** يُغبّش الكتابة الدقيقة في التسجيل. رفعها قرار منفصل.
5أ. **binlog:** كل حفظ يعيد كتابة المشهد كاملاً؛ يُقاس binlog على الإنتاج (`SHOW VARIABLES … log_bin, binlog_row_image, binlog_expire_logs_seconds`) قبل الإطلاق، وتخطّي الحفظ إن لم يتغيّر المحتوى.
5ب. **الخطّ العربي في SVG:** إصلاح `text-anchor` للنصّ RTL بعد التصدير، وأسماء أعلام تعطيل Chromium في Gotenberg، كلاهما يُتحقَّق منه في المرحلة 0.
6. **القرص C: ممتلئ** على جهاز التطوير. يجب تحريره قبل تثبيت أيّ شيء.
7. ~~تحسين Q3~~: مهلة ١٠ ثوانٍ عند أخذ القفل، وافق عليها المالك في 2026-10-01.
