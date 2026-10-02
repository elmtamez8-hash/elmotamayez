# عقد الواجهة البرمجية: السبّورة (`/api/v1`، Sanctum bearer، `X-Workspace`)

**قواعد مشتركة:**
- كل ردّ يمرّ عبر API Resource ولا يكشف إلا `uuid` (المبدأ VI). ولا يظهر `provider`/`provider_asset_id` أبداً (`media.md`).
- السبّورة من مساحة عمل أخرى، أو التي لا يستطيع المستخدم قراءتها، تُجيب **404**، مطابقةً لسبّورة غير موجودة (FR-025، على سابقة `StoreMediaOwnershipTest`).
- السياسة هي `BoardPolicy` (§ التفويض أدناه).
- **كل معرّف متداخل يُحَلّ عبر السبّورة** (مراجعة الخطة: أ-H2). لا وحدة في المستودع تستعمل `scopeBindings()`، فنتبع الفلترة اليدوية في `CompleteStoreFile`:
  - الصفحة: `$board->pages()->where('uuid', …)`؛
  - الملف: `owner_type = Board AND owner_id = $board->id`؛
  - الاستيراد والتصدير: عبر علاقتهما بالسبّورة؛
  - معرّفات الجسم (`after`، `duplicate_of`، `insert_after`، `pages[]`) يجب أن تنتمي إلى السبّورة نفسها، وإلا 404.
  - **الاختبارات:** لكل باب متداخل اختبار يمرّر uuid صفحة أو ملف أو استيراد من **سبّورة أخرى في مساحة العمل نفسها**، ويتوقّع 404. وكلها صفوف في مصفوفة واحدة `backend/tests/Feature/Whiteboard/BoardIsolationTest.php` يساوي عدد صفوفها عدد الطرق المسجّلة (method × URI) التي تبدأ بـ `api/v1/boards`، فالطريق الجديد بلا صفّ يُحمِّر الاختبار.
- **كل باب يكتب** (حفظ المشهد، وعمليات الصفحات، والاستيراد، وPATCH) يسأل `update` **في كل طلب**، لا عند أخذ القفل وحده. فمن سُحبت صلاحيته أو نطاقه يُرفَض في طلبه التالي ولو كان القفل بيده (403).
- **حدود المعدّل مسمّاة لكل مسار** (`http-and-security.md`): `throttle:authoring` لإنشاء السبّورة وتعديلها وعمليات الصفحات · `throttle:whiteboard-autosave` (سخيّ، بالمستخدم) للنبضة وحفظ المشهد، لأنهما يتكرّران كل 1.5–5 ثوانٍ لكل تبويب · `throttle:upload` لتذاكر الملفات · `throttle:whiteboard-files` لقراءة الملفات · `throttle:whiteboard-import` للاستيراد.

## السبّورات

| Method | Path | التفويض | الجسم / الردّ |
|---|---|---|---|
| GET | `/boards?q=&course=&lesson=&session=&mine=1&page=` | `viewAny` + **الاستعلام نفسه مضيَّق** (Action `ListBoards`) | `BoardResource[]` مقسَّمة إلى صفحات (`links`+`meta` عبر `toResponse`، `http-and-security.md`). أعمدة محدَّدة صراحةً، بلا `scene`. `q=` بحث في العنوان، و`lesson=` لقسم «السبّورات» في محرّر الدرس، و`session=` لزرّ الغرفة: **يُضاف مع القصة ٧** (ومعه `class_session` في الإنشاء)، فلا يوجد مرشّح بلا مستدعٍ قبل موافقة المالك على FR-022 |
| POST | `/boards` | `create` | `{title, course?, lesson?, background?}` (و`class_session?` مع القصة ٧) → 201 `BoardResource`، مع صفحة أولى فارغة. `throttle:authoring` |
| GET | `/boards/{board}` | `view` | `BoardDetailResource`: السبّورة + `pages[]` (uuid, position, version, background_file, `scene` **نصّاً خاماً كما خُزِّن، بلا فكّ JSON على الخادم**) + `lock` + `exports[]` + `can` |
| PATCH | `/boards/{board}` | `update` + القفل | `{title?, course?, lesson?, background?}` (و`class_session?` مع القصة ٧). التحقّق نفسه في `create`، وتغيير `course`/`lesson` له شرطان إضافيّان أدناه |
| POST | `/boards/{board}/duplicate` | `view` + `create` | → 202 `{status:"copying"}`. يعمل مهمّةً في الطابور (§ النسخ والحذف). نقرة ثانية أثناء النسخ → 409 `operation_pending` |
| DELETE | `/boards/{board}` | `delete` + **`2fa.required`** | → 202. تختفي السبّورة من القوائم فوراً، وتحذفها مهمّة في الطابور. مدرّس بلا تحقّق ثنائي مُفعَّل يُرفَض |

