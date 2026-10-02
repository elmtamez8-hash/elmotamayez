# البحث: سبّورة المدرّس (039)

**التاريخ**: 2026-10-01 · **المنهج**: ثلاث جولات بحث متوازية:

- الكود في `D:\mteatch`، مقروءاً كاملاً؛
- حزمة `@excalidraw/excalidraw@0.18.1` نفسها (`dist/` و`dist/types`)، إضافةً إلى GitHub وContext7؛
- LiveKit من `node_modules/livekit-client@2.21.0`، والمتصفّحات من بيانات توافق المتصفّحات في MDN (browser-compat-data).

كل قرار أدناه يذكر مصدره، وما رُفض، وما **لم يُتحقَّق منه**.

> **تحذير القرص:** القرص C: على جهاز التطوير ممتلئ (0 بايت متاحة). وهذا منع قراءة جداول الخطوط. حرِّر بعض المساحة قبل المرحلة 0.

---

## R-01: أين تُخزَّن الملفات

- **القرار:** أصول وحدة Media (`MediaAsset`) يملكها `Board` (علاقة morph عبر `owner_type`)، يخزّنها `LocalMediaProvider` على `config('media.disk')`، وهو `local` في الإنتاج.
- **السبب:**
  - المستندات والصور تذهب إلى هناك أصلاً: `MediaProviderResolver::forKind()` يرسل `Document` إلى المزوّد المحلي، لأن `BunnyMediaProvider` يعلن `kinds: [Video]`.
  - تذكرة الرفع، وفحص البايتات السحرية، وسقف الحجم، و`ReconcileAssetStatus` كلّها مبنية ومختبَرة.
- **السابقة:** وحدة `Store` أعطت `StoreItem` أبوابها الخاصّة:
  - `StoreItemMediaController`
  - `RequestStoreFile` / `CompleteStoreFile`
  - `config('media.full_allowance_owners')`
- **المرفوض:**
  - DigitalOcean Spaces: غير موجود في هذه البنية التحتية.
  - R2: يُستخدَم فقط جسراً للتسجيلات، ببيانات اعتماد الإخراج (egress) في LiveKit.
- **فخّ:** `MediaAssetPolicy::assetWithinAssistantScope` يرفض أيّ مالك ليس Lesson ولا ClassSession (`:108-118`)، فملف السبّورة **يجب** أن تكون له أبوابه الخاصّة. الأبواب العامّة ترفضه، وهذا صحيح.

## R-02: محرّك اللوحة وطريقة تحميله

- **القرار:** `@excalidraw/excalidraw` **0.18.1**، مثبَّتة بالضبط (بلا `^`).
  - رخصة MIT.
  - الاعتماد النظير `react ^19.0.0` مدعوم.
  - ESM فقط. يُستورَد CSS من `@excalidraw/excalidraw/index.css`.
  - نحو 47 MB على npm، لكن المتصفّح لا يجلب إلا ما يستخدمه.
  - 0.18.1 تصحيح أمني (CVE-2025-54881 في mermaid-to-excalidraw).
