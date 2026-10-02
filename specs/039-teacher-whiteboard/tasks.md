# المهام: ٠٣٩ سبّورة المدرّس

**المُدخَل**: `specs/039-teacher-whiteboard/` بملفاته:
- spec.md: ١٢ قصة، والتوضيحات، وقرارا المالك D1 وD2؛
- plan.md؛
- research.md (R-01…R-17)؛
- data-model.md؛
- contracts/api.md؛
- quickstart.md؛
- review-findings.md (ق-١…ق-١١، وبنود القسم ب بلا أرقام).

**الاختبارات مطلوبة صراحةً** في طلب المالك ومعايير النجاح:
- Pest، ومنها محاولات الوصول عبر مساحات العمل؛
- Playwright للحفظ والاستعادة، والاستيراد، والتصدير، والنصّ العربي (SC-008).

قاعدة لكل اختبار: **«هل يمكن أن ينجح على نسخة لم تُكتَب فيها الميزة؟»** فإن أمكن فليس اختباراً. وأيّ اختبار لادّعاء ذرّي يكتب الكتابة المنافِسة **داخل الفجوة** (`testing.md`)، لا بعدها، بأداة واحدة: `DB::beforeExecuting` بجزء من نصّ الجملة، ومعه حارس مرّة واحدة، ثم تأكيد أن الكتابة المنافسة جرت فعلاً (سابقة `backend/tests/Feature/Assessments/AdaptiveClaimTest.php:24-37`). **لا** خطّاف `retrieved` على النموذج: لا يُطلَق على `DB::table`.

**الفحص على GitHub يعمل على SQLite وحدها** (لا خدمة MySQL في `.github/workflows/ci.yml`). فلا اختبار يدّعي سلوك MySQL؛ ما يخصّ MySQL يُحرَس بفحص بنيوي (دقّة العمود في الهجرة، والقيم المربوطة) أو بفحص المصدر في `SqliteGreenFormsTest`.

**التنظيم**:
- **المرحلة ٠** تجربة استكشافية على فرع لا يُدمج، تنتهي بـ**توقّف وتقرير** (القيد ٩).
- بعدها الإعداد، ثم الأساس.
- ثم القصص بترتيب التسليم في المواصفة:
  1. الأساس: القصص ١–٨؛
  2. أدوات التدريس: ٩؛
  3. المؤثرات: ١٠ و١٢؛
  4. المحتوى والأنشطة: ١١.

**صيغة المهمة**: `- [ ] Txxx [P]? [USn]? الوصف مع المسار`.
- `[P]` تعني ملفاً مختلفاً بلا اعتماد على مهمة لم تكتمل.

**تصحيحان على الخطة اكتُشفا أثناء كتابة المهام** (الكود هو المرجع):
- `WhiteboardPersonalData` مكانه `Support/` لا `Compliance/`، على سابقة `app/Modules/Store/Support/StorePersonalData.php`، ويُوسَم `compliance.personal_data` في مزوّد الوحدة (`StoreServiceProvider.php:70`).
- `QueueTimeoutInvariantTest` موجود في `backend/tests/Unit/` (موجود فعلاً).

### ما أسقطته مراجعة الوكلاء على المهام (2026-10-02)

**قرار المالك (2026-10-02): لا مفتاح تشغيل — تظهر السبّورة للكل أول بأول.** فلا مهمة لمفتاح ميزة. لكن بنية الاستيراد (Gotenberg وpoppler والمشرف ومفاتيحه) تُشحن مع القصة ٤ لا في الإعداد، حتى لا تنشر أوّل طلبات الدمج حاوية خاملة بحجم 1.5 GB.

| الخلل | البُعد | أين ذهب |
|---|---|---|
| ساعة قاعدة البيانات و`INTERVAL` لا تعمل على SQLite، وربط كائن تاريخ يُسقط الملّي ثانية، والاسم المكرّر يرفضه pdo_mysql (HY093)، والتجديد يعيد صفراً على MySQL | قاعدة البيانات، التزامن | T028، T034، T022، T025، research R-09 |
| عدّ الصفوف في إعادة الترتيب (فرق صفر) يفشل على MySQL؛ `<=>` لا تفهمه SQLite؛ ولا حارس مصدر لصيغ SQL | قاعدة البيانات | T029، T032، T093، T067 |
| اختبارات السباق كانت بخطّاف `retrieved` أو مدّعاة على MySQL، و«إعادة ترتيب متزامنة» غير قابلة للكتابة | الاختبارات | T034، T054، T068، T086، T094، T067 |
| حدّ «استيراد واحد لكل مستخدم» بإدراج شرطي لا يحمي، والحجم المُعلَن لا يُعاد فحصه | التزامن، الأمان | T081، T086 |
| المهامّ تُرسَل قبل الالتزام، وإعادة المحاولة بـ `release`، ومهامّ النسخ والحذف بلا مشرف، والمكنسة بلا `RunsAlone` ولا سقف | الطوابير والتشغيل | T063، T065، T066، T077، T083، T084 |
| `client_rev` وحده ليس مفتاح أثر واحد بين تبويبين | التزامن | T022، T052، T054 |
| المزوّد لا يعرف نسخ ملف، فنسخ السبّورة بلا طريق | المعمار | T064، T065 |
| نقل السبّورة إلى مقرّر آخر، والمرفق المُصدَّر، ورفض المتعلّم مكتوباً بالغياب، و`embeddable`، وروابط ملتوية، و2FA، وإعادة فحص `update` | الأمان | T027، T040، T042، T052، T054، T093، T094، T033، T062 |
| لا مصفوفة عزل واحدة تغطّي كل باب | الأمان | T043، وسطر في كل مهمة طرق بعدها |
| الصفحة تجلب على الخادم بلا توكن، والمكوّنات بلا مكان على الشاشة، ولا إدراج صور عادي، ولا واجهة للسبّورة نفسها، ودالّة رفع بتوقيع خاطئ | الواجهة | T044، T014، T037، T039، T036، T048، T074، T073، وكل مهمة «تركيب»، T095 |
| حالات الحفظ التلقائي غير مسمّاة، واختباراتها لا تمسك الخطأ | الواجهة، التزامن | T055، T056، T057، T058 |
| بنية الاستيراد كانت تُنشر في أوّل طلب دمج | النشر | T077، T078، T079، T080 |
| اختبارات Playwright تعمل على ستّة مشاريع، وتتخطّى بصمت، وتؤكّد ما لا يُثبت | الاختبارات | T051، T060، T076، T091، T099، T112 |
| فحص الحزمة يدوي، واختبارات jsdom تدّعي ما لا تستطيع رؤيته | الأداء | T105، T115، T121، T127، T134 |
| فئات البيانات الشخصية، وقرار المحو، و`$fillable`، وترتيب الطابور، وإحالات المراجعة الخاطئة | قاعدة البيانات، الامتثال | T030، T024، T085، الاعتمادات أدناه |
| سيناريوهات قبول بلا اختبار | تغطية المواصفة | T046، T059، T071، T068، T069، T087، T089، T098، T104، T110، T116، T122، T128، T135، T035، T019 |

---

## المرحلة ٠: التجربة الاستكشافية وسجلّ القرار، ثم التوقّف ⛔

**الهدف:** إثبات أو نفي الافتراضات الخطرة قبل أي قاعدة بيانات أو Laravel.

**الشروط:**
- على فرع `039-spike` من `039-teacher-whiteboard`، ولا يُدمج.
- **لا تبدأ قبل:**
  - موافقة المالك على تثبيت `@excalidraw/excalidraw@0.18.1` (القيد ٦)؛
  - تحرير مساحة على القرص C: (مساحته اليوم 0 بايت).

**مخرج المرحلة:** `docs/whiteboard/ADR-001.md` + تقرير من سبعة أقسام + قائمة الفحص اليدوي في quickstart.md وقد ملأها المالك.

- [ ] T001 اطلب من المالك موافقة مكتوبة على `@excalidraw/excalidraw@0.18.1` (MIT، حوالي 47 MB على npm، والمحمَّل فعلاً حوالي 400 KB مضغوطاً)، وتأكّد من وجود مساحة حرّة على C:. بعدها `git switch -c 039-spike` من `039-teacher-whiteboard`
- [ ] T002 ثبّت النسخة بالضبط بـ `npm install --save-exact @excalidraw/excalidraw@0.18.1` في `frontend/package.json`، وتأكّد أن `package-lock.json` لم يرفع أي اعتماد آخر لـ React
- [ ] T003 [P] اكتب `frontend/scripts/copy-excalidraw-fonts.mjs`:
  - سكربت node يعمل على كل الأنظمة، ينسخ `node_modules/@excalidraw/excalidraw/dist/prod/fonts/` **مع مجلّد `fonts/` نفسه** إلى `frontend/public/excalidraw/fonts/`؛
  - يُستدعى من `predev` و`prebuild` في `frontend/package.json`، **لا** من `postinstall` (مرفوض)، لأن `docker/frontend.Dockerfile` يشغّل `npm ci` قبل `COPY . .`؛
  - وأضف `frontend/public/excalidraw/` إلى `.gitignore`.
- [ ] T004 [P] نزّل نسخة woff2 من Cairo (رخصة OFL) فيها النطاق العربي إلى `frontend/public/whiteboard/fonts/cairo-arabic.woff2`، واكتب مصدرها ورخصتها في `frontend/public/whiteboard/fonts/LICENSE.txt`. نسخة `next/font` لا تصلح لأن أسماء ملفاتها مُجزّأة (مراجعة الخطة: ب، الواجهة)
- [ ] T005 اكتب `frontend/src/lib/whiteboard/arabic-font.ts`. حيلة الخطّ من R-04 بعد تعديل المراجعة:
  1. قاعدتا `@font-face` باسم `"Segoe UI Emoji"`:
     - الأولى بخطّ Cairo مع `unicode-range` العربي (`U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF`) ومعها `ascent-override` و`descent-override` و`line-gap-override`؛
     - الثانية `src: local("Segoe UI Emoji"), local("SegoeUIEmoji")` بلا نطاق، حتى لا يختفي الإيموجي على ويندوز.
  2. دالة `ensureArabicFont()` تنتظر `document.fonts.load('16px "Segoe UI Emoji"', 'ب')` **قبل** تركيب Excalidraw.
  3. دالة `injectArabicFontIntoSvg(svg)` تضيف الخطّ base64 داخل `<defs><style>` وتصحّح `text-anchor` للنصّ RTL.
- [ ] T006 [P] اكتب `frontend/src/lib/whiteboard/page-model.ts`، دوالّاً خالصة:
  - `pageFrame(pageUuid)` يعطي إطاراً 1920×1080 معرّفه `frame:{pageUuid}`؛
  - `fitZoom(viewportW, viewportH)` = `min(w/1920, h/1080)` ومعه `scrollX` و`scrollY` للتوسيط. لا نستخدم `scrollToContent` لأنه يقرّب الزوم لأسفل إلى 0.1 وسقفه 1 (مراجعة الخطة: ب، الواجهة)؛
  - `STREAM_DEFAULTS`: الحدّ الأدنى للسُمك والحجم، واللوحة عالية التباين، والخلفيات الثلاث بلون قلم افتراضي لكل منها، والأرقام مؤقتة حتى القياس.
- [ ] T007 [P] اكتب `frontend/src/lib/whiteboard/page-model.test.ts` (vitest، `$env:TZ='UTC'`). يغطّي:
  - `fitZoom` على 1366×600 و1920×1080 و1280×1024؛
  - معرّف الإطار مختلف لكل صفحة؛
  - لكل خلفية لون قلم نسبة تباينه مع الخلفية ≥ 7:1.