**التحقّق في `create` وPATCH:**
- `course` و`lesson` و`class_session` تُتحقَّق بـ `WorkspaceRules::exists('courses'|'lessons'|'class_sessions', 'uuid')`، ويجب أن ينتمي الدرس إلى المقرّر حين يُعطى الاثنان.
- **المساعد المحصور** يجب أن يسمّي مقرّراً داخل نطاقه (`mayActOnCourse`). السبّورة بلا مقرّر تُرفَض له، كالاختبارات والواجبات. ونقلها إلى مقرّر خارج نطاقه، أو إلى «بلا مقرّر» (`null`)، يُرفَض كذلك.
- **تغيير `course` أو `lesson` في PATCH لصاحب السبّورة الحالي وحده** (D1)، ثم يجب أن يبقى المستدعي مجتازاً `update` **بعد** التغيير (يُفحَص على الحالة الجديدة قبل الالتزام). فلا ينقل أحد سبّورة إلى مقرّر يفقد فيه حقّ تحريرها، ولا ينقلها مساعد إلى مقرّر غيره.

`BoardResource` = `{uuid, title, background, pages_count, course:{uuid,title,deleted:bool}|null, lesson:{uuid,title}|null, class_session:{uuid,starts_at}|null, owner:{uuid,name}, teacher:{uuid,name}, updated_at, lock:{held_by:{uuid,name}|null, mine:bool}, can}`
- `owner` هو المُنشئ، و`teacher` هو «صاحب السبّورة» (§ التفويض). اسم المقرّر يُقرأ بـ `withTrashed()`.

`can` = `{edit, take_lock, export, delete}` (تقرؤه الشاشة ولا تشتقّه أبداً). يُحسَب من بيانات مُهيَّأة مسبقاً للصفحة كلها (`Course::primeCreatorTeaches()`، ونطاق المساعد مرّة واحدة)، لا باستعلام لكل صفّ ولا بـ `Gate` لكل صفّ.

`exports[]` = `[{lesson:{uuid,title}, attachment:{uuid}|null, can_replace:bool}]` (التصدير إلى درس أدناه).

### النسخ والحذف (مهمّتان في الطابور)

- **الحماية من النقرة المزدوجة:** عمود `pending_operation` (`building` · `duplicating` · `deleting` · null) يُكتَب بتحديث شرطي `WHERE pending_operation IS NULL`. صفر صفوف متأثّرة = 409 `operation_pending`.
- **الطابور:** المهمّتان على `whiteboard-ops` (المشرف `supervisor-whiteboard-ops`، الاتصال `redis-long`)، وتُرسَلان بـ `DB::afterCommit`. `failed()` يفرّغ `pending_operation`، والحذف متساوي الأثر إن وصلت المهمّة مرّتين، والمكنسة تعيد إرسال `deleting` العالق.
- **النسخ:** المزوّد لا يعرف النسخ (عقود Media فيها `ingestFromUrl` وحده)، فـ:
  1. تُنشأ السبّورة الجديدة أوّلاً مخفيّة (`pending_operation = building`)؛
  2. تُنسَخ بايتات الملفات الجاهزة على قرصنا المحلي عبر `Media\Actions\CopyLocalMediaAsset` (للمزوّد المحلي وحده، ويرفض غيره)، فتولد الملفات الجديدة `Ready` ومالكها السبّورة الجديدة؛ الملفات غير الجاهزة تُتخطّى؛
  3. يُعاد كتابة كل `fileId`/`fileIds`/`background_asset_id` إلى النسخ، وصفحات السبّورة الجديدة في معاملة واحدة؛
  4. يُفرَّغ `building`.
  عند الفشل تُحذَف السبّورة المخفيّة كلّها بملفاتها.