- **التحميل في Next 15:** ثلاثة ملفات.
  1. مكوّن عميل داخلي يستورد Excalidraw وملف CSS الخاصّ بها.
  2. مكوّن عميل خارجي يستدعي `dynamic(() => import(inner), { ssr:false })`.
  3. `page.tsx` على الخادم يعرض المكوّن الخارجي.

  `ssr:false` **غير مسموح** داخل Server Component (https://nextjs.org/docs/app/guides/lazy-loading#skipping-ssr). مثال Excalidraw نفسه يضعه في `page.tsx` بلا `"use client"`، وهذا خطأ في App Router. والسابقة في المستودع هي `components/ui/RichMarkdownEditor.tsx:56-66`.
- **لم يُتحقَّق منه:** هل يحلّ Turbopack مسار `index.css`، الذي ليس في `exports` الخاصّ به شرط `default`. أمر `next dev` عندنا يعمل على webpack، وستتحقّق منه المرحلة 0.
- **تغييرات المصدر بعد 0.18:** فرع `master` يستبدل `scrollToContent` بـ `setViewport()` ويغيّر `setActiveTool`. لهذا ثُبِّت الإصدار، وتمرّ كل استدعاءات Excalidraw عبر ملف واحد (`lib/whiteboard/excalidraw-api.ts`)، فلا يمسّ الترقية إلا موضع واحد.

## R-03: الاستضافة الذاتية للخطوط

- **القرار:**
  - نسخ `node_modules/@excalidraw/excalidraw/dist/prod/fonts/` **مع مجلّد `fonts/` نفسه** إلى `frontend/public/excalidraw/fonts/`، بسكربت node يعمل على كل الأنظمة، مربوط بـ `predev` و`prebuild` (مراجعة الخطة: ب، المعمار). **لا** بخطوة ما بعد التثبيت: `docker/frontend.Dockerfile` يشغّل `npm ci` قبل `COPY . .`، فالسكربت لا يكون موجوداً وقتها.
  - ضبط `window.EXCALIDRAW_ASSET_PATH = "/excalidraw/"` **داخل المحمِّل الديناميكي، قبل** `import()`.

  الكود (`ExcalidrawFontFace.createUrls`) يبني `<ASSET_PATH>/fonts/<Family>/…woff2`. وعبارة README «انسخ المحتويات» مضلِّلة.
- **فخّ:** الكود يضيف **دائماً** احتياطاً من `https://esm.sh/@excalidraw/excalidraw@<ver>/dist/prod/` حين يفشل تحميل ملف. سياسة CSP في الإنتاج في `docker/nginx.prod.conf:465` ستحجبه. وهذا جيّد لأنه طرف ثالث، لكن يجب ألّا نضيف `esm.sh` إلى CSP.
- **الحجم:** خطّ Xiaolai (CJK) يشغل نحو 13 من الـ 14 MB، لكنه مقسَّم إلى 209 قطعة عبر `unicode-range`، ولا تُحمَّل أيّ قطعة منها لنصّ غير CJK. عدم نسخه خيار (يوفّر 13 MB على الخادم)، لكن حرفاً صينياً سيرجع حينها إلى الـ CDN الذي تحجبه CSP. القرار: ننسخه. القرص رخيص وتبقى CSP نظيفة.

## R-04: العربية داخل اللوحة (الخطر الأكبر)

- **حقائق من الكود:**
  - لا خطّ من الخطوط المضمَّنة (Excalifont، Nunito، Lilita، Comic Shanns، Liberation، Virgil، Cascadia) له `unicode-range` عربي.
  - سلسلة الاحتياط هي `Excalifont, Xiaolai, Segoe UI Emoji`.
  - `Fonts.register` **خاصّة**. لا واجهة عامّة لتسجيل خطّ. وطلب الدمج #11399 (خطوط عربية) لم يُدمَج.
  - الاتّجاه يأتي من أوّل حرف قويّ (`isRTL`): `canvas.dir="rtl"`، وفي SVG `direction="rtl"` مع `text-anchor="end"`.
  - التشكيل (وصل الحروف) مهمّة المتصفّح، فهو صحيح متى احتوى الخطّ على الحروف.
- **قرار يُختبَر في المرحلة 0** (مراجعة الخطة: ب، الواجهة):
  - نسخة **مستضافة ذاتياً** من Cairo بصيغة woff2 تحت `public/` (لا ملف `next/font`، لأنه يُجزِّئ الأسماء)، والخطّ هو نفسه الذي يستخدمه التطبيق (رخصة OFL)، فتطابق عربية السبّورة بقيّة المنصّة.
  - **قاعدتا `@font-face` باسم `"Segoe UI Emoji"`** (الأخير في سلسلة كل عائلة، فلا يملأ إلا ما يفتقده الخطّ الذي قبله):
    1. Cairo مع `unicode-range` عربي (`U+0600-06FF, U+0750-077F, U+08A0-08FF, U+FB50-FDFF, U+FE70-FEFF`) ومع `ascent-override`/`descent-override`/`line-gap-override`؛
    2. `src: local("Segoe UI Emoji"), local("SegoeUIEmoji")` **بلا نطاق**، وإلا اختفت الرموز التعبيرية على Windows خلف القاعدة الأولى.
  - قبل تركيب اللوحة: `await document.fonts.load('16px "Segoe UI Emoji"', 'ب')`. وعند التحميل: `restoreElements(…, { refreshDimensions: true })` لإعادة قياس النصوص بالخطّ الصحيح.
  - المحاذاة الافتراضية: `currentItemTextAlign: "right"`.
- **تصدير SVG:** `exportToSvg` يضمّن أجزاءً من العائلات **المسجَّلة** فقط، فلن تُضمَّن العربية.
  - القرار: `skipInliningFonts: true`، ثم نحقن `@font-face` العربي الخاصّ بنا بترميز base64 داخل `<defs><style>` بأنفسنا، وهو ملف woff2 كامل بحجم نحو 100–150 KB. السبب: CSP عندنا بلا `'wasm-unsafe-eval'`، فمُقسِّم الخطوط في Excalidraw يفشل، ومسار فشله يشير إلى esm.sh.
  - ونصلح `text-anchor` للنصّ RTL في معالجة لاحقة (**يُتحقَّق منه في المرحلة 0**).
  - **تصدير PNG/PDF** يرسم على canvas بسلسلة الخطوط نفسها، فهو صحيح متى حُمِّل `FontFace` (`document.fonts.load` قبل التصدير).
- **الاحتياط إن فشل الاختبار:** `setCustomTextMetricsProvider` (عامّة) تُصلح القياس فقط لا الرسم. وفكرة تعديل `FONT_FAMILY.Cairo = 10` وقت التشغيل مسجَّلة لكنها **هشّة** (تعتمد على تفاصيل داخلية). والملجأ الأخير تعديل أدنى لإضافة عائلة خطّ، وهو **يحتاج موافقة المالك** (القيد 1).
- **مشكلات RTL معروفة** (مفتوحة في المصدر):
  - #11988: النصّ الذي يبدأ برمز تعبيري يُعامَل كـ LTR.
  - #8645 / #8614: تظليل البحث.
  - العنصر كلّه يأخذ اتّجاهاً واحداً من أوّل حرف قويّ فيه. والنصّ المختلط الذي **يبدأ** بالإنجليزية يُحاذى يساراً، وهذا سلوك bidi الطبيعي، وتختبره قائمة الفحص اليدوي في المرحلة 0.

## R-05: `langCode="ar-SA"` والصفحة

- **حقيقة:** `setLanguage()` تكتب `document.documentElement.dir/lang` و**لا تعيدهما أبداً** (المشكلة #11963، والإصلاح #11996 ما زال مفتوحاً). التطبيق `lang="ar" dir="rtl"`، فالتغيير الوحيد أن تصير `lang` هي `ar-SA`.
- **القرار:** الغلاف الخاصّ بنا يلتقط `<html lang/dir>` في مُهيِّئ `useRef` **أثناء الرسم** (قبل أن يكتبهما Excalidraw)، ويعيدهما عند الإزالة. أسطر قليلة بلا تعديل على المكتبة.
- **الترجمة:** `ar-SA` مغطّاة بنسبة 94%. مشكلات واجهة RTL المفتوحة مسجَّلة كمخاطر:
  - #9710 / #11820 / #11978: منزلق الشفافية؛
  - #11069: نافذة الحوار؛
  - #3835: انعكاس التراجع/الإعادة.

## R-06: واجهات Excalidraw التي نستخدمها (0.18.1، من `dist/types`)

| الحاجة | الواجهة |
|---|---|
| الحصول على الواجهة | الخاصّية `excalidrawAPI(api)` (بلا `ref`) |
| تحميل صفحة | `updateScene({ elements, appState, captureUpdate: CaptureUpdateAction.NEVER })` (`NEVER` = خارج سجلّ التراجع) |
| كائن غنيّ (جدول، معادلة) | `updateScene({... captureUpdate: IMMEDIATELY})`، ثم `addFiles([{id, mimeType, dataURL, created}])`. **`dataURL` إلزامي**، فتُحفَظ بايتات الملف في الذاكرة كروابط بيانات. ويُخزَّن **بـ** `fileId` فقط (FR-028) |
| الملاءمة مع الصفحة | **نحسبها بأنفسنا**: `zoom = min(w/1920, h/1080)` ثم `updateScene({ appState: { zoom, scrollX, scrollY } })`. **لا** `scrollToContent`: يقرّب الزوم إلى 0.1 ويقصره على 1 |
| تتبّع التغييرات | `onChange(elements, appState, files)`، و`getSceneVersion` لمعرفة هل تغيّر شيء فعلاً |
| سجلّ التراجع | `api.history.clear()` بعد كل انتقال بين الصفحات (قرار: التراجع لا يعبر الصفحات) |
| اللصق | **مستمع `paste` خاصّ بنا في مرحلة الالتقاط على الحاوية**: يقرأ `text/html`/TSV بشكل متزامن، ويستدعي `preventDefault()` و`stopImmediatePropagation()` حين يجد جدولاً. السبب: Excel يضع صورة PNG في الحافظة أيضاً، وExcalidraw يستهلك `clipboardData.files[0]` **قبل** أن يستدعي `onPaste`، ونوع `ClipboardData` فيه لا يحمل HTML. يبقى `onPaste` احتياطاً |
| الطبقات العلوية (المسطرة، البقعة الضوئية، المؤثّرات) | `sceneCoordsToViewportCoords` / `viewportCoordsToSceneCoords` + `api.onScrollChange` + `onPointerUpdate` |
| مؤشّر الليزر | `setActiveTool({ type: "laser" })` (مدمج) |
| ضغط القلم | freedraw يخزّن قيمة `PointerEvent.pressure` الحقيقية في `pressures[]`. الفأرة تُبلغ دائماً `0.5`، فيُحاكى الضغط |
| التصدير | `exportToBlob({ elements, files, appState:{exportBackground, viewBackgroundColor}, exportingFrame, mimeType:"image/png", getDimensions })`، `exportToSvg({... exportingFrame, skipInliningFonts: true})`. المقياس عبر `getDimensions`، لأن `exportScale` يُتجاهَل بلا `maxWidthOrHeight` |
| إطار الصفحة | `appState.frameRendering = { enabled: true, name: false, outline: false, clip: true }` |
| القفل | `element.locked = true` (لخلفية المستند والقالب) |
| بيانات مخصّصة | `element.customData` |
| معرّفات ثابتة | `convertToExcalidrawElements(skeletons, { regenerateIds: false })` |
| تنظيف البيانات المحمَّلة | `restoreElements(elements, null, { repairBindings: true, refreshDimensions: true })` |
| الواجهة | `UIOptions.canvasActions`، `zenModeEnabled`، `viewModeEnabled`، `gridModeEnabled`، `renderTopRightUI`، `MainMenu`، `Footer`، `Sidebar` |

**التوثيق:**
- https://docs.excalidraw.com/docs/@excalidraw/excalidraw/api/props/excalidraw-api
- https://github.com/excalidraw/excalidraw/blob/master/packages/excalidraw/CHANGELOG.md (تغييرات 0.18.0 الكاسرة: ESM، و`commitToHistory→captureUpdate`، وحذف `baseline`، وحذف مجلّدات `excalidraw-assets`)

## R-07: نموذج الصفحة (16:9) داخل لوحة لا نهائية

- **القرار:** كل صفحة في السبّورة مشهد **مستقلّ** (FR-028). تحمل عنصر `frame` واحداً بأبعاد 1920×1080 عند (0,0)، بمعرّف **خاصّ بالصفحة** `frame:{pageUuid}`. المعرّف المشترك بين الصفحات (`page-frame`) مرفوض: يتسرّب عبر سجلّ التراجع من صفحة إلى أخرى.
  - **عند كل انتقال بين الصفحات** (مراجعة الخطة: ب، الواجهة):
    1. إنهاء تحرير النصّ أوّلاً: `setActiveTool({ type: "selection" })`؛
    2. ربط كتابة المسودّة بمعرّف الصفحة **الملتقَط لحظة التغيير**، لا بالصفحة الحالية؛
    3. `updateScene(NEVER)`، ثم الملاءمة المحسوبة (R-06)؛
    4. `api.history.clear()`: **التراجع لا ينجو من مغادرة الصفحة** (قرار).
  - التصدير يستخدم `exportingFrame: frame`، فما يُرسَم خارج الإطار لا يصل أبداً إلى PDF.
  - **الطلاب يرون التبويب الحيّ** كما هو، فطبقة قناع (`pointer-events: none`، بلون الخلفية) تُخفي كل ما خارج الإطار، مع `frameRendering.clip: true`. وفي وضع العرض يملأ الإطار النافذة (وضع zen + الملاءمة).
- **المرفوض:** كل الصفحات كإطارات في مشهد واحد. سيصير الانتقال تمريراً، ويختلط سجلّ التراجع بين الصفحات، ويصير مستند التعاون لاحقاً ملفاً ضخماً واحداً.
- **سرعة الانتقال بين الصفحات** (SC-004)، ومعنى «التحميل المسبق» في FR-004:
  - تُبقى مشاهد كل الصفحات في الذاكرة بمجرّد فتح السبّورة؛
  - تُجلَب بايتات كل الملفات عند الفتح وتُحفَظ كـ `Blob`؛
  - لكن `addFiles` للصفحة الحالية ±2 فقط، و`dataURL` يُصنَع عند الحاجة؛
  - بعد زيارة نحو 40 صفحة تُعاد تهيئة اللوحة في لحظة خمول، لأن ملفات `addFiles` لا تُحرَّر.
- **الصور المصغّرة:** تُرسَم في المتصفّح فقط، بـ `exportToCanvas({ maxWidthOrHeight: 320 })` داخل `requestIdleCallback` وللعناصر الظاهرة في الشريط فقط، وتُخزَّن مؤقّتاً بحسب إصدار الصفحة. (اختياري: الصفحات المستوردة قد تأخذ صورة مصغّرة 320 px من تشغيل `pdftoppm` نفسه.)

## R-08: الحفظ المحلّي وعلى الخادم

- **محلّياً:** **IndexedDB خام** (بلا مكتبة `idb`، القيد 6). قاعدة واحدة `whiteboard`، ومخزن واحد `drafts`، والمفتاح `board:{b}:page:{p}:user:{u}` (مراجعة الخطة: ب، التزامن)، والقيمة `{ scene, dirty, ackedVersion, rev }`.
  - تُكتَب المسودّة **فقط** أثناء حمل القفل، و**فقط** حين يتغيّر `getSceneVersion`، وللصفحة المتّسخة وحدها، بعد انتهاء الضربة داخل `requestIdleCallback` (بمهلة ≤ ثانيتين).
  - `rev` عدّاد محلّي. **لا مقارنة بين ساعة الجهاز وساعة الخادم أبداً.**
  - حذف صفحة يلغي مؤقّتها ومسودّتها.
  - حين يتعذّر التخزين (التصفّح الخاصّ، الحصّة)، يقول المؤشّر ذلك وتستمرّ السبّورة في العمل (حالة طرفية في المواصفة).
- **على الخادم:** `PUT /boards/{b}/pages/{p}/scene` بعد **1.5 ثانية** من توقّف الرسم، حاملاً `version` و`tab` و`client_rev`.
  - طلب واحد قيد الطيران لكل صفحة، وآخر مشهد ينتظر في الطابور.
  - طلب طُبِّق فعلاً يُجاب 200 (إعادة محاولة متساوية الأثر). مفتاح الأثر الواحد هو `(tab, client_rev)` معاً، ويُعدّ مطبَّقاً فقط إن طابق الاثنان **و** كان الإصدار المرسَل = الإصدار المخزَّن − 1؛ فالعمودان `client_tab` و`client_rev` على `board_pages`.
  - **409 `version_conflict`:** يتوقّف الحفظ لتلك الصفحة، ويُعرَض «احفظ نسختي كصفحة جديدة» أو «خذ نسخة الخادم». **لا رفع تلقائي للإصدار أبداً** (FR-012).
  - عنصر الملف لا يُدرَج إلا بعد أن يصير ملفه `Ready`، والرفع الفاشل يُزيله برسالة، فلا يحجب 422 `unknown_file` صفحةً.
- **الاستعادة:** عند الفتح، تُقارَن المسودّة المحلية بالخادم:
  - `dirty` و`ackedVersion` `==` إصدار الخادم: يُعرَض استعادتها (الافتراضي)؛
  - `dirty` و`ackedVersion` `<` إصدار الخادم: يُسأل المستخدم (حالة طرفية). لا يُقرَّر بصمت أبداً.
- **خمس ثوانٍ كحدّ أقصى** (SC-005): **الضمان الحقيقي هو الكتابة المحلّية خلال ثانيتين.** التفريغ عند `pagehide` بطلب `keepalive` محاولة فقط، لأن `keepalive` محدود بـ 64 KB.

## R-09: قفل التحرير (FR-026، القرار Q3)

- **القرار:** أعمدة على السبّورة: `editor_user_id`، و`editor_tab_id` (uuid يُولَّد لكل تبويب)، و`editor_seen_at` (`timestamp(3)`)، و`editor_handover_tab`، و`editor_handover_at` (`timestamp(3)` أيضاً).
  - `POST /boards/{b}/lock` يكتسب القفل، أو يجدّده إن كان التبويب نفسه يحمله.
  - النبضة هي الطلب نفسه **كل 5 ثوانٍ** (تحديث شرطي واحد، رخيص)، حتى تصل مهلة الـ10 ثوانٍ عند «خُذ التحرير» للتبويب القديم فعلاً. النبضة لا تحتاج رسماً: المدرّس الذي يعرض بلا رسم يبقى حاملاً للقفل.
  - الانتهاء: `editor_seen_at < now - 120s` (دقيقتان بلا نبضة = التبويب مغلق أو منقطع).
  - `POST /boards/{b}/lock/take` **لصاحب السبّورة** وحده (D1).
  - كل Action يمسّ المحتوى يمرّ بصنف الدعم `BoardLock`، وكل حفظ للمشهد يفحص القفل في الجملة نفسها، فالتبويب الذي فقد القفل لا يستطيع الكتابة.
- **الذرّية** (مراجعة: ق-٩): جمل `UPDATE` مشروطة بالضبط. لا قراءة ثم كتابة أبداً (`live-sessions.md`: مطالبة ذرّية، لا `lockForUpdate`).
- **الساعة: ساعة PHP واحدة، لا ساعة قاعدة البيانات** (مراجعة المهام 2026-10-02):
  - كل استدعاء يأخذ `$now = CarbonImmutable::now()` **مرّة واحدة**، ويربط **نصوصاً** منسَّقة منه: `$now->format('Y-m-d H:i:s.v')`، و`$now->subSeconds(10)->format('Y-m-d H:i:s.v')`، و`$now->subSeconds(120)->format('Y-m-d H:i:s.v')`.
  - **لماذا نصوص لا كائنات تاريخ:** Laravel ينسّق أيّ `DateTimeInterface` مربوط بصيغة القواعد `Y-m-d H:i:s` (`Connection.php:779-780`)، فتسقط الملّي ثانية ويضيع معنى `timestamp(3)`.
  - **لا حساب تواريخ داخل SQL:** `INTERVAL 10 SECOND` و`NOW()` خطأ نحوي على SQLite، والفحص على GitHub يعمل على SQLite وحدها (لا خدمة MySQL في `.github/workflows/ci.yml`)، فصيغة MySQL وحدها تكسر كل اختبار، وصيغة SQLite وحدها تكسر الإنتاج. وساعة PHP تجعل `travel()` يعمل في الاختبارات.
  - **وسائط موضعية `?`** (أو اسم مختلف لكل ظهور). **لا اسم مكرّر أبداً:** `PDO::ATTR_EMULATE_PREPARES => false` (`Connector.php:24`)، فيرفض pdo_mysql الاسم المكرّر (HY093) بينما تقبله SQLite، فيمرّ الاختبار وينكسر الإنتاج.
  - كل كتابة في أعمدة `editor_*` تمرّ بمنسِّق واحد في `BoardLock`، والمصانع تكتب الصيغة نفسها.
  - **افتراض مكتوب:** خادم تطبيق واحد. ساعتان مختلفتان على خادمين ستُحسَب بهما المهل بشكل متفاوت؛ إن صار الخادم اثنين يُعاد النظر.
- **الاكتساب/التجديد** — محمولها كاملاً (`N` = `$now`، و`N10` = `$now − 10s`، و`N120` = `$now − 120s`، كلها نصوص بالملّي ثانية):
  `(editor_user_id=u AND editor_tab_id=tab AND (editor_handover_at IS NULL OR editor_handover_at > N10)) OR (editor_handover_tab=tab AND editor_handover_at <= N10) OR (editor_handover_tab IS NULL AND (editor_tab_id IS NULL OR editor_seen_at < N120))`.
  يُنفَّذ فرعه الأوّل (التجديد) جملةً مستقلّة تلمس `editor_seen_at` وحده عبر `DB::table` (فلا يتغيّر `updated_at` كل خمس ثوانٍ، ولا يُمحى تسليم معلّق)، ثم الفرعان الآخران (انتقال القفل) جملةً ثانية إن لم ينجح التجديد:
  ```sql
  -- renew: bindings [N, id, u, tab, N10]
  UPDATE boards SET editor_seen_at = ?
  WHERE id = ? AND editor_user_id = ? AND editor_tab_id = ?
    AND (editor_handover_at IS NULL OR editor_handover_at > ?)
  -- acquire (the lock moves, so handover_* is cleared): bindings [u, tab, N, id, tab, N10, N120]
  UPDATE boards SET editor_user_id = ?, editor_tab_id = ?, editor_seen_at = ?,
                    editor_handover_tab = NULL, editor_handover_at = NULL
  WHERE id = ? AND (
    (editor_handover_tab = ? AND editor_handover_at <= ?)
    OR (editor_handover_tab IS NULL AND (editor_tab_id IS NULL OR editor_seen_at < ?)))
  ```
  - **نجاح التجديد له نصفان:** صفّ واحد متأثّر، **أو** صفر صفوف ثم قراءة تُظهر أن `(editor_user_id, editor_tab_id)` هما أنا وأن لا تسليم منقضياً (`editor_handover_at IS NULL OR editor_handover_at > N10`). السبب: MySQL بلا `MYSQL_ATTR_FOUND_ROWS` يعيد صفراً لتحديث لا يغيّر القيمة، والملّي ثانية تجعل ذلك نادراً لا مستحيلاً. ولا نعتمد على عدد الصفوف وحده في التجديد أبداً.
  - الاكتساب (الجملة الثانية) ينقل القفل فيغيّر القيمة دائماً، فنجاحه = صفّ واحد متأثّر.
- **الأخذ** (صاحب السبّورة وحده):
  ```sql
  -- take: bindings [tab, N, id, tab, N120]
  UPDATE boards SET editor_handover_tab = ?, editor_handover_at = ?
  WHERE id = ? AND editor_tab_id IS NOT NULL AND editor_tab_id <> ?
    AND editor_seen_at >= ? AND editor_handover_at IS NULL
  ```
  صفر صفوف ثم قراءة: إن كان هناك تسليم معلّق لي يُعاد `handover_at` نفسه (تكرار الأخذ)، وإن لم يكن حامل حيّ فالأخذ لا يلزم والاكتساب العادي يكفي.
- **التحرير:** `WHERE id = ? AND editor_user_id = ? AND editor_tab_id = ?`. إن كان هناك تسليم معلّق ينتقل القفل مباشرةً إلى `editor_handover_tab`، وتُكتَب `editor_user_id` = صاحب السبّورة المشتقّ (D1)، لأن من يطلب التسليم هو صاحب السبّورة دائماً. وعند إغلاق التبويب: `fetch(url, {method:'DELETE', keepalive:true})` عبر `lib/api.ts`؛ **لا** `sendBeacon`، لأنه لا يرسل `Authorization: Bearer`.
- **حفظ المشهد = جملة واحدة:**
  ```sql
  -- bindings [scene, bytes, tab, rev, page_id, version, board_id, u, tab, N10]
  UPDATE board_pages SET scene = ?, scene_bytes = ?, version = version + 1, client_tab = ?, client_rev = ?
  WHERE id = ? AND version = ? AND EXISTS (
    SELECT 1 FROM boards WHERE id = ? AND editor_user_id = ? AND editor_tab_id = ?
      AND (editor_handover_at IS NULL OR editor_handover_at > ?))
  ```
  الإصدار يتغيّر دائماً، فنجاح الحفظ = صفّ واحد متأثّر. صفر صفوف ثم قراءة تميّز `lock_lost` من `version_conflict` ومن إعادة محاولة طُبِّقت (`client_tab` و`client_rev` مطابقان **و** الإصدار المرسَل = المخزَّن − 1).
- **الاختبارات:** اختبار السباق يجب أن يُدخل الكتابة المنافِسة **داخل الفجوة** بين الفحص والكتابة (`testing.md`: الاختبار المتتابع لمطالبة ذرّية أخضر حتى بلا مطالبة). الأداة: `DB::beforeExecuting` بجزء من نصّ الجملة ومعه حارس مرّة واحدة، ثم تأكيد أن الكتابة المنافسة جرت فعلاً (سابقة `tests/Feature/Assessments/AdaptiveClaimTest.php:24-37`). **لا** خطّاف `retrieved` على النموذج: لا يُطلَق على `DB::table`.
- **أخذ القفل** (تحسينٌ على Q3 وافق عليه المالك في 2026-10-01): عند «خُذ التحرير» يُمهَل المحرّر الحالي حتى ١٠ ثوانٍ ليحفظ على الخادم ثم يُحرِّر القفل؛ إن لم يستجب (تبويب مغلق أو بلا شبكة) ينتقل القفل، ويبقى ما لم يُحفَظ في مسودّته المحلية (IndexedDB) ويُعرَض عليه عند عودته.
  - الآلية: `take` يسجّل تسليماً معلّقاً (`editor_handover_tab`، `editor_handover_at`). التبويب القديم يراه في نبضته (≤ 5 ثوانٍ) أو في حفظه التالي، فيحفظ ويحرّر القفل. ومن يجد أنه فقد القفل يتلقّى 409 `lock_lost`. مسجَّل في `contracts/api.md`.

## R-10: تحويل المستندات على الخادم (القرار Q1)

- **Office→PDF:** **Gotenberg 8** (`gotenberg/gotenberg:8`، MIT، نحو 700 MB مضغوطاً، v8.37.0، 2026-09-11) كحاوية جديدة على شبكة Docker الداخلية، **بلا منفذ منشور**.
  - المسار: `POST /forms/libreoffice/convert`.
  - LibreOffice لا يعمل بالتوازي داخل نسخة واحدة، وهذا يوافق قرار «واحد في كل مرّة».
  - العربية: الصورة تحمل `fonts-noto-core` منذ 8.30. ويمكن إضافة Amiri لاحقاً عند الحاجة.
  - الإعدادات: `--api-timeout=120s`، `--libreoffice-restart-after=10`، `--libreoffice-max-queue-size=1`، مع حدّ ذاكرة في Docker قدره **1.5 GB**. هذا الحدّ تقديرنا؛ Gotenberg لا ينشر رقماً. وحدّ ذاكرة لحاوية `horizon` أيضاً.
  - **العزل** (مراجعة الخطة: أ-H3): شبكة Docker خاصّة `internal: true` لا يشاركها إلا `horizon`. بلا خروج إلى الإنترنت، فلا يستطيع LibreOffice جلب صورة مربوطة أو حقل خارجي (SSRF).
  - يُرسَل الملف باسم ثابت مشتقّ من النوع المكتشَف (`source.docx`)، **لا** باسم الملف الذي أرسله المتصفّح.
  - مسارات Chromium غير مستخدمة فتُعطَّل. أسماء أعلام التعطيل الدقيقة **تُتحقَّق في المرحلة 0**.
  - **أخطاء Gotenberg العابرة** (429، 503، انقطاع الاتصال) يُعاد معها المحاولة **داخل عميل HTTP** (`Http::retry`)، ولا تُسجَّل `corrupt`. **لا** `release` بتأخير: المهمّة `tries=1`، والإطلاق من جديد يبدأ تحويلاً ثانياً (مراجعة المهام 2026-10-02).
- **PDF→صور:** **poppler-utils** داخل صورة الخلفية (`apk add poppler-utils`، Alpine v3.22، 25.04؛ رخصة GPL كأدوات سطر أوامر غير معدَّلة، وهو ما يسمح به القيد 6)، عبر Symfony Process **بمصفوفة وسائط** (لا سلسلة صدفة) ولكل أمر مهلة:
  - `pdfinfo` لعدد الصفحات، يُفحَص مبكّراً لـ PDF (الحدّ 100، Q4)؛
  - `pdftoppm -f 1 -l {max} -scale-to 1920 -jpeg` لصورة واحدة لكل صفحة، فلا يُصيَّر أكثر من الحدّ أبداً؛
  - **يُعاد فحص العدد بعد التصيير** (قد يكذب `pdfinfo`). ولملفات Office لا يُعرَف العدد إلا بعد Gotenberg، فالعرض الطويل ينتهي `failed/too_many_pages` داخل المهمّة.
  - طبقة النصّ (`pdftotext`، FR-015) **مؤجَّلة**: لا قارئ لها في هذا النطاق (`ponytail:`).
- **سلسلة المهل:** كل Process < عميل HTTP < `--api-timeout` في Gotenberg < مهلة المهمّة.
- **الصور الوسيطة** في مجلّد مؤقّت باسم uuid الاستيراد، و`MediaAsset` لا يُنشأ إلا في المعاملة الأخيرة. التنظيف في `finally`.
- **المكنسة** `SweepBoardImportsJob` في جدول الصيانة القائم: `Schedule::job(new SweepBoardImportsJob, 'maintenance')->everyFiveMinutes()`، والمهمّة تستعمل `RunsAlone` (يطلبه `ScheduledSweepsRunAloneTest`) و`lazyById`:
  - `converting` أقدم من **مهلة المهمّة + هامش** (900 + 300 ثانية، لا 900) يصير `failed/timeout`، وتُنظَّف ملفاته المؤقتة؛
  - `uploading` العالق (الرفع لم يكتمل) يصير `failed`؛
  - `queued` العالق يُعاد إرساله، مع `dispatched_at` وعدّاد إعادة إرسال وسقف؛ بعد السقف `failed/timeout`؛
  - السبّورة العالقة على `deleting` تُعاد مهمّة حذفها.
- **المعاملة الأخيرة:**
  1. تبدأ ببوّابة الصفّ والسقف معاً: `UPDATE boards SET pages_count = pages_count + ? WHERE id = ? AND pages_count + ? <= ? AND pending_operation IS NULL` (وسائط موضعية؛ السبّورة التي تُحذَف تُنهي الاستيراد `failed/board_deleted`)؛
  2. تُركَن المواضع عند `max(position)+1+i` ثم تُضغَط (الفهرس الفريد `(board_id, position)` في MySQL، والعمود unsigned يمنع الركن في السالب)؛
  3. نقطة الإدراج هي `insert_after_page_id` (`nullOnDelete`)؛
  4. الحالة `done` تُكتَب `WHERE status = 'converting'`، وصفر صفوف = تراجع المعاملة كلها.
  - إضافة الصفحات تأخذ بوّابة الصفّ نفسها. **إعادة الترتيب** (فرق صفر) لا تعدّ صفوفاً: `UPDATE boards SET id = id WHERE id = ?` قفلاً على الصفّ بلا فحص للعدد (سابقة `Community/Actions/PostMessage.php:225`)، لأن تحديثاً لا يغيّر شيئاً يعيد صفراً على MySQL. **الحذف** (فرق سالب) جملة مستقلّة: `pages_count = pages_count - ? WHERE id = ? AND pages_count >= ?`.
  - المهمّة تختم `workspace_id` صراحةً (أو تعمل داخل `WorkspaceContext::forWorkspace`)، و`uuid` صراحةً إن أُدرجت الصفوف دفعةً.
- **WebP:** `pdftoppm` لا يُخرج WebP، و**pdf-lib لا تستطيع تضمين WebP**. القرار: **JPEG** بجودة 85 لخلفيات الصفحات. صيغة واحدة تخدم اللوحة وتصدير PDF، بلا خطوة تحويل.
  - المرفوض: WebP عبر `imagewebp()` في GD (متاحة في الصورة). توفّر نحو 30% من الحجم، لكنها تفرض تحويلاً إلى PNG/JPEG في المتصفّح عند كل تصدير PDF.
- **المرفوض:**
  - `spatie/pdf-to-image`: يحتاج Ghostscript، ورخصته **AGPL-3**؛
  - مُصيِّرات PHP خالصة: لا يوجد أيّ منها.
- **واحد في كل مرّة** (Q1): مشرف Horizon مخصّص `supervisor-whiteboard` على الطابور `whiteboard` عبر الاتصال `redis-long` (`retry_after` 1900)، `maxProcesses=1`، `timeout` = 900، `tries=1`. يُضاف إلى `defaults` **و**`environments` **و**`waits` في `config/horizon.php`، ويغطّيه `QueueTimeoutInvariantTest`.
  - المهمّة تكتب في مُنشئها `onQueue('whiteboard')` حرفياً و`public int $timeout = 900;` (التعبير في `QueueTimeoutInvariantTest.php:94` يبحث عنهما نصّاً)، و`$this->connection = 'redis-long'`.
  - **المرفوض:** وسيط تمنع التداخل في المهمّة: يُسقط المهمّات المتزاحمة بدل أن يؤجّلها (`dontRelease`). المشرف ذو العملية الواحدة يكفي وحده.
  - **المرفوض:** رفع `stop_grace_period` لحاوية horizon إلى مهلة المهمّة. يبقى 90 ثانية، والمهمّة التي يقطعها النشر تلتقطها المكنسة و`failed()`.
- **مهامّ النسخ والحذف** على مشرف ثانٍ `supervisor-whiteboard-ops`: الاتصال `redis-long`، والطابور `whiteboard-ops`، و`timeout` = 300، و`maxProcesses` = 1، في `defaults` و`environments` (production وlocal) و`waits`. فلا ينتظر نسخ سبّورة خلف تحويل مستند من خمس عشرة دقيقة.
- **الإرسال بعد الالتزام:** `DB::afterCommit(fn () => ConvertBoardImportJob::dispatch(...))` (سابقة `Gamification/Actions/AwardPoints.php:138`)، لأن `after_commit` في إعداد الطابور `false`.
- **حدّ المستخدم:** استيراد واحد على الأكثر `uploading`/`queued`/`converting` لكل مستخدم. **لا** `INSERT … WHERE NOT EXISTS` (لا يحمي على MySQL حين لا يوجد صفّ يُتنازَع عليه): المعاملة تبدأ بقفل صفّ المستخدم `UPDATE users SET id = id WHERE id = ?` بلا فحص للعدد، ثم تفحص وتُدرج. ومعه محدِّد معدّل مسمّى `whiteboard-import`.
- **التحقّق من الحجم الحقيقي:** الصفّ يولد `uploading`، و`CompleteBoardImport` يطلب الملف المصدر `Ready` و`size_bytes` **الفعلي** ≤ `import_max_bytes` (لا الحجم الذي أعلنه المتصفّح)، ثم يصير `queued`.
- **موضع الانتظار:** يُعَدّ `withoutWorkspaceScope()` على الفهرس `(status, created_at)`، مرتّباً بـ `(created_at, id)`، لأن الطابور مشترك بين كل مساحات العمل.
- **لم يُتحقَّق منه:** استهلاك Gotenberg الحقيقي للذاكرة على ملف PPTX من 50 صفحة. تقيسه المرحلة 0.

## R-11: حدود الاستيراد (القرار Q4)

- مفاتيح `platform_settings` جديدة:
  - `whiteboard.import_max_bytes` = 26,214,400 (25 MB)؛
  - `whiteboard.import_max_pages` = 100.
- طريقة إضافة مفتاح:
  1. القيمة الافتراضية في `config/whiteboard.php`؛
  2. سطر في `PlatformSettings::KEYS` (نسيانه يُخفي المفتاح من اللوحة، وقد حدث ذلك مرّات عدّة)؛
  3. حقل في `ManagePlatformSettings`.
- يُفحَص الحجم في أربع نقاط: قبل الرفع في المتصفّح، وفي التذكرة (`size`، 422 `too_large`)، وفي `receiveUpload` (`MediaLimits::uploadCeilingFor`، الذي يقرأ `full_allowance_owners`؛ وهو 50 MB للمستند، وفحص الـ 25 MB الخاصّ بنا موجود في Action التذكرة)، ثم مرّة رابعة في `CompleteBoardImport` على `size_bytes` الفعلي للملف بعد وصوله، لأن الحجم في التذكرة هو ما أعلنه المتصفّح.
- **عدد الصفحات** يُفحَص داخل المهمّة (R-10)، فتجاوزه ينتهي `failed/too_many_pages` لا 422، لـ PDF وOffice على السواء.

## R-12: تصدير PDF وإرفاقه بدرس (القرار Q2)

- **أين يُبنى PDF:** في المتصفّح. لكل صفحة، `exportToBlob(PNG, exportingFrame)` بمقياس 1.5، ثم `embedPng` في **pdf-lib** بصفحة PDF واحدة 16:9 لكل صفحة من السبّورة.
  - pdf-lib إصدار 1.17.1، MIT، **غير مصانة منذ 2021**. الواجهة التي نستخدمها صغيرة ومستقرّة؛ وهذا خطر يُسجَّل. البديل jsPDF (MIT، مصانة)، وتُقارَن في المرحلة 0.
- **الإرفاق** (مراجعة: ق-١١): **يُعاد استخدام باب مرفقات الدرس القائم** للرفع، ولا تُنشئ وحدة Whiteboard ملفاً يملكه الدرس:
  1. `POST /lessons/{lesson}/assets` (الدور `attachment`، النوع `document`) → PUT → `complete`، في المتصفّح عبر `lib/media.ts`، وبتفويض Media نفسه؛
  2. ثم `POST /boards/{b}/lesson-exports {lesson, asset}` يسجّل الصلة وحدها في المرة الأولى، والاستبدال `PUT /boards/{b}/lesson-exports/{export}` تحت `2fa.required` (D2).

  الـ Action `RecordBoardExport` تتحقّق أن الملف `Ready`، ومالكه ذلك الدرس، ونوعه `application/pdf`، ودوره `Attachment`، وأنه غير مربوط بتصدير آخر، وأن المستدعي يجتاز نطاق `MediaAssetPolicy::create` على الدرس. وإن كان مرفق سابق مربوطاً:
  - إن كان الجديد هو القديم نفسه (`$new->is($old)`) يعود مبكّراً بلا تبديل ولا حذف؛
  - `Gate::authorize('delete', $old)` (`MediaAssetPolicy`: `LESSONS_DELETE` + النطاق) على مسار تحت `2fa.required` (D2)؛
  - التبديل بتحديث شرطي عبر Eloquent: `->where('id', $e)->where('media_asset_id', $old)->update([...])`، و`where(..., null)` يصير `IS NULL` وحده؛
  - القديم الذي صار `null` بعد `nullOnDelete` يُعامَل إرفاقاً أوّل (لا `Gate` على null)؛
  - حذف القديم عبر `DeleteMediaAsset` **بعد الالتزام**، وفقط إن تغيّر صفّ واحد.
  - مَن لا يملك الحذف يرفق **المرّة الأولى فقط**، ومحاولة الاستبدال تُجاب 403 برسالة «اطلب من مدرّس الكورس».
  - تُحفَظ الصلة في الجدول `board_lesson_exports(board_id, lesson_id, media_asset_id)`، و`media_asset_id` فيه `nullOnDelete`.
- **الطلاب:** الباب القائم، `IssuePlaybackGrant` → `mayWatch`. وإن فتح طالب المرفق القديم أثناء الاستبدال فجاءه 403/404، تعيد الشاشة جلب المرفقات.

## R-13: الكائنات الغنية (الجداول، المعادلات، النصّ القرآني)

- **النمط:** عنصر `image` في Excalidraw مع `customData` مسطّح بإصدار (`{ kind, v, … }`، الشكل الكامل في data-model.md) وملف **PNG**، يُحوَّل إلى صورة بمقياس 3× في المتصفّح.
  - إعادة التحرير تستبدل `fileId` وتُبقي على `id` العنصر، و`x/y`، و`angle`، و`width`، وترتيب الطبقات.
- **لماذا PNG لا SVG:** ملف SVG ناقل لسكربتات حين يُقدَّم من نطاقنا (`PlaybackController` يقدّم بـ `inline`). والمصدر محفوظ في `customData`، فإعادة الرسم بأيّ جودة ممكنة دائماً.
- **الجدول:**
  - كود خاصّ بنا بلا مكتبة، محرّر شبكة RTL؛
  - يُرسَم SVG في المتصفّح، ثم canvas، ثم PNG. نصوص الخلايا تُكتَب نصّاً (`textContent`)، **لا** عبر `innerHTML` أبداً؛
  - اللصق: مستمع `paste` الخاصّ بنا في مرحلة الالتقاط يقرأ `text/html` (`<table>`) أو TSV (`\t`/`\n`) من الحافظة (R-06)، و`onPaste` احتياط.
- **المعادلة:**
  - **MathLive 0.111.0** (MIT، نحو 227 KB مضغوطاً بـ gzip) للكتابة؛
  - **MathJax 4.1.3** (Apache-2.0) عبر `tex2svgPromise` مع **mhchem** (`\ce`) للعرض؛
  - كلاهما **يُحمَّل عند الطلب فقط**، حين يُفتَح محرّر المعادلات، ولا يُستورَدان إلا داخل مكوّنات `next/dynamic`. ومع MathJax امتداد `safe`.
  - **لم يُتحقَّق منه:** هل mhchem موجود في حزمة v4 المجمَّعة أم يُحمَّل وقت التشغيل. هذا يحدّد طريقة استضافة ملفاته ذاتياً (`loader.paths`).
- **النصّ القرآني (US11):**
  - **النصّ:** Tanzil Uthmani، رخصة CC-BY 3.0، حرفياً بلا أيّ تغيير، مع إشعار حقوق النشر ورابط إلى tanzil.net (https://tanzil.net/docs/text_license).
  - **الخطّ:** **Amiri Quran** (SIL OFL 1.1).
  - يُدرَج ككائن غنيّ بـ `customData` مسطّح `{ kind: "quran", v, surah, from, to, edition }`، فلا يُخزَّن النصّ نفسه في المشهد. يُرسَم من الملف الحرفي المضمَّن، فـ **لا يمكن** تحريره.
  - **المرفوض:** خطوط KFGQPC وصور صفحات مصحف المدينة (مملوكة، تحتاج إذناً كتابياً)؛ وapi.quran.com (لا تخزين أبعد من 7 أيام).

## R-14: أدوات التدريس والمؤثّرات والأنشطة (القصص 9–12)

- **أدوات الهندسة (FR-029):** طبقة React فوق اللوحة تتبع `onScrollChange`. ناتجها عناصر أصلية `line` / `arrow` / `ellipse`، أو قوس كنقاط `line`، تُضاف عبر `updateScene(IMMEDIATELY)`. والأداة نفسها لا تكون في المشهد أبداً.
- **قوالب الصفحات (FR-030):** عنصر `image` **مقفل** في أسفل الصفحة (`customData.kind="template"`)، يحمل نقشاً SVG يُحوَّل إلى صورة مرّة واحدة لكل قالب ويُخزَّن مؤقّتاً بـ `fileId` ثابت (`template:grid:v1`، يطابق `^template:[a-z-]+:v\d+$` بصرامة).
  - السبب: يُصدَّر مع الصفحة بكل الصيغ، ويبقى لون الخلفية منفصلاً.
  - المرفوض: `gridModeEnabled`. هي شبكة واحدة، لا تُصدَّر، وبلا خيار سطور أو شبكة متساوية القياس.
- **أنواع الأقلام (FR-031): الحدّ الرئيسي.** freedraw في Excalidraw له عرض الخطّ والشفافية واللون، و**بلا ملمس** (طباشير، رشّ).
  - **خيار المرحلة 0:** «إعدادات أقلام جاهزة» = تركيبات من العرض والشفافية واللون (قلم تحديد شفّاف، فرشاة عريضة).
  - الطباشير والرشّ يحتاجان إمّا عنصراً مخصّصاً (مستحيل، القيد 2) أو تحويل الضربة إلى صورة (ثقيل، ولا يعود تحرير الضربة ممكناً).
  - **مسجَّل كمرشَّح للانحراف** أمام المالك في تقرير المرحلة 0. والمواصفة تقول ذلك صراحةً في FR-031: «قيد التقييم في المرحلة ٠ — قد يُستبدل بأقلام جاهزة بقرار المالك».
- **المكبّر، والستارة، والعجلة، والبقعة الضوئية، والمؤقّت، ومؤثّرات الاحتفال، وآثار المؤشّر (FR-032):** كلّها في طبقة علوية **واحدة** خارج المشهد، فلا يُحفَظ منها شيء.
  - المؤثّرات: كود خاصّ بنا بـ Canvas 2D أو CSS. **canvas-confetti** (ISC، 92 KB) فقط إن وافق المالك؛ والكود الخاصّ بنا نحو 60 سطراً للبالونات والقصاصات.
  - **الأصوات:** ملفات قصيرة مستضافة ذاتياً برخصة CC0 (يُسمّى المصدر في التقرير). لا تصل إلى البثّ إلا إن شارك المدرّس صوت التبويب، وهو ما يدعمه Chrome/Edge وحدهما (MDN).
- **الألعاب والمشاهد ثلاثية الأبعاد (FR-034):** مكوّن «نشاط» يُفتَح فوق الصفحة في إطاره الخاصّ، ولا يُحمَّل **إلا** عند فتحه (`next/dynamic`؛ `three` لا تُستورَد إلا داخله)، ويُغلَق بضغطة واحدة. لا يحمل أيّ حالة في المشهد.
  - ثلاثي الأبعاد: **three** 0.186.1 (MIT) وحدها، **بلا** `@react-three/fiber` (لا حاجة إلى طبقة React).
  - المجموعة الأولى لعبتان (وصّل الحروف، الذاكرة) ومشهدان (المجموعة الشمسية، الجدول الدوري).
  - بقيّة القائمة المرجعية متأخّرات: عمل محتوى لا هندسة.
  - نسيج الكواكب: من مصادر NASA في الملك العام فقط.

## R-15: مشاركة الشاشة (FR-024، لموافقة المالك فقط)

- **حقيقة** (`livekit-client` 2.21.0، `.d.ts`): `setScreenShareEnabled(enabled, options?: ScreenShareCaptureOptions, publishOptions?: TrackPublishOptions)`.
  - بلا خيارات اليوم:
    - الالتقاط 1080p30 `ideal`؛
    - النشر `screenShareEncoding = h1080fps15` (2.5 Mbps)، vp8، مع طبقة إضافية واحدة بـ 960×540؛
    - `degradationPreference` هو `maintain-resolution` أصلاً لمصدر الشاشة؛
    - **`contentHint` غير مضبوط.**
- **المقترح** (لا يُنفَّذ دون موافقة):
  - الالتقاط: `{ contentHint: "text", resolution: { width:1920, height:1080, frameRate:15 }, selfBrowserSurface: "exclude", surfaceSwitching: "include" }`؛
  - النشر: `{ videoCodec: "vp8", screenShareEncoding: { maxBitrate: 1_500_000, maxFramerate: 10 }, screenShareSimulcastLayers: [ScreenSharePresets.h720fps5] }`.
  - ⚠️ **لا `vp9`/`av1`:** مع SVC تفرض المكتبة `contentHint='motion'`.
  - ⚠️ **لا `preferCurrentTab: true`:** السبّورة في تبويب **آخر**، وهذا الخيار يعرض تبويب الغرفة.
  - الأرقام تقديرنا. تقيسها المرحلة 0 على هاتف.
- **الطلاب:** `<LiveKitRoom>` بلا `options`، فـ `adaptiveStream=false` و`dynacast=false`. وهذا جيّد للنصّ: الشاشة الصغيرة لا تنزل إلى أدنى طبقة. لا تُفعِّل `adaptiveStream`.
- **التسجيل:** `RoomCompositeEgress` بتخطيط `single-speaker` بالافتراضي 720p30. السبّورة تُلتقَط لكنها تُعاد ضغطاً إلى 720p. رفعها إلى `H264_1080P_30` قرار منفصل خارج هذه المواصفة (مسجَّل في مخاطر plan.md).
- **المتصفّحات** (MDN BCD 2026-09-02):
  - `getDisplayMedia` **غير متاحة** على Safari في iOS/iPadOS ولا على Chrome/Firefox في Android (عرضها Chrome Android 72–88 وكانت تفشل دائماً). **يجب أن يكون المدرّس على متصفّح مكتبي.**
  - صوت التبويب: Chrome/Edge فقط.
  - التقاط عنصر واحد (`restrictTo`، Element Capture): Chrome 132+ على المكتب فقط. ممكن لاحقاً لميزة «شارك السبّورة فقط».

## R-16: نصوص الواجهة (القيد 8: كل النصوص عبر i18n)

- **حقيقة:** لا نظام i18n في التطبيق. النصّ عربي مكتوب في موضعه، مع ملف مشترك `lib/labels.ts`.
- **القرار:** ملف واحد `frontend/src/lib/whiteboard/strings.ts` (كائن عربي `as const`) تمرّ عبره كل نصوص السبّورة.
  - يتبع شكل `lib/labels.ts`، والانتقال لاحقاً إلى مكتبة i18n حقيقية يستبدل استيراداً واحداً.
  - المرفوض: إضافة next-intl الآن (اعتماد جديد وتغيير في التطبيق كلّه من أجل ميزة واحدة).
- **انحراف مسجَّل:** القيد 8 يقول «i18n»، ونحن نسلّم «ملف نصوص واحداً». يحقّق المقصد (لا نصّ متناثر في الكود) بلا مكتبة.

## R-17: الاعتمادات الجديدة (القيد 6: تتطلّب موافقة)

| الاسم | الإصدار | الرخصة | الحجم | السبب | متى |
|---|---|---|---|---|---|
| `@excalidraw/excalidraw` | 0.18.1 (بالضبط) | MIT | 47 MB على npm، يُحمَّل عند الطلب | اللوحة | المرحلة 0 |
| `pdf-lib` | 1.17.1 | MIT | 19.5 MB على npm، والمستخدَم ~0.5 MB | تصدير PDF | المرحلة 1 (أو jsPDF بعد المقارنة) |
| `mathlive` | 0.111.0 | MIT | ~227 KB gzip | محرّر المعادلات | المرحلة 1، عند الطلب |
| `mathjax` | 4.1.3 | Apache-2.0 | ~1.9 MB tex-svg | عرض المعادلات + mhchem | المرحلة 1، عند الطلب |
| `three` | 0.186.1 | MIT | ~600 KB بعد tree-shaking | المشاهد ثلاثية الأبعاد | الأنشطة (القصّة 11) |
| `canvas-confetti` (اختياري) | 1.9.4 | ISC | 92 KB | مؤثّرات الاحتفال | القصّة 10، أو كود خاصّ بنا |

**على الخادم (بلا تعديل):**
- `gotenberg/gotenberg:8` (MIT، حاوية)؛
- `poppler-utils` (GPL، أدوات سطر أوامر داخل صورة الخلفية).

**المحتوى:**
- نصّ جزء عمّ من Tanzil (CC-BY 3.0، حرفياً)؛
- خطّ Amiri Quran (OFL)؛
- Cairo (OFL، مستخدَم أصلاً)؛
- الأصوات (CC0، تُسمّى مصادرها قبل الاستخدام).