- [ ] T008 ابنِ صفحة التجربة `frontend/src/app/(app)/lab/whiteboard/page.tsx` (فيها `notFound()` إن كان `NODE_ENV==='production'`) بثلاثة ملفات حسب R-02:
  - `components/whiteboard/BoardCanvasClient.tsx`:
    - `'use client'` و`dynamic(..., {ssr:false})`؛
    - يضبط `window.EXCALIDRAW_ASSET_PATH="/excalidraw/"` **داخل** دالة التحميل قبل `import()`؛
    - يلتقط `<html lang/dir>` في مُهيِّئ `useRef` ويعيدهما عند فكّ التركيب.
  - `components/whiteboard/BoardCanvas.tsx`:
    - يستورد Excalidraw و`index.css`؛
    - `langCode="ar-SA"`، و`currentItemTextAlign:"right"`، و`frameRendering={enabled:true,name:false,outline:false,clip:true}`؛
    - قناع فوق اللوحة يغطّي ما خارج الإطار؛
    - إعدادات الأقلام الجاهزة، وتنقّل بين 10 صفحات مع `api.history.clear()` عند كل انتقال.
- [ ] T009 [P] أضف إلى صفحة التجربة:
  - تصدير PNG عبر `exportToBlob({exportingFrame, getDimensions})` بمقياس 1.5؛
  - تصدير SVG عبر `exportToSvg({exportingFrame, skipInliningFonts:true})` ثم `injectArabicFontIntoSvg`؛
  - تصدير PDF بـ pdf-lib وآخر بـ jsPDF **لا يُثبَّتان إلا بموافقة**؛ وإن لم تأتِ الموافقة سجّل المقارنة نظرياً في الـ ADR.
- [ ] T010 [P] أضف إلى صفحة التجربة مستمع `paste` في مرحلة الالتقاط:
  - يسجّل في الـ console أنواع الحافظة عند اللصق من Excel على ويندوز ومن Google Sheets؛
  - الغرض: إثبات أو نفي أن Excel يضع PNG يلتقطه Excalidraw قبل `onPaste` (مراجعة الخطة: ب، الواجهة).
- [ ] T011 شغّل القياسات وسجّلها:
  - ذاكرة Gotenberg على PPTX من 50 صفحة: `docker run` مؤقت على الجهاز، لا على الإنتاج؛
  - أسماء أعلام تعطيل Chromium في Gotenberg 8، و`pageRanges` في LibreOffice؛
  - هل mhchem داخل الحزمة الموحّدة لـ MathJax 4؛
  - `text-anchor` في SVG مع RTL: القيمة الصحيحة بعد التصحيح، يقرؤها اختبار T051؛
  - العربية في Chrome وEdge وFirefox؛
  - الذاكرة بعد زيارة 40 صفحة؛
  - **ثم سلّم المالك** قائمة quickstart.md (١–٨: القلم، والهاتف الضعيف، وجلسة الثلاثين دقيقة).
- [ ] T012 اكتب `docs/whiteboard/ADR-001.md`، يغطّي:
  - تقرير البثّ (R-15)؛
  - نموذج الصفحة (R-07 بعد المراجعة)؛
  - التخزين والحفظ (R-08)؛
  - مسار الملفات (R-01، R-10، R-12)؛
  - مخطّط customData؛
  - التكامل مع الغرفة؛
  - طريق Yjs (plan.md)؛
  - نتائج T011؛
  - قرارات معلّقة: الطباشير والبخّاخ، وpdf-lib مقابل jsPDF، وخيارات مشاركة الشاشة لـ FR-024.
- [ ] T013 **توقّف.** أرسل التقرير بأقسامه السبعة:
  1. الملخّص؛
  2. الملفات المتغيّرة؛
  3. الواجهات المُتحقَّق منها مع روابطها؛
  4. الانحرافات وأسبابها؛
  5. المخاطر والأسئلة؛
  6. التشغيل على Windows؛
  7. الخطوة التالية.

  **لا مهمة بعد هذا السطر قبل موافقة المالك.**

---

## الطور ١: الإعداد (بعد موافقة المالك على المرحلة ٠)

**الهدف:** الوحدة الفارغة والبنية التحتية التي تحتاجها القصص ١–٣، على `039-teacher-whiteboard` لا على فرع التجربة. بنية الاستيراد كلها في القصة ٤.

- [ ] T014 انقل من فرع التجربة **ما وافق عليه المالك فقط**:
  - `scripts/copy-excalidraw-fonts.mjs`، ومعه خطّافا `predev` و`prebuild` في `frontend/package.json`، وسطر `frontend/public/excalidraw/` في `.gitignore`؛
  - `lib/whiteboard/arabic-font.ts`؛
  - `lib/whiteboard/page-model.ts` واختباره؛
  - `components/whiteboard/BoardCanvasClient.tsx` و`components/whiteboard/BoardCanvas.tsx`؛
  - ملف Cairo ورخصته؛
  - تثبيت Excalidraw.

  ولا تنقل صفحة `lab`.
- [ ] T015 [P] أنشئ الوحدة `backend/app/Modules/Whiteboard/WhiteboardServiceProvider.php` ترث `App\Shared\Modules\Module`، ومعها `routes/api.php` فارغاً. وأضف `app/Modules/Whiteboard/Database/Migrations` إلى `backend/phpstan.neon`
- [ ] T016 [P] أنشئ `backend/config/whiteboard.php` بالقيم الافتراضية الثلاث التي تقرؤها القصص ١–٣ (`max_scene_bytes`، `max_board_bytes`، `max_pages_per_board`) من data-model.md، وأضف المفاتيح الثلاثة إلى `PlatformSettings::KEYS` في `backend/app/Modules/Tenancy/Support/PlatformSettings.php`، **كلها لا بعضها** (مراجعة الخطة). مفاتيح الاستيراد في T080
- [ ] T017 [P] أضف حقول المفاتيح الثلاثة إلى `backend/app/Modules/Tenancy/Filament/Pages/ManagePlatformSettings.php` بعنوان «السبّورة»، بقيم دنيا ≥ 1
- [ ] T018 [P] أضف `\App\Modules\Whiteboard\Models\Board::class` إلى `full_allowance_owners` في `backend/config/media.php`
- [ ] T019 [P] أضف محدِّدات المعدّل في `AppServiceProvider::registerRateLimiters()` (`backend/app/Providers/AppServiceProvider.php`):
  - `whiteboard-autosave`: سخيّ ومحسوب بالمستخدم. يتّسع لحفظ كل 1.5 ثانية ونبضة كل 5 ثوانٍ لثلاثة تبويبات؛
  - `whiteboard-files`: سخيّ، حتى يتّسع لـ 300 ملف تُجلَب عند فتح سبّورة.

  ومعه اختبار في `backend/tests/Feature/Whiteboard/RateLimitersTest.php` أن الاسمين مسجّلان (`RateLimiter::limiter()` غير null) ويحسبان بالمستخدم. `whiteboard-import` في T080.
- [ ] T020 [P] أضف `"/whiteboard"` إلى `NO_FLOATING_CHROME_PREFIXES` في `frontend/src/components/ui/FloatingActions.tsx`، ومعها حالة في اختباره المجاور إن كان له اختبار. السبب: زرّ الواتساب لا يجوز أن يظهر في التبويب المشارَك (ق-١٠)
- [ ] T021 [P] أضف `lessonsManage: "lessons.manage"` إلى `frontend/src/lib/permissions.ts`

---

## الطور ٢: الأساس (يحجب كل القصص)

**نقطة التحقّق:** الجداول والنماذج والسياسة والقفل والعزل جاهزة وخضراء قبل أي قصة.

- [ ] T022 اكتب هجرة `backend/app/Modules/Whiteboard/Database/Migrations/2026_10_02_000100_create_whiteboard_tables.php`، بالجداول الأربعة كما في data-model.md حرفياً:
  - `scene` من النوع `longText`؛
  - `editor_seen_at` **و**`editor_handover_at` من النوع `timestamp(3)`، ومثلهما `board_imports.dispatched_at`؛
  - كل مفتاح أجنبي إلى `media_assets` بـ `nullOnDelete`؛
  - `pending_operation` (`building` · `duplicating` · `deleting`)، و`client_tab` و`client_rev` على `board_pages`، و`insert_after_page_id` و`user_id` (NOT NULL) و`dispatch_attempts` على `board_imports`؛
  - أعمدة الحالة `string` مع PHP enum، على عرف `media_assets.status`، و`board_imports.status` بافتراضي قاعدة البيانات `uploading`؛
  - الفهارس كلها، وكل اسم فهرس ≤ 64 حرفاً.
- [ ] T023 [P] اكتب الـ enums في `backend/app/Modules/Whiteboard/Enums/`:
  - `BoardBackground`: white، blackboard، greenboard؛
  - `BoardImportStatus`: uploading، queued، converting، done، failed؛
  - `BoardImportFailure`: too_many_pages، unsupported، corrupt، timeout، board_deleted؛
  - `BoardPendingOperation`: building، duplicating، deleting.
- [ ] T024 [P] اكتب النماذج `Board`، `BoardPage`، `BoardImport`، `BoardLessonExport` في `backend/app/Modules/Whiteboard/Models/`:
  - `BelongsToWorkspace` و`HasUuid`؛
  - `$fillable` يحوي الأعمدة التي تكتبها الـ Actions كتابةً عادية (`title`، `scene_bytes`، `background_asset_id`…)؛
  - ولا يحوي `version`، ولا `editor_*`، ولا `status`، ولا `pages_count`، ولا `pending_operation`، ولا `client_tab`/`client_rev`، لأنها تُكتب بتحديثات شرطية فقط.
- [ ] T025 [P] اكتب المصانع في `backend/database/factories/Modules/Whiteboard/`:
  - `BoardFactory`: المُنشئ عضو في مساحة العمل نفسها بدور teacher، و`pages_count` يساوي عدد الصفحات الفعلي دائماً (حالة `withPages(n)` تُنشئ الصفحات وتضبط العدد معاً)؛
  - `BoardPageFactory`: `position` متتالٍ، و`scene` صالح، وإطار معرّفه `frame:{uuid}`؛
  - `BoardImportFactory` و`BoardLessonExportFactory`؛
  - أيّ قيمة `editor_*` تُكتب بمنسِّق `BoardLock` نفسه (`Y-m-d H:i:s.v`).

  ⚠️ لا مصنع يسمّي الأب نفسه مرّتين (`courses.md`).
- [ ] T026 اكتب `backend/app/Modules/Whiteboard/Support/BoardOwnership.php`:
  - `owningTeacherId(Board)` حسب D1: مدرّس المقرّر عبر `Course::teacherUser()` مع قراءة المقرّر بـ `withTrashed()`، وإلا `owner_user_id`؛
  - `primeFor(Collection $boards)` يستدعي `Course::primeCreatorTeaches()` مرة واحدة للصفحة كلها.
- [ ] T027 اكتب `backend/app/Modules/Whiteboard/Policies/BoardPolicy.php` كما في contracts/api.md § التفويض حرفياً:
  - كل دالة تفحص بنفسها سياقاً **غير null ومطابقاً**، ولا تعيد استخدام `BasePolicy::belongsToCurrentWorkspace`؛
  - **رفض المتعلّم مكتوب إيجاباً:** يوجد صفّ عضوية بدور محوري من أدوار الطاقم **و** `! StaffAccounts::isLearnerAccount($user)` (`backend/app/Modules/Tenancy/Support/StaffAccounts.php:43`)؛
  - `update` على نمط `StoreItemPolicy` (`LESSONS_MANAGE` + `mayActOnCourse`)، والمدير الذي ليس صاحب السبّورة لا يمرّ أبداً؛
  - دالة `takeLock` لصاحب السبّورة وحده.

  ثم سجّل السياسة في مزوّد الوحدة.