- **الحذف:** السبّورة التي `pending_operation = deleting` تُستبعَد من القوائم ومن `view`. المهمّة تحذف الملفات عبر `DeleteMediaAsset` بعد الالتزام.

**الصور المصغّرة:** لا مسار لها ولا حقل في `BoardDetailResource`. يرسمها الشريط الجانبي في المتصفّح عبر `exportToCanvas` بدقّة منخفضة من مشاهد الصفحات الموجودة في الذاكرة أصلاً (R-07) — بلا عمود، وبلا مسار، وبلا تحديد معدّل (`ponytail:` تبسيط).

## قفل التحرير (R-09، Q3)

| Method | Path | الردّ |
|---|---|---|
| POST | `/boards/{board}/lock` `{tab}` | `update`. 200 `{held:true, handover_requested:bool}` · 423 `{held_by:{uuid,name}}` · 403 لمدير ليس صاحب السبّورة |
| DELETE | `/boards/{board}/lock` `{tab}` | 204. إن كان هناك تسليم معلّق ينتقل القفل مباشرةً إلى `editor_handover_tab`. عند إغلاق التبويب: `lib/api.ts` بخيار `keepalive` (`fetch` بـ `method:'DELETE'` و`keepalive:true` والترويسات التي يضعها `lib/api.ts` نفسه)، **لا** `navigator.sendBeacon` (لا يستطيع إرسال ترويسة `Authorization: Bearer`) |
| POST | `/boards/{board}/lock/take` `{tab}` | `takeLock` (**صاحب السبّورة وحده**، D1؛ **لا** `update`، فالمساعد يُجاب 403). 202 `{handover_at}`. تكرار الطلب يعيد `handover_at` نفسه. ينتقل القفل حين يحرّره حامله، أو بعد 10 ثوانٍ |

- النبضة هي `POST /lock` كل 5 ثوانٍ، لتصل مهلة الـ10 ثوانٍ للتبويب القديم. النبضة تُبقي القفل ولو لم يرسم المدرّس شيئاً: المدرّس الذي يعرض بلا رسم لا يفقد القفل.
- **الانتهاء بعد دقيقتين** يعني «التبويب مغلق أو منقطع» (لا نبضة)، لا «لم يرسم».
- الحفظ بلا القفل يعيد **409 `lock_lost`**.
- **قاعدة التسليم** (تحسينٌ على Q3 وافق عليه المالك في 2026-10-01): عند «خُذ التحرير» يُمهَل المحرّر الحالي حتى ١٠ ثوانٍ ليحفظ على الخادم ثم يُحرِّر القفل؛ إن لم يستجب (تبويب مغلق أو بلا شبكة) ينتقل القفل، ويبقى ما لم يُحفَظ في مسودّته المحلية (IndexedDB) ويُعرَض عليه عند عودته.
- **الجمل الشرطية بالضبط** في research.md R-09، بوسائط موضعية، **وبساعة PHP واحدة** (`$now` لكل استدعاء، تُربط نصوصاً `Y-m-d H:i:s.v`)، بلا حساب تواريخ داخل SQL. النجاح = صفّ واحد متأثّر، إلا التجديد: صفر صفوف مع قراءة تُظهر أنني الحامل نجاحٌ أيضاً.

## الصفحات