- [ ] T028 اكتب `backend/app/Modules/Whiteboard/Support/BoardLock.php`، بجمل R-09 الشرطية حرفياً:
  - **ساعة PHP واحدة:** `$now = CarbonImmutable::now()` مرّة لكل استدعاء، وتُربط **نصوصاً**: `$now->format('Y-m-d H:i:s.v')` و`$now->subSeconds(10)->format(…)` و`$now->subSeconds(120)->format(…)`؛ لا حساب تواريخ داخل SQL؛
  - وسائط موضعية `?`، ولا اسم مكرّر أبداً؛
  - كل كتابة في `editor_*` تمرّ بمنسِّق واحد في هذا الصنف؛
  - `acquire(board, user, tab)`، و`take(board, tab)`، و`release(board, user, tab)`؛
  - `assertHeldBy(board, user, tab)` تستدعيها كل Action تكتب؛
  - التجديد يمسّ `editor_seen_at` وحده عبر `DB::table`، بلا `updated_at`، ونجاحه صفّ واحد **أو** صفر صفوف مع قراءة تُظهر أنني الحامل بلا تسليم منقضٍ. والاكتساب والأخذ والتحرير نجاحها صفّ واحد؛
  - تعليق في رأس الصنف: الافتراض خادم تطبيق واحد.
- [ ] T029 [P] اكتب `backend/app/Modules/Whiteboard/Support/BoardPageGate.php`، أول جملة في كل معاملة تمسّ الصفحات، بوسائط موضعية:
  - `open(board, int $delta)` لـ `$delta > 0`: `UPDATE boards SET pages_count = pages_count + ? WHERE id = ? AND pages_count + ? <= ? AND pending_operation IS NULL`، وتُرمى بـ 422 `too_many_pages` إن لم يتأثّر صفّ؛
  - `lock(board)` لإعادة الترتيب (فرق صفر): `UPDATE boards SET id = id WHERE id = ?` **بلا فحص للعدد** (سابقة `backend/app/Modules/Community/Actions/PostMessage.php:225`)، لأن تحديثاً لا يغيّر شيئاً يعيد صفراً على MySQL؛
  - `close(board, int $n)` للحذف: `UPDATE boards SET pages_count = pages_count - ? WHERE id = ? AND pages_count >= ?`؛
  - `park(board)` تنقل المواضع إلى `max(position)+1+i` قبل الضغط. **لا سالب**، لأن العمود unsigned.
- [ ] T030 [P] اكتب `backend/app/Modules/Whiteboard/Support/WhiteboardPersonalData.php`:
  - ينفّذ عقد Compliance (التصدير، والمحو، والانتهاء) على `owner_user_id` و`editor_user_id` و`board_imports.user_id`، ولا يمسّ المحتوى؛
  - **المحو:** يُبقى المحتوى ويُعاد إسناد `owner_user_id` و`board_imports.user_id` إلى مالك مساحة العمل، ويُفرَّغ `editor_user_id` (data-model § قرار المحو)؛
  - يُوسَم `compliance.personal_data` في `WhiteboardServiceProvider`؛
  - صفوف الوحدة في `backend/database/seeders/DataCategorySeeder.php`، وهجرة `2026_10_02_000200_backfill_whiteboard_data_categories.php` تستدعي `(new DataCategorySeeder)->run()` على سابقة `Store/Database/Migrations/2026_08_29_001300_backfill_store_data_categories.php`؛
  - ومعه `backend/tests/Feature/Whiteboard/WhiteboardPersonalDataTest.php`: التصدير يمرّ على صفوف الوحدة، والمحو يُعيد الإسناد ويُبقي المحتوى.