| Method | Path | الجسم | الردّ |
|---|---|---|---|
| POST | `/boards/{board}/pages` | `{tab, after?: uuid, duplicate_of?: uuid}` | 201 `PageResource` · 422 `too_many_pages` |
| PUT | `/boards/{board}/pages/order` | `{tab, pages: uuid[]}` (كلّها) | 200 `{pages:[{uuid,position}]}` · **409 `pages_changed`** إن لم تساوِ `pages[]` مجموعة صفحات السبّورة كاملةً |
| DELETE | `/boards/{board}/pages/{page}` | `{tab}` | 204 (لا تُحذَف الصفحة الأخيرة: 422) |
| PUT | `/boards/{board}/pages/{page}/scene` | `{tab, version, client_rev, scene}` | 200 `{version, client_rev}` · **409 `version_conflict` `{version, scene}`** · 409 `lock_lost` · 422 `scene_too_large` / `board_too_large` / `inline_file` / `unknown_file` / `bad_link` / `bad_element` |

**حفظ المشهد** (مراجعة: ق-٩؛ ب: قاعدة البيانات والتزامن):
- `Content-Length` يُفحَص مقابل `whiteboard.max_scene_bytes` **قبل** فكّ JSON (في `SaveSceneRequest`). ثم يُفحَص الشكل في PHP، ويُخزَّن النصّ كما هو (`longText`).
- **جملة واحدة**: `UPDATE board_pages … WHERE id = ? AND version = ? AND EXISTS(قفل التبويب نفسه)` (النصّ الكامل في R-09). صفر صفوف = إمّا 409 `lock_lost` وإمّا 409 `version_conflict` وإمّا إعادة محاولة طُبِّقت، يُميَّز بينها بقراءة بعد الفشل.
- **إعادة المحاولة متساوية الأثر:** مفتاحها `(tab, client_rev)`، والعمودان `client_tab` و`client_rev` على الصفحة. الطلب يُعدّ مطبَّقاً فقط إن طابق الاثنان **و** كان `version` المرسَل = المخزَّن − 1، فيُجاب 200 بالإصدار الحالي، لا 409.
- **عناصر مرفوضة على الخادم:** `embeddable` و`iframe` → 422 `bad_element`.
- **سقف السبّورة كلّها:** `whiteboard.max_board_bytes` (الافتراضي 50 MB) على مجموع `scene_bytes`، يُفرَض في `SaveBoardScene` → 422 `board_too_large`.
- **عند 409 `version_conflict`** يتوقّف المتصفّح عن حفظ تلك الصفحة ويعرض «احفظ نسختي كصفحة جديدة» أو «خذ نسخة الخادم». **لا يرفع الإصدار تلقائياً أبداً.**
- `element.link` يُقبَل فقط إن كان `http(s)` أو مساراً نسبياً، بتعبير مثبَّت الطرفين (`\A…\z` مع المعدِّل `D`)، وإلا 422 `bad_link`. يُرفَض `JaVaScRiPt:` والمسافة في أوّله و`data:` و`//evil.example`.

**قاعدة `unknown_file`:** عناصر `fileIds` المطابقة للتعبير الصارم `^template:[a-z-]+:v\d+$` (مثبَّتاً بـ `\z` والمعدِّل `D`؛ `template:grid:v1x` و`template:../a:v1` مرفوضان) مسموحة بلا أيّ بحث (تُولَّد في المتصفّح ولا تُخزَّن أبداً)؛ وكل معرّف آخر يجب أن يكون `MediaAsset` تملكه هذه السبّورة وحالته `Ready`، وإلا فالردّ 422 `unknown_file`. المتصفّح لا يُدرج عنصر ملف إلا بعد أن يصير ملفه `Ready` (والرفع الفاشل يُزيله برسالة)، فلا يحجب `unknown_file` صفحةً أبداً.

**كل عملية بنيوية** (إضافة، ترتيب، حذف) تتطلّب القفل، وتبدأ معاملتها ببوّابة صفّ السبّورة (R-10)، بوسائط موضعية:
- الإضافة: `UPDATE boards SET pages_count = pages_count + ? WHERE id = ? AND pages_count + ? <= ?`، وصفر صفوف = 422 `too_many_pages`؛
- إعادة الترتيب (فرق صفر): `UPDATE boards SET id = id WHERE id = ?` قفلاً بلا فحص للعدد؛
- الحذف (فرق سالب): `UPDATE boards SET pages_count = pages_count - ? WHERE id = ? AND pages_count >= ?`.