- [ ] T031 [P] أضف الجداول الأربعة إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php`
- [ ] T032 [P] وسّع `backend/tests/Feature/Identity/SqliteGreenFormsTest.php` ليفحص كل ملف تحت `app/Modules/Whiteboard/` (بعد نزع التعليقات، كعادته) بحثاً عن: `<=>`، و`INTERVAL`، و`NOW(`، و`||` داخل نصّ SQL، والوسيط المسمّى المكرّر في الجملة الواحدة. ويؤكّد أنه فحص ملفاً واحداً على الأقلّ، فلا ينجح فارغاً
- [ ] T033 اكتب `backend/tests/Feature/Whiteboard/BoardPolicyTest.php`. كل حالة تفشل إن حُذف الفرع المقابل لها في السياسة:
  - مدير ليس صاحب السبّورة يرى ويحذف، وفي `update` و`takeLock` يُرفض؛
  - مدير يدرّس المقرّر يحرّر (D1)؛
  - مساعد أنشأ سبّورة مقرّر: صاحبها مدرّس المقرّر لا المساعد؛
  - مساعد محصور خارج نطاقه يُرفض، ومساعد محصور بلا مقرّر يُرفض في `create`؛
  - عضو أُزيل: يُضبط team id في `PermissionRegistrar` يدوياً والسياق يبقى null، فيُرفض حتى على سبّورته؛
  - طالب أُعطي `LESSONS_MANAGE` بالخطأ يُرفض، على سبّورة مقرّرها حيّ؛
  - حساب وليّ أمر (`platform_role` = Parent) يُرفض؛
  - مدير ليس صاحب السبّورة، على سبّورة مقرّرها حيّ؛
  - مقرّر محذوف ناعماً على سبّورة **أنشأها مساعد**: فرع المساعد يُرفض، وصاحب السبّورة (مدرّس المقرّر) يبقى.
- [ ] T034 اكتب `backend/tests/Feature/Whiteboard/BoardLockTest.php`:
  - اكتساب، ونبضة، وتحرير؛
  - تبويب ثانٍ يأخذ 423؛
  - انتهاء بعد 120 ثانية عبر `travel()` (يعمل لأن الساعة ساعة PHP)؛
  - أخذ صاحب السبّورة مع التسليم بعد 10 ثوانٍ؛
  - تكرار الأخذ يعيد `handover_at` نفسه؛
  - التحرير أثناء تسليم معلّق يمرّر القفل مباشرةً؛
  - التجديد الذي يعيد صفراً مع بقاء الحامل يُعدّ نجاحاً (يُصنع بتجميد الوقت)؛
  - **فحص بنيوي:** العمودان `editor_seen_at` و`editor_handover_at` بدقّة 3 في الهجرة، والقيم المربوطة (تُلتقط بـ `DB::beforeExecuting`) نصوص فيها ملّي ثانية.

  **السباق:** بـ `DB::beforeExecuting` على جزء من جملة التجديد وحارس مرّة واحدة، يُكتب الاكتساب المنافس (بعد انقضاء المهلة) داخل الفجوة، ثم يُؤكَّد أنه جرى وأن الحامل القديم لا يجدّد.
- [ ] T035 أنشئ الحارس `frontend/src/app/(app)/whiteboard/layout.tsx`:
  - استخرج تحويل تسجيل الدخول من `frontend/src/app/(app)/(shell)/layout.tsx:117-122` إلى `frontend/src/lib/use-require-sign-in.ts`؛
  - استخدمه في الـ shell وفي هذا الـ layout، **دون تغيير سلوك الـ shell**؛
  - ومعه `frontend/src/lib/use-require-sign-in.test.ts`: الضيف يُحوَّل إلى تسجيل الدخول ولا يرى شاشة رفض، والمسجَّل لا يُحوَّل.
- [ ] T036 [P] اكتب `frontend/src/lib/whiteboard/strings.ts`، كائناً عربياً `as const` يمرّ منه كل نصّ في السبّورة:
  - الأعداد دوالّ تمرّ بـ `counted()`، وأضف `pages` و`boards` إلى `NOUNS` في `frontend/src/lib/labels.ts`؛
  - رسائل الفشل لكل رمز في contracts/api.md.
- [ ] T037 [P] اكتب `frontend/src/lib/whiteboard/excalidraw-api.ts`، الباب إلى Excalidraw:
  - `loadPage`: يعطي `updateScene(NEVER)` ثم `history.clear()`، ويُنهي محرّر النصّ قبل الانتقال؛
  - `fitToFrame`، و`addFilesFor(pageIds)`؛
  - `exportPage(png|svg)`.

  ⚠️ لا يستورد من `@excalidraw/excalidraw` إلا هذا الملف و`components/whiteboard/BoardCanvas.tsx` (المكوّن و`index.css` وحدهما). ومعه حارس vitest `frontend/src/lib/whiteboard/single-excalidraw-door.test.ts` على شكل `frontend/src/lib/no-hand-rolled-sign-in.test.ts:30-37` (ينزع التعليقات أولاً): يرفض أيّ استيراد لـ `@excalidraw/excalidraw` خارج الملفّين، وأيّ استيراد ثابت لـ `mathlive` أو `mathjax` أو `three` خارج `dynamic()`، وأيّ `fetch(` خام تحت `src/lib/whiteboard/`.
- [ ] T038 [P] اكتب `frontend/src/lib/whiteboard/custom-data.ts` بالأنواع وبـ `migrateCustomData`، ومعه `custom-data.test.ts`:
  - كل إصدار أقدم يُرقّى؛
  - النوع المجهول يبقى كما هو ولا يُحذف؛
  - شكل `quran` مسطّح.
- [ ] T039 [P] أضف إلى `frontend/src/lib/api.ts` الدالّة `api.blob(path)` وخيار `keepalive`، بدل ترويسات تُبنى باليد (`lib/api.ts:24-25` لا يعيد توكناً على الخادم، فكل جلب للسبّورة في المتصفّح). ثم اكتب `frontend/src/lib/whiteboard/api.ts` ملتفّاً حوله:
  - الأنواع تطابق `BoardResource` و`BoardDetailResource` و`PageResource`؛
  - `scene` يُقرأ نصّاً ثم يُفكّ في المتصفّح؛
  - الملفات عبر `api.blob`، والتحرير عند الإغلاق عبر `keepalive`.

---

## الطور ٣: القصة ١ (P1): سبّورة عربية مقروءة على هاتف ضعيف 🎯

**الهدف:** المدرّس يفتح سبّورة، يكتب بالعربي والمختلط، يرسم بالقلم، ويشاركها والطالب يقرأ.

**الاختبار المستقل:** إنشاء سبّورة ثم فتحها ثم الكتابة والتصدير، بلا حفظ على الخادم ولا استيراد.

- [x] T040 [US1] اكتب في `backend/app/Modules/Whiteboard/Actions/` الـ Actions `CreateBoard` و`UpdateBoard` و`ListBoards`، ومعها `BoardData` في `Data/`:
  - تحقّق `WorkspaceRules::exists(…,'uuid')`؛
  - الدرس من المقرّر نفسه، أو يُشتقّ المقرّر من الدرس؛
  - المساعد المحصور يسمّي مقرّراً في نطاقه، ولا ينقل السبّورة إلى مقرّر خارج نطاقه ولا إلى «بلا مقرّر»؛
  - تغيير `course`/`lesson` لصاحب السبّورة الحالي وحده (D1)، ثم يُعاد فحص `update` على الحالة الجديدة قبل الالتزام؛
  - `CreateBoard` يُنشئ صفحة أولى فارغة فيها إطارها؛
  - `ListBoards`: الاستعلام مضيَّق بمحمول `view`، ومرشّحاته `q` (العنوان) و`course` و`lesson` و`mine`. (`class_session` و`session=` في T108.)
- [x] T041 [US1] اكتب `BoardController` بالدوالّ `index` و`store` و`show` و`update` في `backend/app/Modules/Whiteboard/Http/Controllers/`، والطلبات `StoreBoardRequest` و`UpdateBoardRequest` و`ListBoardsRequest`، وثلاثة Resources:
  - `BoardResource` و`BoardDetailResource` و`PageResource`؛
  - `scene` يُرجَع **نصّاً خاماً بلا فكّ**؛
  - `can` محسوب من بيانات مهيّأة مسبقاً؛
  - القائمة: `with()` لكل العلاقات، وأعمدة المستخدم `id,uuid,first_name,last_name`، والصفحات عبر `toResponse`.

  ثم الطرق في `routes/api.php` بحدودها المسمّاة.
- [x] T042 [US1] اكتب `backend/tests/Feature/Whiteboard/BoardCrudTest.php`:
  - إنشاء وقراءة وتعديل؛
  - القائمة لا تُظهر لمساعد محصور سبّورات خارج نطاقه، وعدد الصفحات في القائمة يطابق ذلك؛
  - سبّورة من مساحة أخرى تعطي 404 مطابقاً لسبّورة غير موجودة؛
  - PATCH `course`/`lesson` من غير صاحب السبّورة يُرفض، ونقل يُفقد المستدعي `update` يُرفض، والمساعد المحصور لا ينقل إلى مقرّر خارج نطاقه ولا إلى null؛
  - **تطابق `can`:** `can.*` في الـ Resource يساوي `Gate::allows` على عيّنة من الممثّلين؛
  - ميزانية الاستعلامات للقائمة ثابتة مع 20 صفاً، بعد تسخين حتى الاستقرار.
- [x] T043 [US1] اكتب `backend/tests/Feature/Whiteboard/BoardIsolationTest.php`، مصفوفة بيانات على كل طريق للسبّورة × الممثّلين، بالرموز الدقيقة:
  - مساحة عمل أخرى؛ uuid متداخل من سبّورة أخرى في المساحة نفسها؛ عضو أُزيل؛ طالب يحمل `LESSONS_MANAGE`؛ مساعد محصور خارج نطاقه؛ مدير ليس صاحب السبّورة (للكتابة)؛
  - يؤكّد أن عدد الطرق في المصفوفة يساوي عدد الطرق المسجّلة (method × URI) التي يبدأ `uri` فيها بـ `api/v1/boards`، فالطريق الجديد بلا صفّ يُحمِّره.

  تُكتب اليوم بطرق القصة ١، **وكل مهمة طرق بعدها تضيف صفوفها**.
- [x] T044 [US1] ابنِ `frontend/src/app/(app)/whiteboard/[board]/page.tsx`: صفحة خادم **تفكّ `params` فقط** (وعد في Next 15) وتعرض `<BoardCanvasClient boardUuid=…/>`. الجلب يحدث في المتصفّح خلف حارس T035، لا على الخادم (لا توكن هناك)
- [x] T045 [US1] أكمل `frontend/src/components/whiteboard/BoardCanvas.tsx` حسب R-04 وR-05 وR-07:
  - يجلب السبّورة عبر `lib/whiteboard/api.ts`؛
  - `await ensureArabicFont()` قبل التركيب؛
  - المشاهد المحمّلة تمرّ على `restoreElements(…,{refreshDimensions:true})`؛
  - الخلفيات الثلاث بلون قلمها، و`STREAM_DEFAULTS`؛
  - قناع خارج الإطار، وزوم محسوب عند تغيير حجم النافذة.

  ومعها وضع عرض نظيف:
  - `zenModeEnabled`؛
  - إخفاء ما لا يهمّ الطالب عبر `UIOptions`، فشريط الأدوات يُطوى ويبقى زرّ يعيده.
- [x] T046 [P] [US1] أضف إلى `frontend/src/lib/whiteboard/page-model.test.ts`: تغيير الخلفية يغيّر لون القلم الافتراضي (`penFor(background)`، US1-3)، و`refitOnResize` الخالصة التي يستدعيها `BoardCanvas` تعيد الملاءمة عند تغيير الحجم (US1-5)
- [x] T047 [P] [US1] اكتب `frontend/src/components/whiteboard/ExportMenu.tsx`: تصدير الصفحة PNG وSVG عبر `excalidraw-api.ts`، وكل نصوصها من `strings.ts`
- [x] T048 [US1] اكتب `frontend/src/components/whiteboard/BoardSettings.tsx`: إعادة تسمية السبّورة واختيار خلفيتها (PATCH)، في السبّورة نفسها
- [x] T049 [US1] ركّب `ExportMenu` و`BoardSettings` على الشاشة عبر `renderTopRightUI` في `BoardCanvas.tsx`، ومعه `frontend/src/components/whiteboard/BoardToolbar.test.tsx` يضغط كل زرّ ويرى أثره
- [x] T050 [US1] ابنِ صفحة القائمة `frontend/src/app/(app)/(shell)/manage/boards/page.tsx`:
  - محروسة بـ `lessonsManage`؛
  - إنشاء ثم فتح في تبويب جديد بـ `target="_blank"`، لأن CSS الخاص بـ Excalidraw بلا طبقات ويبقى بعد التنقّل؛
  - عنصر في `frontend/src/lib/panel-nav.tsx` (يحتاجه `panel-nav.test.ts`) ومعه `layout.tsx` بعنوان (يحتاجه `frontend/src/app/(app)/(shell)/route-titles.test.ts`).
- [x] T051 [US1] اكتب `frontend/e2e/whiteboard.spec.ts` › «النصّ العربي»، على مشروع واحد فقط: `test.skip(testInfo.project.name !== 'desktop-light')` (`frontend/playwright.config.ts`):
  - الكتابة: أداة النصّ ثم `textarea.excalidraw-wysiwyg` ثم `keyboard.type('الماء H₂O يغلي عند 100 درجة')` ثم Escape؛
  - SVG: فيه `@font-face` لـ `Segoe UI Emoji` بمصدر `data:font/woff2;base64` و`unicode-range` العربي، ونصّ `direction="rtl"` بقيمة `text-anchor` المصحّحة كما ثبتت في T011؛
  - PNG: `toHaveScreenshot` على هذا المشروع وحده.

  وأضف دالة مساعدة لمدرّس محلّي في `frontend/e2e/teacher-account.ts` إن لزم.

- [ ] T143 [US1] (قرار المالك على المرحلة ٠) اكتب `recolorForBackground(elements, from, to)` في `frontend/src/lib/whiteboard/page-model.ts`: كل لون من لوحة الخلفية القديمة يصير نظيره في الموضع نفسه من الجديدة، وما سواه يبقى. ويُستدعى من `BoardSettings` عند تغيير الخلفية عبر `updateScene(IMMEDIATELY)`. ومعه اختبار: النصّ الأسود على الأبيض يصير فاتحاً على الأسود، ولون غريب يبقى
- [ ] T144 [US1] (قرار المالك) وضع «عرض» يُخفي **كل** أدوات Excalidraw (شريط الأدوات، والقوائم، والتذييل) بـ CSS مقيّد بصنف على الحاوية، ويُظهرها حين يقترب المؤشّر من أعلى 48 px. ومعه اختبار يرى الصنف يُضاف ويُزال
- [ ] T145 [US1] (قرار المالك) `injectArabicFontIntoSvg` يضمّن أيضاً Excalifont من `public/excalidraw/fonts/Excalifont/` بالنطاقات نفسها التي يسجّلها Excalidraw، حتى يطابق اللاتيني والأرقام الشاشة. ومعه اختبار: SVG المصدَّر فيه `@font-face` لـ Excalifont بمصدر `data:`

---

## الطور ٤: القصة ٢ (P1): لا يضيع شيء عند انقطاع الشبكة أو إغلاق التبويب

**الهدف:** الحفظ المحلي والخادمي، والقفل، والاستعادة.

**الاختبار المستقل:** ارسم ثم اقطع الشبكة، ثم ارسم ثم أغلق التبويب وافتحه، فلا يضيع شيء.

- [x] T052 [US2] اكتب `SaveBoardScene` في `backend/app/Modules/Whiteboard/Actions/`، ومعه `SaveSceneRequest` (يفحص `Content-Length` قبل الفكّ) و`SceneData`:
  1. فحص الشكل؛
  2. لا `dataURL`، ولا عناصر `embeddable` أو `iframe` (422 `bad_element`)؛
  3. `fileIds` إمّا قوالب بالتعبير الصارم المثبَّت (`\z` والمعدِّل `D`) وإمّا ملفات `Ready` من نوع `image/png|jpeg` لهذه السبّورة؛
  4. `element.link` يُقبل http(s) أو مساراً نسبياً فقط، بتعبير مثبَّت الطرفين؛
  5. سقف الصفحة وسقف السبّورة كلها؛
  6. **جملة واحدة** تجمع الإصدار والقفل (R-09)، بساعة PHP ووسائط موضعية؛
  7. مفتاح الأثر الواحد `(tab, client_rev)`: يُعدّ مطبَّقاً فقط إن طابق الاثنان **و** الإصدار المرسَل = المخزَّن − 1؛
  8. التمييز بين `lock_lost` و`version_conflict` والإعادة المطبَّقة بقراءة بعد الفشل.
- [x] T053 [US2] اكتب `AcquireBoardLock` و`TakeBoardLock` و`ReleaseBoardLock`، وكلها تمرّ بـ `BoardLock`، ومعها `LockRequest` (`tab`). ثم `BoardLockController` بالدوالّ `store` (اكتساب ونبضة) و`destroy` و`take`، و`BoardPageController@scene`، بحدّ `whiteboard-autosave`:
  - كل طلب كتابة يسأل `update` من جديد؛
  - `take` يسأل `takeLock` لا `update`.

  وأضف صفوف الطرق الأربعة إلى `BoardIsolationTest`.
- [x] T054 [US2] اكتب `backend/tests/Feature/Whiteboard/SaveBoardSceneTest.php`:
  - إصدار قديم يعطي 409، ولا كتابة؛
  - الحفظ بلا قفل يعطي `lock_lost`؛
  - **السباق:** بـ `DB::beforeExecuting` على جملة الحفظ وحارس مرّة واحدة، يُكتب أخذ القفل المنقضي داخل الفجوة، ثم يُؤكَّد أنه جرى وأن الحفظ أعطى `lock_lost` بلا كتابة؛
  - `(tab, client_rev)` المكرّر يعطي 200 بلا زيادة في الإصدار؛ و`client_rev` نفسه من تبويب آخر لا يُعدّ مكرّراً؛
  - `dataURL` يعطي 422 `inline_file`، و`embeddable`/`iframe` يعطيان 422 `bad_element`؛
  - ملف من سبّورة أخرى، وملف `Pending`، يعطيان 422 `unknown_file`؛
  - `template:grid:v1` يمرّ، و`template:grid:v1x` و`template:../a:v1` يعطيان `unknown_file`؛
  - `javascript:` و`JaVaScRiPt:` ورابط بمسافة في أوّله و`data:` و`//evil.example` تعطي 422 `bad_link`؛
  - تجاوز سقف السبّورة يعطي `board_too_large`؛
  - مدير ليس صاحب السبّورة: `POST /lock` يعطي 403؛ ومساعد يستدعي `/lock/take` يعطي 403؛
  - سحب النطاق أو الصلاحية من حامل القفل ثم الحفظ يعطي 403.
- [x] T055 [P] [US2] اكتب `frontend/src/lib/whiteboard/draft-store.ts`، IndexedDB خاماً:
  - المفتاح `board:{b}:page:{p}:user:{u}`، والقيمة `{scene, ackedVersion, rev, dirty}`؛
  - كل قراءة وكتابة داخل `try/catch`، وفي الفشل يُبلَّغ `unavailable`؛
  - `classifyDraft(draft, serverVersion)` دالّة خالصة: `restore`، أو `ask`، أو `discard`.

  ومعه `draft-store.test.ts`: فرع `unavailable` يُختبر اليوم في jsdom (لا `indexedDB` فيها)، و`classifyDraft` بحالاتها. المسار السعيد يحتاج `fake-indexeddb`: **بموافقة المالك على الاعتماد**، ولا يُثبَّت قبلها. _(لم يُثبَّت؛ المسار السعيد يغطّيه اختبار المتصفّح «الاستعادة من الجهاز»)_
- [x] T056 [US2] اكتب `frontend/src/lib/whiteboard/autosave.ts`، حسب R-08 بعد المراجعة، وكل اعتماد يُحقن (`requestIdleCallback`، و`fetch` عبر `lib/whiteboard/api.ts`، والمخزن، و`getSceneVersion`):
  - `onChange` يعلّم الصفحة `dirty` فقط إن تغيّر `getSceneVersion`؛
  - الكتابة المحلية للصفحة المتّسخة بعد انتهاء الضربة، في `requestIdleCallback` بحدّ أقصى ثانيتين (وإلا `setTimeout`)، وأثناء حمل القفل فقط؛
  - طلب PUT واحد قيد التنفيذ لكل صفحة، وآخر نسخة تنتظر دورها؛
  - 409 `version_conflict`: يتوقّف الحفظ، ولا رفع تلقائياً للإصدار؛
  - `lock_lost`: يتوقّف الحفظ، وتُكتب مسودّة أخيرة، وتصير السبّورة للقراءة فقط؛
  - `handover_requested` في النبضة: يُنهي الـ PUT الجاري ثم `DELETE /lock`؛
  - 409 `workspace_changed`: يتوقّف ولا يستأنف؛
  - 422 دائم: لا إعادة؛ وانقطاع الشبكة و429 و5xx: تراجع متزايد بالمفتاح نفسه، واستئناف عند `online`؛
  - `pagehide`: يُرسل فقط إن لم يكن PUT جارياً، وفقط إن كان المشهد ≤ 64 KB (حدّ `keepalive`)؛ و`visibilitychange` على أفضل جهد؛
  - حذف صفحة يلغي مؤقّتها ومسودّتها.
- [x] T057 [P] [US2] اكتب `frontend/src/lib/whiteboard/autosave.test.ts` بمؤقّتات وهمية واعتمادات محقونة:
  - ثلاثة تغييرات أثناء PUT لم يُجب = طلب واحد إضافي فقط، يحمل آخر مشهد؛
  - بعد 409 لا تستأنف التغييرات اللاحقة الحفظ؛
  - `dirty` لا يُعلَّم إلا بتغيّر إصدار المشهد؛
  - حذف الصفحة يلغي المؤقّت؛
  - التفريغ في `pagehide`، ولا يُرسل إن كان PUT جارياً أو المشهد أكبر من 64 KB؛
  - `lock_lost` يكتب المسودّة ويوقف الحفظ؛ و`handover_requested` ينهي الجاري ثم يحرّر؛
  - تبويب للقراءة فقط لا يكتب المسودّة.
- [x] T058 [US2] اكتب `SaveIndicator.tsx` بخمس حالات من `strings.ts` (محفوظ، جارٍ الحفظ، بدون اتصال ومحفوظ على الجهاز، فشل الحفظ، «الحماية من الانقطاع غير متاحة»)، و`LockBanner.tsx`:
  - من يحرّر الآن، ونصّ «للقراءة فقط»؛
  - زرّ «خُذ التحرير» إن كان `can.take_lock`؛
  - تنبيه التسليم للحامل.

  وفي `BoardCanvas.tsx`:
  - النبضة كل 5 ثوانٍ؛
  - التحرير عند الإغلاق عبر `lib/api.ts` بخيار `keepalive`؛
  - حوار الاستعادة عند الفتح حسب `classifyDraft`؛
  - حوار التعارض `ConflictDialog.tsx`: «احفظ نسختي كصفحة جديدة» أو «خذ نسخة الخادم».
- [x] T059 [P] [US2] اكتب `frontend/src/components/whiteboard/SaveIndicator.test.tsx` و`ConflictDialog.test.tsx`: المؤشّر يقول «جارٍ الحفظ» أثناء الطلب (US2-1)، وحوار التعارض يظهر بخياريه عند 409 وكل خيار يستدعي ما يخصّه (US2-4)
- [x] T060 [US2] أضف إلى `frontend/e2e/whiteboard.spec.ts` › «الحفظ والاستعادة» (المشروع نفسه وحده):
  - `context.setOffline(true)` ثم رسم ثم المؤشّر يقول «بدون اتصال»، ثم عودة الشبكة فيقول «محفوظ»، ثم قراءة الصفحة عبر الواجهة البرمجية تُظهر الإصدار أعلى والعنصر موجوداً. وراقب أن عامل الخدمة (`src/lib/service-worker.ts`) لا يحوّل إلى `/offline` أثناء الانقطاع؛
  - الاستعادة: `page.route('**/scene', r => r.abort())` ثم رسم ثم إغلاق ثم فتح، فيظهر حوار الاستعادة وتُستعاد المسودّة؛
  - التبويب الأوّل ليس للقراءة فقط، والتبويب الثاني يرى «للقراءة فقط».

---

## الطور ٥: القصة ٣ (P1): صفحات تُحضَّر قبل الحصة، والتنقّل فوري

**الاختبار المستقل:** أضف وكرّر واحذف ورتّب، وتنقّل بالاختصارات، فيكون التنقّل فورياً وتملأ الصفحة الإطار.

- [x] T061 [US3] اكتب `AddBoardPage` و`DeleteBoardPage` و`ReorderBoardPages`، ومعها `AddPageRequest` و`ReorderPagesRequest`، و`BoardPageController` بالدوالّ `store` و`order` و`destroy`:
  - كلها تبدأ ببوابة الصف `BoardPageGate` (الإضافة `open`، والترتيب `lock`، والحذف `close`)، وتتطلّب القفل؛
  - `duplicate_of` و`after` من السبّورة نفسها؛
  - `pages[]` يجب أن يساوي مجموعة صفحات السبّورة كاملة، وإلا 409 `pages_changed`؛
  - الصفحة الأخيرة لا تُحذف، وإلا 422؛
  - الركن عند `max+1` ثم الضغط.

  وأضف صفوف الطرق إلى `BoardIsolationTest`.
- [x] T062 [US3] اكتب `RequestBoardFile` و`CompleteBoardFile` في `backend/app/Modules/Whiteboard/Actions/`، ومعهما `RequestBoardFileRequest` و`BoardFileController` بالدوالّ `store` و`complete` و`show`:
  - على نمط `RequestStoreFile` و`CompleteStoreFile`، والبحث بالمالك يدوياً؛
  - القائمة `['image/png','image/jpeg']` وحدها؛
  - `show`: للملفات `Ready` من نوع `image/png|jpeg` فقط (الملف الأصلي المستورَد لا يُقدَّم من هنا)، و`nosniff`، و`private, max-age=86400`، و`throttle:whiteboard-files` مع `withoutMiddleware('throttle:api')`.

  ومعه اختبار: ملف من سبّورة أخرى يعطي 404، وملف `Pending` يعطي 404، وملف PDF للسبّورة يعطي 404. وأضف صفوف الطرق إلى `BoardIsolationTest`.
- [x] T063 [US3] أضف إلى `backend/config/horizon.php` مشرفاً `supervisor-whiteboard-ops`: الاتصال `redis-long`، والطابور `whiteboard-ops`، و`timeout` = 300، و`maxProcesses` = 1، في `defaults` و`environments.production` و`environments.local` و`waits`. ثم وسّع `backend/tests/Unit/QueueTimeoutInvariantTest.php`:
  - يؤكّد وجود المشرف **باسمه**؛
  - يؤكّد أن `DuplicateBoardJob` و`DeleteBoardJob` في المجموعة المفحوصة (التعبير في `:94` يطلب `public int $timeout = 300;` و`onQueue('whiteboard-ops')` نصّاً)؛
  - يؤكّد أن كل مشرف في `defaults` موجود في `environments.production`.
- [x] T064 [US3] اكتب `backend/app/Modules/Media/Actions/CopyLocalMediaAsset.php`: ينسخ بايتات ملف `Ready` على قرص `config('media.disk')` إلى ملف جديد `Ready` لمالك جديد، للمزوّد المحلي وحده، ويرفض غيره صراحةً. عقود المزوّد لا تعرف النسخ (`ingestFromUrl` وحده). ومعه اختبار
- [x] T065 [US3] اكتب `DuplicateBoard` و`DeleteBoard` ومهمّتيهما `Jobs/DuplicateBoardJob.php` و`Jobs/DeleteBoardJob.php`:
  - `pending_operation` بتحديث شرطي، وإلا 409 `operation_pending`؛
  - الإرسال بـ `DB::afterCommit(fn () => …::dispatch())` (سابقة `backend/app/Modules/Gamification/Actions/AwardPoints.php:138`)، لأن `after_commit` = false؛
  - في المُنشئ `onQueue('whiteboard-ops')` حرفياً، و`public int $timeout = 300;`، و`$this->connection = 'redis-long'`؛
  - النسخ:
    1. السبّورة الجديدة أوّلاً، مخفيّة بـ `pending_operation = building`؛
    2. `CopyLocalMediaAsset` لكل ملف جاهز، والنسخ ملك السبّورة الجديدة؛
    3. إعادة كتابة كل `fileId` و`fileIds` و`background_asset_id`، والصفحات في معاملة واحدة؛
    4. تفريغ `building`؛ وعند الفشل تُحذف السبّورة المخفيّة كلّها.
  - الحذف: إخفاء فوري، و`DeleteMediaAsset` بعد الالتزام، ومتساوي الأثر إن وصلت المهمّة مرّتين؛
  - `failed()` يفرّغ `pending_operation`؛
  - المهام تختم `workspace_id` صراحةً؛
  - الطرق `POST /boards/{board}/duplicate` و`DELETE /boards/{board}` (تحت `2fa.required`)، وصفوفهما في `BoardIsolationTest`.
- [x] T066 [US3] اكتب `backend/app/Modules/Whiteboard/Jobs/SweepWhiteboardJob.php` وجدوِله في `backend/routes/console.php`: `Schedule::job(new SweepWhiteboardJob, 'maintenance')->everyFiveMinutes()`، والمهمّة بـ `RunsAlone` (يطلبه `ScheduledSweepsRunAloneTest`) و`lazyById`:
  - السبّورة العالقة على `deleting` تُعاد مهمّة حذفها؛
  - العالقة على `building` تُحذف (نسخ لم يكتمل).

  ومعه اختبار أنه مجدول، وأن كل فرع يعمل. فروع الاستيراد في T084.
- [x] T067 [US3] اكتب `backend/tests/Feature/Whiteboard/BoardPagesTest.php`:
  - إعادة ترتيب تنتهي بالمواضع الصحيحة، و**القيم المربوطة في الركن لا تكون سالبة أبداً** (تُلتقط بـ `DB::beforeExecuting`)؛
  - **البوابة أوّل جملة:** بـ `DB::listen` مع حدث `TransactionBeginning` (لا `BEGIN` في `QueryExecuted`، وتحت `RefreshDatabase` المعاملة نقطة حفظ)، يُؤكَّد أن أوّل جملة بعد بداية كل معاملة تمسّ الصفحات (إضافة، ترتيب، حذف، استيراد) هي جملة البوابة؛
  - إعادة الترتيب لا تعتمد على عدد الصفوف: تنجح وصفّ السبّورة لم يتغيّر؛
  - `pages_changed`؛
  - عتبة 300 صفحة، وسباق البوابة: بـ `DB::beforeExecuting` تُضاف صفحة منافسة داخل الفجوة فيبقى العدد ≤ 300؛
  - **IDOR:**
    - `duplicate_of` من سبّورة خاصة أخرى في المساحة نفسها يعطي 404؛
    - `DELETE /boards/{mine}/pages/{theirs}` يعطي 404.
- [x] T068 [US3] اكتب `backend/tests/Feature/Whiteboard/BoardDuplicateTest.php` مع `Queue::fake([DuplicateBoardJob::class])` حين يُختبر الإرسال (الطابور في phpunit متزامن):
  - النسخة لا تشير إلى ملف من الأصل: حذف الأصل يُبقي صور النسخة، وتعديل النسخة يُبقي الأصل كما هو (US3-3)؛
  - نقرة ثانية على النسخ تعطي 409، والنقرة الثانية داخل الفجوة (بـ `DB::beforeExecuting`) تعطي 409 أيضاً؛
  - السبّورة `building` لا تظهر في القائمة ولا في `view`؛
  - `failed()` يفرّغ `pending_operation`، والحذف المكرّر لا يفشل؛
  - مدرّس بلا تحقّق ثنائي مُفعَّل يُرفض على `DELETE /boards/{board}`.
- [x] T069 [US3] أضف إلى `BoardCrudTest`: القائمة تُظهر اسم المقرّر والدرس لكل سبّورة (المقرّر المحذوف ناعماً باسمه ومعه `deleted:true`)، والمرشّح `q` يجد بالعنوان (US3-5)
- [x] T070 [P] [US3] اكتب `frontend/src/components/whiteboard/PagesSidebar.tsx`:
  - إضافة وتكرار وحذف عبر `ConfirmButton`، وسحب لإعادة الترتيب؛
  - صور مصغّرة كسولة: `exportToCanvas({maxWidthOrHeight:320})` في `requestIdleCallback`، للظاهر فقط عبر `IntersectionObserver`، ومخبّأة بإصدار الصفحة.
- [x] T071 [P] [US3] اكتب `frontend/src/components/whiteboard/PagesSidebar.test.tsx`: الصورة المصغّرة تُطلب للصفحات الظاهرة وحدها، ولا تُطلب ثانيةً للإصدار نفسه (US3-1)
- [ ] T072 [US3] اكتب في `BoardCanvas.tsx`: _(تمّ: الاختصارات والتحميل المسبق. **مؤجَّل:** إعادة تركيب اللوحة بعد ~40 صفحة، حتى يُقاس نموّ الذاكرة فعلاً)_
  - اختصارات «التالية» و«السابقة» (PageDown/PageUp و← →)، ولا تعمل أثناء تحرير النصّ؛
  - **التحميل المسبق:** كل البايتات تُجلب Blob عبر `api.blob` عند الفتح؛ `addFiles` للصفحة الحالية ±٢ فقط، و`dataURL` عند الطلب؛
  - إعادة التركيب في لحظة هادئة بعد حوالي 40 صفحة مُزارة (R-07، مراجعة الخطة: ب، الواجهة).
- [x] T073 [US3] اكتب `frontend/src/lib/whiteboard/image-insert.ts` ووصله بأداة الصورة في شريط الأدوات، ولصق ملف صورة، والسحب والإفلات:
  - كلها تمرّ بـ `RequestBoardFile`، بعد تصغير في المتصفّح إلى 2560 px كحدّ أقصى؛
  - العنصر يُدرج **بعد** `Ready` فقط، والرفع الفاشل يُزال برسالة من `strings.ts`؛
  - ومعه `image-insert.test.ts` للتصغير والترتيب.
- [x] T074 [US3] أضف النسخ والحذف (بـ `ConfirmButton`) في `manage/boards` وفي قائمة السبّورة نفسها، والحذف يقرأ `can.delete`
- [x] T075 [US3] ركّب `PagesSidebar` في `Sidebar` الخاصّ باللوحة، وأزرار النسخ والحذف، ومعه اختبار يضغط «إضافة صفحة» و«حذف» ويرى أثرهما
- [x] T076 [US3] أضف إلى `frontend/e2e/whiteboard.spec.ts` › «الصفحات»: إنشاء 10 صفحات، والتنقّل بالاختصار، وكل انتقال تظهر صفحته في أقل من ثانية (`performance.now`)، وإعادة الترتيب تبقى بعد إعادة التحميل

---

## الطور ٦: القصة ٤ (P2): استيراد PDF وPowerPoint وWord وصور كصفحات

**الاختبار المستقل:** PDF من 50 صفحة يصير 50 صفحة بالترتيب، وكل خلفية مقفلة.

**البنية التحتية للاستيراد تُشحن هنا** (قرار المالك 2026-10-02 أعلاه).

- [ ] T077 [US4] أضف إلى `backend/config/horizon.php` مشرفاً اسمه `supervisor-whiteboard`:
  - الاتصال `redis-long`، والطابور `whiteboard`، و`maxProcesses` = 1، و`timeout` = 900، و`tries` = 1؛
  - يُضاف في `defaults` و`environments.production` و`environments.local` و`waits`.

  ثم وسّع `QueueTimeoutInvariantTest`: المشرف موجود **باسمه**، و`ConvertBoardImportJob` في المجموعة المفحوصة، والاختبار **يفشل** إذا وُضع المشرف على `redis` (القيمة 90).
- [ ] T078 [US4] أضف إلى `docker/docker-compose.yml` و`docker/docker-compose.prod.yml` خدمة `gotenberg` من الصورة `gotenberg/gotenberg:8`:
  - على شبكة جديدة `gotenberg` بخاصية `internal: true`، **لا يتصل بها إلا `horizon`**، وبلا `ports:`؛
  - الأعلام `--api-timeout=120s --libreoffice-restart-after=10 --libreoffice-max-queue-size=1`، وأعلام تعطيل Chromium كما ثبتت في T011؛
  - `mem_limit: 1536m`؛ وحدّ ذاكرة لـ `horizon` أيضاً. **لا** يُرفع `stop_grace_period` لـ horizon (يبقى 90 ثانية): المهمّة المقطوعة تلتقطها المكنسة و`failed()`.
- [ ] T079 [P] [US4] أضف `poppler-utils` إلى سطر `apk add` في `docker/backend.Dockerfile`، وتحقّق أن `pdfinfo -v` يعمل في الصورة. ⚠️ تغيير هذا الملف يحرّك وسم `:reverb` (`docs/gotchas/deploy-ops.md`)، فهذا النشر يعيد تشغيل Reverb مرّة واحدة؛ اكتب ذلك في وصف طلب الدمج
- [ ] T080 [P] [US4] أضف إلى `backend/config/whiteboard.php` المفتاحين `import_max_bytes` و`import_max_pages` ومعهما `gotenberg_url` و`poppler_path` (`WHITEBOARD_POPPLER_PATH`) و`import_timeout` = 900؛ والمفتاحين إلى `PlatformSettings::KEYS` وحقليهما إلى `ManagePlatformSettings` (≥ 1)؛ ومحدِّد `whiteboard-import` (استيراد واحد في الدقيقة لكل مستخدم) في `AppServiceProvider`، ويُضاف اسمه إلى `RateLimitersTest`
- [ ] T081 [US4] اكتب `RequestBoardImport` و`CompleteBoardImport`، ومعهما `RequestBoardImportRequest`:
  - 422 `too_large` قبل التذكرة؛
  - **حدّ المستخدم:** المعاملة تبدأ بـ `UPDATE users SET id = id WHERE id = ?` (بلا فحص للعدد)، ثم تفحص `uploading`/`queued`/`converting` وتُدرج، وإلا 429 `import_in_progress`. **لا** `INSERT … WHERE NOT EXISTS`؛
  - الصفّ يولد `uploading`؛
  - `insert_after_page_id` من السبّورة نفسها؛
  - `CompleteBoardImport`: الملف المصدر `Ready`، و`size_bytes` **الفعلي** ≤ `import_max_bytes` (وإلا 422 `too_large`)، ثم `queued`، ثم `DB::afterCommit(fn () => ConvertBoardImportJob::dispatch(…))`.
- [ ] T082 [US4] اكتب `backend/app/Modules/Whiteboard/Support/DocumentConverter.php`، واجهة لها تنفيذان: `GotenbergPopplerConverter` و`FakeDocumentConverter` للاختبارات.
  - **Gotenberg:**
    - يرسل الملف باسم ثابت من النوع المكتشف (`source.docx`)، لا باسم العميل؛
    - إعادة المحاولة على 429 و503 وأخطاء الاتصال **داخل عميل HTTP** (`Http::retry`)، لا بـ `release`.
  - **poppler:**
    - Symfony `Process` بمصفوفة وسائط ومهلة؛
    - `pdfinfo` للعدّ، و`pdftoppm -f 1 -l {max} -scale-to 1920 -jpeg`؛
    - عدّ الصفحات مرة ثانية بعد التصيير.
  - **سلسلة المهل:** كل Process < عميل HTTP (مع إعاداته) < `--api-timeout` < مهلة المهمة.
- [ ] T083 [US4] اكتب `ConvertBoardImport` و`Jobs/ConvertBoardImportJob.php`:
  - في المُنشئ `onQueue('whiteboard')` حرفياً، و`public int $timeout = 900;`، و`$this->connection = 'redis-long'`، و`tries` = 1؛
  - **بلا `RunsAlone`**، و**بلا `release`**؛
  - انتقال `queued` إلى `converting` شرطي، فإعادة الإرسال لا تحوّل مرّتين؛
  - صور مؤقتة في مجلّد باسم uuid الاستيراد؛
  - **المعاملة الأخيرة:**
    1. `BoardPageGate::open(n)`، والبوابة تشترط `pending_operation IS NULL`؛ فإن كانت السبّورة تُحذف ينتهي الاستيراد `failed/board_deleted`؛
    2. الإدراج بعد `insert_after_page_id`، أو في الآخر إن حُذفت؛
    3. `MediaAsset` لكل خلفية؛
    4. عنصر صورة مقفل `doc-background` في كل صفحة؛
    5. `done` بـ `WHERE status = 'converting'`، وصفر صفوف = تراجع المعاملة كلها.
  - `workspace_id` و`uuid` صريحان؛
  - التنظيف في `finally`؛
  - `failed()` يتحقّق أن الحالة ما زالت `converting`.
- [ ] T084 [US4] وسّع `SweepWhiteboardJob` (T066) بفروع الاستيراد، بـ `lazyById`:
  - `converting` أقدم من مهلة المهمّة + هامش (900 + 300 ثانية) يصير `failed/timeout`، وتُنظّف ملفاته المؤقتة؛
  - `uploading` العالق يصير `failed`؛
  - `queued` العالق يُعاد إرساله، ويُحدَّث `dispatched_at` ويُزاد `dispatch_attempts`؛ بعد السقف `failed/timeout`.

  ومعه اختبار: كل فرع، وأن استيراداً `queued` أُعيد إرساله لا يتحوّل مرّتين.
- [ ] T085 [US4] اكتب `BoardImportController` بالدوالّ `store` و`complete` و`show`. `position` يُعدّ `withoutWorkspaceScope()` مرتّباً بـ `(created_at, id)`. وأضف صفوف الطرق الثلاثة إلى `BoardIsolationTest`
- [ ] T086 [US4] اكتب `backend/tests/Feature/Whiteboard/BoardImportTest.php` بـ `FakeDocumentConverter`:
  - 50 صفحة تصير 50 صفحة بالترتيب، والخلفيات مقفلة؛
  - 101 صفحة تعطي `failed/too_many_pages` بلا صفحات جزئية؛
  - ملف تالف يعطي `failed/corrupt`؛
  - استيراد ثانٍ لنفس المستخدم يعطي 429، والثاني داخل الفجوة (بـ `DB::beforeExecuting` على جملة الإدراج) يعطي 429 أيضاً ويبقى صفّ واحد؛
  - ملف أكبر فعلياً من الحدّ مع حجم مُعلَن صغير يُرفض عند `complete`؛
  - صفحة الإدراج تُحذف أثناء التحويل فتُلحق الصفحات في الآخر؛
  - حذف السبّورة أثناء التحويل يعطي `failed/board_deleted` بلا صفحات؛
  - استيراد من مساحة أخرى يعطي 404؛
  - المهمة على `redis-long` بمهلتها، وتُرسَل بعد الالتزام.
- [ ] T087 [US4] أضف إلى `BoardImportTest`: PPTX وDOCX وصورة PNG تصير صفحات بالترتيب عبر `FakeDocumentConverter` (US4-2)
- [ ] T088 [P] [US4] اكتب `frontend/src/components/whiteboard/ImportDialog.tsx`:
  - فحص الحجم قبل الرفع؛
  - الرفع عبر `lib/media.ts` `uploadTo`؛
  - استعلام كل 3 ثوانٍ، مع موضع الانتظار؛
  - رسائل الفشل من `strings.ts`؛
  - إمكانية العمل على صفحات أخرى أثناء التحويل.
- [ ] T089 [P] [US4] اكتب `ImportDialog.test.tsx` و`strings.test.ts`: التقدّم وموضع الانتظار يظهران والحوار لا يحجب اللوحة (US4-3)، ولكل رمز فشل في contracts/api.md رسالة في `strings.ts` (US4-4)
- [ ] T090 [US4] ركّب زرّ «استيراد» في شريط الأدوات يفتح `ImportDialog`، ومعه اختبار يضغطه
- [ ] T091 [US4] أضف إلى `frontend/e2e/whiteboard.spec.ts` › «الاستيراد» ملف PDF صغيراً من 3 صفحات في `frontend/e2e/fixtures/3-pages.pdf`، ويُنشأ المجلّد. يتحوّل إلى 3 صفحات مرتّبة. إن غاب المحوّل أو العامل **يفشل الاختبار بصوت عالٍ** ولا يتخطّى. حالة الخمسين صفحة في Pest (T086)

---

## الطور ٧: القصة ٥ (P2): ضغطة واحدة تضع السبّورة في مواد الدرس

**الاختبار المستقل:** «إرفاق بمواد الدرس» يُظهر PDF في مرفقات الدرس، وطالب مسجَّل يفتحه، والإرفاق الثاني يستبدل.

- [ ] T092 [US5] **قرار مكتبة PDF من المرحلة ٠:** pdf-lib أو jsPDF، بموافقة المالك. ثم اكتب `frontend/src/lib/whiteboard/pdf-export.ts`:
  - لكل صفحة `exportToBlob(PNG, exportingFrame, getDimensions×1.5)`؛
  - PDF بصفحات 16:9، يُحفظ بلا مجاري كائنات (`useObjectStreams:false` في pdf-lib) حتى يُعدّ عدد صفحاته في الاختبار؛
  - تقدّم مرئي، ويعمل خارج الخيط الرئيسي إن أمكن.
- [ ] T093 [US5] اكتب `RecordBoardExport`، ومعه `RecordExportRequest` و`BoardLessonExportController` بالدوالّ `store` (الإرفاق الأول) و`update` (`PUT /{export}` تحت `2fa.required`):
  - الملف `Ready`، ومالكه هذا الدرس، و`application/pdf`، ودوره `Attachment`، وغير مربوط بتصدير آخر، والمستدعي يجتاز نطاق `MediaAssetPolicy::create` على الدرس؛
  - `POST` وقد وُجد تصدير: 409 `already_exported`؛
  - `$new->is($old)`: عودة مبكّرة قبل التبديل؛
  - للاستبدال: `Gate::authorize('delete', $old)`، والقديم الذي صار null بعد `nullOnDelete` يُعامَل إرفاقاً أوّل؛
  - التبديل عبر Eloquent `->where('media_asset_id', $old)` (null يصير `IS NULL`)؛
  - `DeleteMediaAsset` بعد الالتزام إن تغيّر صفّ واحد؛
  - في السباق يُحذف الجديد غير المربوط.

  وأضف صفوف الطريقين إلى `BoardIsolationTest`.
- [ ] T094 [US5] اكتب `backend/tests/Feature/Whiteboard/BoardExportTest.php`:
  - الإرفاق الأول بلا 2FA؛ و`POST` ثانٍ يعطي 409 `already_exported`؛
  - الاستبدال من مساعد بلا `LESSONS_DELETE` يعطي 403 `replace_forbidden`، ويبقى المرفق القديم؛
  - الاستبدال من مدرّس بلا تحقّق ثنائي مُفعَّل يُرفض؛
  - الاستبدال من المدرّس مع 2FA: مرفق واحد هو الأحدث؛
  - **السباق:** بـ `DB::beforeExecuting` على جملة التبديل يُكتب تبديل منافس داخل الفجوة، فيُحذف الجديد غير المربوط ولا يُحذف المربوط؛
  - الملف نفسه مرّتين لا يحذفه؛
  - ملف بدور غير `Attachment`، أو مربوط بتصدير آخر: 422؛
  - حذف الدرس بعد التصدير لا يعطي 500 (`nullOnDelete`)، والتصدير بعده يُعامَل إرفاقاً أوّل؛
  - ملف لدرس آخر يعطي 422 `asset_mismatch`؛
  - درس من مساحة أخرى يعطي 404؛
  - طالب مسجَّل يفتح المرفق عبر `IssuePlaybackGrant`.
- [ ] T095 [US5] اكتب `frontend/src/components/whiteboard/ExportToLessonButton.tsx`:
  - يختار الدرس إن لم تُربط السبّورة؛
  - يحوّل الـ Blob إلى `File`، ثم يرفع عبر `lib/media.ts` `requestUpload(lessonUuid, {original_filename, size_bytes, kind:'document', role:'attachment'})` ثم `uploadTo` ثم `complete`، ثم `POST` أو `PUT` على `lesson-exports`؛
  - يقرأ `exports[].can_replace` ويقول «اطلب من مدرّس الكورس» **قبل** الرفع.

  وفي جهة الطالب `frontend/src/components/player/AttachmentList.tsx`: إعادة الجلب عند 403 أو 404.
- [ ] T096 [US5] اكتب `frontend/src/components/whiteboard/LessonBoardsSection.tsx` في مكوّن مستقل، لأن `LessonEditor` فيه 548 سطراً:
  - يقرأ `GET /boards?lesson=`؛
  - فيه «سبّورة جديدة لهذا الدرس».

  واربطه من `frontend/src/components/courses/LessonEditor.tsx` بسطر واحد. **هذا رابط الوصول الثاني.**
- [ ] T097 [US5] ركّب `ExportToLessonButton` وتصدير PDF السبّورة في `ExportMenu`، ومعه اختبار يضغطهما
- [ ] T098 [P] [US5] اكتب `ExportToLessonButton.test.tsx`: منتقي الدرس يظهر للسبّورة غير المربوطة (US5-2)، وبعد الاستبدال تظهر رسالة «استُبدل المرفق» (US5-3)
- [ ] T099 [US5] أضف إلى `frontend/e2e/whiteboard.spec.ts` › «التصدير»:
  - تصدير PDF السبّورة: الملف يُنزَّل وعدد صفحاته = عدد صفحات السبّورة، والعربية فيه ظاهرة (لقطة صفحة)؛
  - الإرفاق بدرس يُظهر المرفق في `AttachmentsPanel`.

---

## الطور ٨: القصة ٦ (P2): جداول ومعادلات تُعدَّل بعد رسمها

- [ ] T100 [P] [US6] اكتب `frontend/src/lib/whiteboard/table-paste.ts`:
  - `parseClipboardTable(html|tsv)` دالة خالصة بلا `innerHTML`، تستخدم `DOMParser` و`textContent`؛
  - ومستمع `paste` في مرحلة الالتقاط على الحاوية: إن وجد `<table>` أو TSV فإنه يستدعي `preventDefault()` و`stopImmediatePropagation()`.

  ومعه `table-paste.test.ts` بعيّنات حقيقية من Excel وSheets.
- [ ] T101 [P] [US6] اكتب `frontend/src/components/whiteboard/rich/TableEditor.tsx`:
  - شبكة من اليمين لليسار، إضافة وحذف صفوف وأعمدة، وتلوين خلايا؛
  - يُرسم SVG، ثم canvas، ثم PNG بمقياس 3×؛
  - النصّ عبر `textContent` لا `innerHTML`.
- [ ] T102 [US6] اكتب `frontend/src/lib/whiteboard/rich-object.ts`:
  - `insertRichObject(kind, customData, png)`: يرفع الملف عبر `RequestBoardFile` ثم يُدرج العنصر **بعد** `Ready`؛
  - `replaceRichObject(elementId, …)`: ملف جديد، والمعرّف نفسه، و`x` و`y` و`angle` و`width` وترتيب الطبقة كما هي.

  ومعه زرّ «تعديل» سياقي عند تحديد عنصر جدول أو معادلة (`renderTopRightUI` أو طبقة فوقية).
- [ ] T103 [US6] اكتب `frontend/src/components/whiteboard/rich/MathEditor.tsx`:
  - داخل `next/dynamic` فقط، بعد موافقة المالك على mathlive وmathjax؛
  - MathLive للكتابة، و`tex2svgPromise` مع mhchem وامتداد `safe`؛
  - الخطوط مستضافة ذاتياً عبر `loader.paths`، لأن CSP يمنع أيّ CDN؛
  - الناتج PNG.
- [ ] T104 [US6] اختبر `frontend/src/lib/whiteboard/rich-object.test.ts`: الاستبدال يحفظ المعرّف والموضع والترتيب. ومعه `TableEditor.test.tsx`: الخلية الأولى على اليمين (US6-1)، و`table-paste.test.ts`: مستمع الالتقاط يمنع لصق الصورة حين تحمل الحافظة جدولاً وصورة معاً (US6-2)
- [ ] T105 [US6] اكتب `frontend/scripts/check-whiteboard-bundle.mjs`: يقرأ `.next/app-build-manifest.json` لطريق السبّورة ويبحث في قطعه عن علامات `mathlive` و`MathJax` و`three` (`REVISION`)، ويفشل إن وجد أيّاً منها (SC-009). وأضف خطوته بعد `npm run build` في `.github/workflows/ci.yml`. (`next build` **ليس** أثناء `next dev`.)
- [ ] T106 [US6] ركّب زرّي «إدراج جدول» و«إدراج معادلة» في شريط الأدوات، ومعه اختبار يضغطهما فيفتح المحرّر

---

## الطور ٩: القصة ٧ (P2): زرّ «افتح السبّورة» في الحصة ⚠️ يحتاج موافقة المالك

**لا تبدأ قبل موافقة صريحة على لمس كود الحصة المباشرة (القيد ٥، FR-022، FR-024).**

- [ ] T107 [US7] اطلب الموافقة، مع عرض اقتراح FR-024 من ADR-001: `contentHint:'text'` + 1080p@15 + `maxFramerate 10` + vp8، **لا** `preferCurrentTab`. خطوتان منفصلتان، وكل واحدة بموافقة
- [ ] T108 [US7] أضف `class_session` إلى `StoreBoardRequest` و`UpdateBoardRequest` و`BoardData` (`WorkspaceRules::exists('class_sessions','uuid')`)، والمرشّح `session=` إلى `ListBoards`، ومعه اختبار في `BoardCrudTest`: الإنشاء بحصّة، والمرشّح يعيد سبّورات الحصّة التي يستطيع المستدعي `view` وحدها، وحصّة من مساحة أخرى تُرفض
- [ ] T109 [US7] أضف إلى `frontend/src/app/(app)/(shell)/sessions/[uuid]/room/page.tsx` زرّ «افتح السبّورة»:
  - للمضيف فقط (`ticket.role === "host"`)؛
  - يقرأ `GET /boards?session=` (سبّورات يستطيع `view`)، أو يقدّم الاختيار أو إنشاء سبّورة؛
  - يفتحها بـ `window.open(..., 'whiteboard', 'popup,width=1600,height=900')`؛
  - معه سطر التلميح من `strings.ts`.
- [ ] T110 [P] [US7] اكتب اختبار vitest لمكوّن الزرّ: بلا سبّورة مرتبطة يُعرض الاختيار والإنشاء، والإنشاء يرسل `class_session` (US7-3)
- [ ] T111 [US7] (بموافقة منفصلة فقط) مرّر خيارات الالتقاط والنشر في `frontend/src/components/sessions/BroadcastStage.tsx:360`، ومعها اختبار في `BroadcastStage.test.tsx` يؤكّد الخيارات الممرّرة
- [ ] T112 [US7] أضف إلى `frontend/e2e/sessions.spec.ts` على حصّة مزروعة داخل نافذتها:
  - المضيف يرى الزرّ (تأكيد إيجابي على أنه المضيف أوّلاً)؛
  - الطالب لا يراه.

---

## الطور ١٠: القصة ٨ (P3): الليزر والكشّاف والمؤقّت

- [ ] T113 [P] [US8] اكتب `frontend/src/components/whiteboard/overlays/OverlayLayer.tsx`:
  - طبقة واحدة فوق اللوحة مربوطة بـ `sceneCoordsToViewportCoords` و`onScrollChange`؛
  - لا تكتب في المشهد أبداً، وهي أساس القصص ٩ و١٠ و١٢.
- [ ] T114 [P] [US8] اكتب `overlays/Spotlight.tsx` و`overlays/Timer.tsx`. والليزر مدمج (`setActiveTool({type:'laser'})`) وله زرّ واختصار
- [ ] T115 [US8] اختبر `overlays/OverlayLayer.test.tsx` بواجهة Excalidraw وهمية: تشغيل كل أداة ثم إغلاقها يستدعي `updateScene` و`addFiles` و`history` **صفر مرّة** (SC-010). أمّا «لا أثر في التصدير» ففي Playwright
- [ ] T116 [P] [US8] اكتب `Spotlight.test.tsx` و`Timer.test.tsx`: الكشّاف يظهر عند تشغيله، والمؤقّت يعدّ ويُغلق
- [ ] T117 [US8] ركّب أزرار الليزر والكشّاف والمؤقّت في شريط الأدوات و`OverlayLayer` فوق اللوحة، ومعه اختبار يضغطها

---

## الطور ١١: القصة ٩ (P2): أدوات التدريس

- [ ] T118 [P] [US9] اكتب `overlays/Ruler.tsx` و`Protractor.tsx` و`Compass.tsx` و`SetSquare.tsx`:
  - تُنتج عناصر `line` و`arrow` وأقواساً كنقاط `line` عبر `updateScene(IMMEDIATELY)`؛
  - الأداة نفسها لا تُحفظ.
- [ ] T119 [P] [US9] اكتب `frontend/src/lib/whiteboard/templates.ts`، ستة قوالب:
  - مسطّرة، ومربّعات، ومنقّطة، ومتساوية القياس، ورسم بياني، وسطور كرّاسة عربية؛
  - كلها SVG تُحوَّل PNG مرة واحدة بمعرّف ثابت `template:<name>:v1`؛
  - عنصر مقفل أسفل الصفحة.
- [ ] T120 [US9] أضف إعدادات الأقلام الجاهزة (ماركر وفرشاة، وما يقرّره المالك في الطباشير والبخّاخ) كلها فوق الحدّ الأدنى للبثّ، ثم `overlays/Magnifier.tsx` و`Curtain.tsx` و`Wheel.tsx`
- [ ] T121 [US9] اختبر `templates.test.ts` و`overlays/geometry.test.ts`:
  - خطّ المسطرة يُحفظ ويُعاد فتحه في مكانه (SC-011)؛
  - القالب عنصر مقفل بمعرّف ثابت. وظهوره في التصدير يُفحص في Playwright.
- [ ] T122 [P] [US9] اكتب اختبار vitest: كل قلم جاهز فوق الحدّ الأدنى للسُمك، والمكبّر والستارة والعجلة تستدعي `updateScene` صفر مرّة (SC-010)
- [ ] T123 [US9] ركّب منتقي القوالب وأدوات الهندسة والأقلام والمكبّر والستارة والعجلة على الشاشة، ومعه اختبار يضغطها

---

## الطور ١٢: القصتان ١٠ و١٢ (P3): مؤثرات التشجيع ومؤثرات المؤشّر

- [x] T124 [P] [US10] اكتب `overlays/effects/Celebrate.tsx`: تصفيق وبالونات واحتفال ونجوم، بكود خاص على Canvas 2D (أو `canvas-confetti` إن وافق المالك)، مدّته ثوانٍ ثم يختفي
- [x] T125 _(تمّ: ثلاث تسجيلات ملكية عامة/CC0 من ويكيميديا كومنز بموافقة المالك، ومصدر كل واحد في `LICENSE.txt`؛ والفرقعة والشاكوش والنجوم مولَّدة. أُضيفت من أفكار المرجع: بالونات تُفرقَع باليد، «انتباه!» بالشاكوش، طبلة قبل الإعلان، وملصقات تشجيع تُحفظ في الصفحة)_ أضف ملفات صوت قصيرة برخصة CC0 إلى `frontend/public/whiteboard/sounds/` مع `LICENSE.txt` يذكر المصدر، وسطر التلميح «يُسمَع في البثّ فقط إذا شاركت صوت التبويب» (FR-035)
- [x] T126 [P] [US12] اكتب `overlays/effects/PointerTrail.tsx`: نيون وشرارات وذيل ملوّن، عبر `requestAnimationFrame` وcanvas واحد. ويُقاس أنه لا يزيد تأخّر القلم في جلسة 5 دقائق
- [x] T127 [US10] اختبر `overlays/effects/effects.test.tsx`: المؤثّر يزول ويستدعي `updateScene` و`addFiles` صفر مرّة (SC-010)
- [x] T128 [P] [US10] اختبر أن سطر التلميح يظهر بجانب كل مؤثّر له صوت (US10-2)
- [x] T129 [US10] ركّب قائمة المؤثّرات ومفتاح آثار المؤشّر في شريط الأدوات، ومعه اختبار يضغطها

---

## الطور ١٣: القصة ١١ (P3): محتوى جاهز وألعاب

- [ ] T130 [US11] أضف نصّ جزء عمّ من Tanzil Uthmani **حرفياً** إلى `frontend/src/lib/whiteboard/content/juz-amma.json`:
  - إشعار الحقوق كاملاً، ورابط tanzil.net في `CONTENT-LICENSES.md`؛
  - خطّ Amiri Quran (OFL) في `frontend/public/whiteboard/fonts/`.
- [ ] T131 [US11] اكتب `rich/QuranPicker.tsx` فوق `rich-object.ts` (T102):
  - اختيار السورة والآيات؛
  - `customData {kind:'quran', v:1, surah, from, to, edition}`؛
  - يُصيَّر PNG من الملف الحرفي، فلا تحرير للنصّ.

  ومعه اختبار يؤكّد أن نصّ الآية في الصورة يأتي من الملف بلا تعديل (مقارنة سلاسل قبل التصيير).
- [ ] T132 [US11] اكتب `frontend/src/components/whiteboard/activities/ActivityHost.tsx`:
  - إطار فوق الصفحة، وإغلاق بضغطة؛
  - كل نشاط عبر `next/dynamic`.

  وابدأ بلعبتين بلا اعتماد جديد: توصيل الحروف، والذاكرة.
- [ ] T133 [US11] (بموافقة المالك على `three`) اكتب مشهدين ثلاثيي الأبعاد `activities/SolarSystem.tsx` و`PeriodicTable.tsx` بـ `three` وحده، و`dispose()` كامل عند الإغلاق. صور الكواكب من مصادر NASA العامة فقط
- [ ] T134 [US11] اختبر: إغلاق النشاط يستدعي `updateScene` صفر مرّة (SC-010)؛ وأن `three` والألعاب خارج حزمة السبّورة الأولى يحرسه `check-whiteboard-bundle.mjs` (T105، SC-009)
- [ ] T135 [P] [US11] اختبر: إشعار المصدر يظهر مع كل إدراج قرآني (US11-1)، والألعاب تُرسم داخل إطار الصفحة لا خارجه (US11-2)
- [ ] T136 [US11] ركّب `QuranPicker` و`ActivityHost` في شريط الأدوات، ومعه اختبار يضغطهما

---

## الطور الأخير: التوثيق والفحوص الشاملة

- [ ] T137 [P] حدّث:
  - `docs/README.md`: الوحدة، والطرق، والصلاحيات المستخدمة؛
  - `docs/erd.md`: الجداول الأربعة.
- [ ] T138 [P] أنشئ `docs/gotchas/whiteboard.md` بأهمّ ما في review-findings.md وهذه المراجعة، وأضف عنوانه إلى قسم Gotchas في `CLAUDE.md`. أهمّ النقاط:
  - سجلّ التراجع واحد؛
  - `addFiles` لا يُفرَّغ؛
  - الطالب يرى التبويب الحيّ؛
  - `RunsAlone` يُسقط؛
  - `timestamp(3)` وساعة PHP المربوطة نصّاً؛
  - `sendBeacon` بلا Bearer؛
  - إعادة الترتيب لا تعدّ صفوفاً.
- [ ] T139 شغّل البوابات المحلية **مرة واحدة بعد التغيير كلّه**:
  - `./vendor/bin/pint --test`؛
  - `./vendor/bin/phpstan analyse`؛
  - `php vendor/bin/pest tests/Feature/Whiteboard` (عملية واحدة فقط)؛
  - `npx tsc --noEmit`؛
  - `$env:TZ='UTC'; npx vitest run src/lib/whiteboard src/components/whiteboard`.

  والحزمة الكاملة على GitHub.
- [ ] T140 شغّل `quickstart.md` § المرحلة ١ كاملاً على الجهاز.
- [ ] T141 قبل الإطلاق، **على الإنتاج قراءةً فقط**:
  - `SHOW VARIABLES WHERE Variable_name IN ('log_bin','binlog_row_image','binlog_expire_logs_seconds','max_allowed_packet')`، وسجّل النتيجة في ADR-001 (خطر binlog)؛
  - اطلب من المالك قياس الذاكرة الحقيقية لـ Gotenberg بعد أول استيراد.
- [ ] T142 راجع الـ PR بوكيل مستقلّ قبل الدمج، والدمج بموافقة المالك فقط.

---

## الاعتمادات والترتيب

```
المرحلة ٠ (T001–T013) ⛔ توقّف وموافقة
  └─ الطور ١ الإعداد (T014–T021)
       └─ الطور ٢ الأساس (T022–T039) ← يحجب كل القصص
            ├─ القصة ١ (T040–T051) ← MVP
            │    ├─ القصة ٢ (T052–T060) تحتاج لوحة القصة ١
            │    └─ القصة ٣ (T061–T076) تحتاج لوحة القصة ١؛ فيها ملفات السبّورة (T062)
            │          ├─ القصة ٤ (T077–T091) تحتاج الملفات والصفحات والمكنسة (T066)
            │          ├─ القصة ٥ (T092–T099) تحتاج الصفحات
            │          └─ القصة ٦ (T100–T106) تحتاج ملفات السبّورة (T062)
            ├─ القصة ٧ (T107–T112) ⚠️ موافقة منفصلة؛ تحتاج القصة ١ فقط
            └─ القصة ٨ (T113–T117) ← تمهّد للقصص ٩ و١٠ و١٢ (طبقة واحدة)
                 ├─ القصة ٩ (T118–T123): القوالب تحتاج ملفات السبّورة (T062) و`rich-object.ts` (T102، القصة ٦)
                 ├─ القصتان ١٠ و١٢ (T124–T129)
                 └─ القصة ١١ (T130–T136) ⚠️ موافقة على three؛ `QuranPicker` يحتاج T062 وT102 (القصة ٦)، وفحص الحزمة T105
الطور الأخير بعد ما يُسلَّم
```

**التوازي:**
- **الطور ١:** T015–T021 معاً.
- **الطور ٢:** بعد T022: T023–T025 وT029 وT030–T032 معاً، وفي الواجهة T036–T039 معاً بالتوازي مع الخلفية.
- **القصة ١:** T046 وT047 بالتوازي مع T045.
- **القصة ٢:** T055 وT057 وT059 بالتوازي مع T052–T054 (واجهة مع خلفية).
- **القصة ٣:** T070 وT071 بالتوازي مع الخلفية.
- **القصة ٤:** T079 وT080 وT088 وT089 معاً.
- **القصة ٦:** T100 وT101 معاً.
- **القصتان ١٠ و١٢:** T124–T126 معاً.

⚠️ لا يُشغَّل pest مرتين في الوقت نفسه، ولا `npm run build` أثناء `npm run dev`.

## استراتيجية التسليم

1. **المرحلة ٠ ثم توقّف.** لا Laravel ولا قاعدة بيانات قبل موافقة المالك على التقرير.
2. **الحدّ الأدنى (MVP) = الأساس + القصص ١ و٢ و٣:** سبّورة عربية بصفحات، لا يضيع منها شيء، تُشارَك من زرّ المشاركة القائم. بلا مفتاح تشغيل: تظهر للكل مع دمجها (قرار المالك 2026-10-02).
3. **ثم القصتان ٤ و٥:** الاستيراد والتصدير، وهما قيمة المدرّس الأكبر بعد الأساس. بنية الاستيراد (Gotenberg وpoppler والمشرف) تُنشر مع القصة ٤ وحدها.
4. **ثم القصتان ٦ و٨.**
5. **ثم القصة ٧** بعد موافقة لمس كود الحصة.
6. **ثم القصة ٩، ثم القصتان ١٠ و١٢، ثم القصة ١١** (ترتيب المواصفة).

كل خطوة PR مستقلّ، يراجعه وكيل مستقلّ، ويُدمج بموافقة المالك، والدمج ينشر تلقائياً.