## الملفات

| Method | Path | ملاحظة |
|---|---|---|
| POST | `/boards/{board}/files` | `update` + القفل. `{filename, size, mime}` → `{file:{uuid}, upload:{url,method,headers}}` (`RequestBoardFile`، نمط `RequestStoreFile`؛ `throttle:upload`). الصورة المُدرَجة تُصغَّر في المتصفّح إلى 2560 px كحدّ أقصى قبل الرفع |
| POST | `/boards/{board}/files/{file}/complete` | `CompleteBoardFile` → `CompleteMediaUpload` بالقائمة `['image/png','image/jpeg']` وحدها. المتصفّح **يقرأ** `status:failed` |
| GET | `/boards/{board}/files/{file}` | `view`. البايتات (bearer)، للملف الذي حالته `Ready` ونوعه `image/png` أو `image/jpeg` فقط (الملف الأصلي المستورَد لا يُقدَّم من هنا أبداً؛ وغيره 404)، و`Content-Type` من الصفّ، و`X-Content-Type-Options: nosniff`، و`Cache-Control: private, max-age=86400`. حدّ معدّل خاصّ سخيّ `throttle:whiteboard-files` مع `withoutMiddleware('throttle:api')` (سابقة وسائط المحادثة)، لأن فتح سبّورة يجلب كل ملفاتها دفعةً |

**لماذا `max-age=86400` لا `immutable` لسنة:** البايتات لا تتغيّر تحت uuid واحد، لكن الملف قد يُحذَف (حذف صفحة أو سبّورة، أو سُحبت صلاحية القارئ). يوم واحد يكفي لجلسة الحصة كلّها بلا إعادة جلب، ولا يُبقي ملفاً محذوفاً في ذاكرة متصفّح مشترك أكثر من يوم.

## الاستيراد (R-10، Q4)

| Method | Path | ملاحظة |
|---|---|---|
| POST | `/boards/{board}/imports` | `update` + القفل. `{filename, size, mime, insert_after?: page uuid}` → 422 `too_large` قبل التذكرة · 429 `import_in_progress` إن كان للمستخدم استيراد `uploading`/`queued`/`converting` (قفل صفّ المستخدم ثم فحص وإدراج في معاملة واحدة) · `throttle:whiteboard-import`. وإلا `{import:{uuid}, upload:{…}}`، والصفّ يولد `uploading` |
| POST | `/boards/{board}/imports/{import}/complete` | `CompleteBoardImport`: يطلب الملف المصدر `Ready`، ويفحص نوعه (pdf/docx/pptx/png/jpeg) و`size_bytes` **الفعلي** ≤ `import_max_bytes` (وإلا 422 `too_large`)، ثم `queued`، ثم يرسل `ConvertBoardImportJob` بعد الالتزام على `supervisor-whiteboard` (واحد في كل مرّة). 202 `{status:"queued", position}` |
| GET | `/boards/{board}/imports/{import}` | `view`. `{status, failure_reason?, pages_count?, position?}`. يستعلم المتصفّح كل 3 ثوانٍ؛ وحدث مقبس حيّ ترقية لاحقة. `position` يُعَدّ `withoutWorkspaceScope()` على الفهرس `(status, created_at)`، مرتّباً بـ `(created_at, id)` |

**رموز الفشل** (`failure_reason`): `too_many_pages` · `unsupported` · `corrupt` · `timeout` · `board_deleted`. لكلّ منها رسالة عربية في `lib/whiteboard/strings.ts`.
- `too_large` **ليس** رمز فشل: هو 422 قبل التذكرة.
- **عدد الصفحات يُفحَص داخل المهمّة**، لكل الصيغ: لـ PDF عبر `pdfinfo` ثم مرّة ثانية بعد التصيير، ولملفات Office بعد Gotenberg. الملف الطويل (PDF أو PPTX) ينتهي `status: failed`، `failure_reason: too_many_pages`، لا 422.

## التصدير إلى درس (R-12، Q2، D2)

**الرفع يمرّ من باب مرفقات الدرس القائم في وحدة Media** (مراجعة: ق-١١)، لا من باب خاصّ بالسبّورة:
1. `POST /lessons/{lesson}/assets` (الدور `attachment`، النوع `document`) → تذكرة؛ ثم PUT؛ ثم `POST /media/assets/{asset}/complete`. في المتصفّح عبر `lib/media.ts` القائم، وبتفويض Media نفسه.
2. ثم تسجّل السبّورة الصلة وحدها:

| Method | Path | ملاحظة |
|---|---|---|
| POST | `/boards/{board}/lesson-exports` | **الإرفاق الأوّل** فقط. `export`. `{lesson, asset}` → 201 `{attachment:{uuid}}` · 409 `already_exported` (يوجد مرفق سابق، فالاستبدال من المسار التالي) · 422 `asset_not_ready` / `asset_mismatch` |
| PUT | `/boards/{board}/lesson-exports/{export}` | **الاستبدال.** `export` + `Gate::authorize('delete', $old)` + **`2fa.required`** على هذا المسار وحده (D2). `{asset}` → 200 `{attachment:{uuid}, replaced: true}` · 403 `replace_forbidden` «اطلب من مدرّس الكورس» |

- **`RecordBoardExport`** يتحقّق أن الملف `Ready`، ومالكه هذا الدرس نفسه، ونوعه `application/pdf`، ودوره `Attachment`، وأنه غير مربوط بتصدير آخر، وأن المستدعي يجتاز نطاق `MediaAssetPolicy::create` على الدرس.
- **إن كان هناك مرفق سابق** لهذه السبّورة على هذا الدرس:
  - إن كان الجديد هو القديم (`$new->is($old)`) يعود مبكّراً بلا تبديل ولا حذف؛
  - `Gate::authorize('delete', $old)` (`MediaAssetPolicy`، أي `LESSONS_DELETE` + النطاق، كحذف أيّ مرفق اليوم)؛
  - التبديل عبر Eloquent `->where('id', $e)->where('media_asset_id', $old)->update(...)` (و`null` يصير `IS NULL`)، ويُحذَف القديم عبر `DeleteMediaAsset` **بعد الالتزام** وفقط إن تغيّر صفّ واحد؛
  - القديم الذي صار `null` بعد `nullOnDelete` يُعامَل إرفاقاً أوّل، بلا `Gate` على null.
- **مَن لا يملك الحذف** يرفق المرّة الأولى فقط. الشاشة تقرأ `exports[].can_replace` وتقول «اطلب من مدرّس الكورس» **قبل** الرفع؛ وإن وصل 403 رغم ذلك (سباق) يحذف الـ Action الملف الجديد الذي لم يُربَط.
- `2fa.required` وسيط على المسار لا على الفرع. لذلك فُصل الاستبدال في مسار خاص، فلا يُطلب التحقّق الثنائي في الإرفاق الأوّل، كما أن إضافة مرفق للدرس اليوم لا تطلبه. والاستبدال يطلبه كحذف أي مرفق (`Media/routes/api.php:56`).
- **الطالب أثناء الاستبدال:** إن فتح المرفق القديم بعد حذفه فجاءه 403/404، تعيد الشاشة جلب قائمة المرفقات.

## التفويض (`BoardPolicy`)

**«صاحب السبّورة»** (D1): للسبّورة المرتبطة بمقرّر هو مدرّس المقرّر (`Course::teacherUser()`)، حتى لو أنشأها مساعد؛ وللسبّورة بلا مقرّر هو مُنشئها (`owner_user_id`). مالك الأكاديمية الذي يدرّس المقرّر بنفسه هو صاحب السبّورة، فيحرّر.

**كل دالّة تبدأ بفحصين خاصّين بها** (مراجعة: ق-١، أ-M1)، **ولا تعيد استخدام** `BasePolicy::belongsToCurrentWorkspace` لأنه يسمح حين يكون السياق `null`، والعضو المُزال من مساحة العمل سياقه `null`:
1. السياق **محسوم وغير `null`** ويطابق `workspace_id` السبّورة، وإلا رفض (404 على الباب)؛
2. **مكتوب إيجاباً:** يوجد صفّ عضوية للمستخدم في مساحة العمل **بدور محوري من أدوار الطاقم**، **و** `! StaffAccounts::isLearnerAccount($user)` (`Tenancy/Support/StaffAccounts.php:43`)، وإلا رفض صريح. فالطالب ووليّ الأمر (ومنه حساب `platform_role` = Parent) يُرفضان مهما حملا من صلاحيات.

| الدالّة | مَن |
|---|---|
| `viewAny` / `create` | `Permissions::LESSONS_MANAGE`. المساعد المحصور: `create` يتطلّب مقرّراً داخل نطاقه |
| `view` | صاحب السبّورة · **أو** مالك مساحة العمل (الدور المحوري `tenant-owner`، Q5) · **أو** من يجتاز `update` |
| `update` (تحرير المحتوى) | (صاحب السبّورة **و** `LESSONS_MANAGE`) · **أو** (ليس حالة «المدير الذي ليس صاحب السبّورة» **و** `LESSONS_MANAGE` **و** للسبّورة مقرّر غير محذوف **و** `AssistantScopeDirectory::mayActOnCourse($user, $ws, $course)`). أي مساعدو المقرّر ومؤلّفوه. على نمط `StoreItemPolicy` لا `CoursePolicy`. **مالك مساحة العمل الذي ليس صاحب السبّورة لا يجتاز `update` أبداً** (Q5) |
| `takeLock` | صاحب السبّورة فقط |
| `export` | `view` (مالك مساحة العمل يصدِّر، Q5) |
| `delete` | صاحب السبّورة · أو مالك مساحة العمل (Q5) |

- **القائمة (`viewAny`)** لا تُفلتَر صفّاً صفّاً بـ `Gate`. الاستعلام نفسه مضيَّق بمحمول `view` نفسه: سبّوراتي (`owner_user_id`) · سبّورات مقرّرات أدرّسها · سبّورات المقرّرات (غير المحذوفة) في `scopedCourseIdsFor()`، وحين تعيد `null` (غير محصور) فكلّ سبّورة مرتبطة بمقرّر غير محذوف، تماماً كفرع `update` · ولمالك مساحة العمل الكلّ.
- **السبّورة التي حُذف مقرّرها حذفاً ناعماً** (المقرّرات تُحذَف ناعماً، فـ `nullOnDelete` لا ينطلق) تصير لصاحبها وحده، وصاحبها يبقى `teacherUser()` للمقرّر المقروء بـ `withTrashed()`: فرع المساعد مرفوض، واسم المقرّر يُقرأ بـ `withTrashed()` كذلك.
- **الصلة بالحصة** (`class_session_id`) مُرشِّح للراحة فقط، **لا** فرع للمضيف في السياسة: زرّ الغرفة يعرض السبّورات التي يستطيع المضيف `view`.

الطالب أو وليّ الأمر لا مسار له إلى السبّورات إطلاقاً (FR-027): الفحص الثاني أعلاه يرفضه صراحةً، ولا يُعتمَد على غياب `LESSONS_MANAGE` عن أدوارهما وحده (`tenancy.md`: صلاحية تُصنَّف بالغياب لا تحرس شيئاً).

**الاختبارات لكل باب** (مصفوفة واحدة `BoardIsolationTest`، بالرموز الدقيقة): عزل مساحة العمل · uuid متداخل من سبّورة أخرى (أ-H2) · عضو مُزال (سياق `null`) · طالب يحمل `LESSONS_MANAGE` بالخطأ · مساعد محصور خارج نطاقه · مدير ليس صاحب السبّورة يحاول الكتابة.

**تطابق `can`:** اختبار يقارن `can.*` في الـ Resource بـ `Gate::allows` على عيّنة من الممثّلين والسبّورات، فلا تنحرف الحسبة المهيّأة مسبقاً عن السياسة.
