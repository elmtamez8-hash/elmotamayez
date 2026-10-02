# نموذج البيانات: سبّورة المدرّس (039)

الوحدة: `backend/app/Modules/Whiteboard/` (وحدة جديدة، تُضاف إلى `phpstan.neon`). الهجرات في `Database/Migrations` بحرف M كبير.

## تصنيف الملكية (الدستور، المبدأ I)

| الجدول | الطبقة | الحارس |
|---|---|---|
| `boards` | **مملوك لمساحة العمل** | `BelongsToWorkspace` + `BoardPolicy` |
| `board_pages` | **مملوك لمساحة العمل**، يتبع سبّورته | `BelongsToWorkspace` + باب السبّورة |
| `board_imports` | **مملوك لمساحة العمل** | `BelongsToWorkspace` |
| `board_lesson_exports` | **مملوك لمساحة العمل** | `BelongsToWorkspace` |
| `media_assets` (قائم) | كما هو | المالك `Board` لملفات السبّورة. مرفق الدرس المُصدَّر يُنشئه باب Media القائم (المالك `Lesson`)، لا وحدة Whiteboard |

لا جدول مملوك للمنصّة. كل جدول جديد يُضاف إلى `tests/Feature/Tenancy/WorkspaceIsolationTest.php`.

**البيانات الشخصية** (مراجعة: ق-٦): `WhiteboardPersonalData` يطبّق عقد Compliance (مسارات التصدير والمحو والانتهاء)، ومعه صفوف في `backend/database/seeders/DataCategorySeeder.php` وهجرة ملء `data_categories` تستدعي `(new DataCategorySeeder)->run()` على سابقة `Store/Database/Migrations/2026_08_29_001300_backfill_store_data_categories.php`، وإلا يفشل `PersonalDataContractCoverageTest`. السبّورات محتوى لمساحة العمل: خروج المدرّس (FR-037) يُبقيها، والمحو يمسّ الأعمدة التي تسمّي الشخص لا المحتوى.

**قرار المحو (مراجعة المهام 2026-10-02):** `owner_user_id` و`board_imports.user_id` يبقيان `NOT NULL`. **يُبقى المحتوى ويُعاد إسناد `owner_user_id` إلى مالك مساحة العمل عند محو الحساب**، ومثله `board_imports.user_id`، و`editor_user_id` يُفرَّغ. المرفوض: جعل العمود nullable على سابقة `2026_08_01_000800_make_workspace_owner_nullable.php`؛ نُظر فيه ورُفض، لأن سبّورة بلا مُنشئ تفقد صاحبها حين لا يكون لها مقرّر (D1).

---

## `boards`

| العمود | النوع | القاعدة |
|---|---|---|
| `id` | bigint PK | لا يُكشَف أبداً |
| `uuid` | uuid unique | `HasUuid`، مفتاح المسار |
| `workspace_id` | FK workspaces | `BelongsToWorkspace` |
| `owner_user_id` | FK users `cascadeOnDelete` (عُرف المستودع لعمود `owner_user_id`) | **المُنشئ**. «صاحب السبّورة» **مشتقّ** لا مخزَّن (D1): مدرّس المقرّر `Course::teacherUser()` حين يوجد `course_id`، وإلا المُنشئ. القوائم تستدعي `Course::primeCreatorTeaches()` فلا يكلّف `can` استعلاماً لكل صفّ. صفوف `users` لا تُحذَف في المنتج؛ المحو يمرّ بعقد Compliance (`WhiteboardPersonalData`) |
| `title` | varchar(160) | إلزامي |
| `course_id` | FK courses null، `nullOnDelete` | `WorkspaceRules::exists('courses', 'uuid')` |
| `lesson_id` | FK lessons null، `nullOnDelete` | من المقرّر نفسه في `course_id` حين يُعطى الاثنان |
| `class_session_id` | FK class_sessions null، `nullOnDelete` | الصلة المباشرة بجلسة (FR-022)، مُرشِّح للراحة لا فرع تفويض |
| `background` | enum `white` · `blackboard` · `greenboard` | الافتراضي `white` (FR-007) |
| `editor_user_id` | FK users null، `nullOnDelete` | قفل التحرير (R-09) |
| `editor_tab_id` | uuid null | التبويب الذي يحمل القفل |
| `editor_seen_at` | `timestamp(3)` null | آخر نبضة. ينتهي بعد 120 ثانية. **بالملّي ثانية، وبساعة PHP** (R-09): يُكتَب نصّاً `Y-m-d H:i:s.v` من `$now` واحد لكل استدعاء، لأن ربط كائن تاريخ يُسقط الملّي ثانية. وتجديد في الثانية نفسها على `timestamp` عادي لا يغيّر القيمة، فيعيد MySQL صفراً |
| `editor_handover_tab` | uuid null | التبويب الذي طلب «خُذ التحرير». من يطلبه هو صاحب السبّورة دائماً، فلا عمود للمستخدم (R-09) |
| `editor_handover_at` | `timestamp(3)` null | ينتقل التحرير بعد 10 ثوانٍ مهما حدث. بالصيغة نفسها ومن المنسِّق نفسه في `BoardLock` |
| `pages_count` | unsigned smallint | عدّاد لبوّابة الصفّ وللقائمة؛ ≤ `whiteboard.max_pages_per_board`. **لا يُكتَب إلا داخل المعاملات المحروسة ببوّابة الصفّ** (إضافة، حذف، استيراد، نسخ)، و**ليس في `$fillable`**. المصانع تُبقيه مساوياً لعدد الصفحات الفعلي |
| `pending_operation` | enum `building` · `duplicating` · `deleting` null | حماية النقرة المزدوجة للنسخ والحذف في الطابور. `deleting` و`building` (نسخة لم تكتمل) يُخفيان السبّورة من القوائم ومن `view`. **ليس في `$fillable`** |
| `timestamps` | | |

**الفهارس:** `(workspace_id, owner_user_id)`، `(workspace_id, course_id)`، `(workspace_id, lesson_id)`، `(workspace_id, class_session_id)`.

**القواعد (في الـ Actions، لا في التحقّق وحده):**
- يجب أن ينتمي `lesson_id` إلى `course_id` حين يُعطى الاثنان. وحين يُعطى الدرس وحده، يُشتَقّ المقرّر منه.
- المساعد المحصور لا يُنشئ سبّورة بلا مقرّر ولا ينقلها إلى مقرّر خارج نطاقه.
- **الحذف الناعم للمقرّر لا يُطلق `nullOnDelete`**، والحصص لا تُحذَف أبداً. فالقراءة تأخذ اسم المقرّر بـ `withTrashed()`، والسبّورة التي حُذف مقرّرها ناعماً تصير لصاحبها وحده (فرع المساعد مرفوض)، وصاحبها يبقى `teacherUser()` للمقرّر المقروء بـ `withTrashed()`. حذف الدرس نهائياً يُفرِغ الصلة ويُبقي السبّورة (حالة طرفية في المواصفة).
- **الحذف** (صاحب السبّورة والمدير، Q5) **نهائي** ويمرّ بـ `2fa.required`:
  - تسأل الشاشة مرّتين عبر `ConfirmButton`؛
  - مهمّة في الطابور تحذف ملفات السبّورة عبر `DeleteMediaAsset` بعد الالتزام (commit)؛
  - المرفق المُصدَّر إلى درس **يبقى**، لأنه ملك للدرس.
  - `ponytail:` لا حذف ناعم ولا مكنسة تطهير. يُضافان إن أُبلغ يوماً عن حذف بالخطأ.

## `board_pages`

| العمود | النوع | القاعدة |
|---|---|---|
| `id`، `uuid`، `workspace_id` | | كما سبق |
| `board_id` | FK boards `cascadeOnDelete` | |
| `position` | unsigned smallint | **unique** `(board_id, position)`؛ إعادة الترتيب تعيد الترقيم في معاملة واحدة (`database.md`: إعادة الترقيم قبل الفهرس) |
| `scene` | `longText` (اختياراً، لا `json`) | مستند الصفحة: `{ v, elements[], appState: {viewBackgroundColor}, fileIds[] }`. نوع `json` في MySQL نوع JSON حقيقي يعيد ترتيب المفاتيح ويفكّ النصّ ويعيد بناءه، فيُخزَّن النصّ كما وصل ويُتحقَّق منه في PHP. **لا `dataURL`** (FR-028)؛ ترفض الـ Action أيّ عنصر فيه `dataURL` وأيّ ملف ليس في `fileIds`. وعناصر `fileIds` المطابقة للتعبير الصارم `^template:[a-z-]+:v\d+$` مسموحة بلا أيّ بحث (تُولَّد في المتصفّح ولا تُخزَّن أبداً)؛ وكل معرّف آخر يجب أن يكون `MediaAsset` تملكه هذه السبّورة وحالته `Ready`. `element.link` يُقبَل `http(s)` أو مساراً نسبياً فقط |
| `scene_bytes` | unsigned int | يُفحَص مقابل `whiteboard.max_scene_bytes` (الافتراضي 2 MB)، ومجموعه مقابل `whiteboard.max_board_bytes` (50 MB) |
| `version` | unsigned int default 1 | قفل تفاؤلي **لكل صفحة**، +1 مع كل حفظ (FR-012) |
| `client_tab` | uuid null | التبويب الذي كتب آخر حفظ. مع `client_rev` هو مفتاح الأثر الواحد `(tab, client_rev)` (R-08) |
| `client_rev` | unsigned int null | آخر `client_rev` طُبِّق. الطلب يُعدّ مكرَّراً فقط إن طابق `client_tab` و`client_rev` **و** كان إصداره المرسَل = المخزَّن − 1 |
| `background_asset_id` | FK media_assets null، `nullOnDelete` | خلفية المستند المقفلة (FR-014) |
| `timestamps` | | |

**نصّ المستند المستورد:** **لا عمود له** في هذا النطاق. `pdftotext` بلا قارئ اليوم، فـ FR-015 مؤجَّل إلى المرحلة ٣ (`ponytail:` يُضاف حين يوجد قارئه).

**الصور المصغّرة:** لا عمود ولا مسار لها. يرسمها الشريط الجانبي في المتصفّح عبر `exportToCanvas({maxWidthOrHeight: 320})` للعناصر الظاهرة فقط (R-07) — بلا عمود، وبلا مسار، وبلا تحديد معدّل (`ponytail:` تبسيط).

**شكل `scene` (بإصدار):**
```json
{ "v": 1,
  "elements": [ /* native Excalidraw elements, stable ids; the first is the frame id="frame:{pageUuid}" 1920×1080 */ ],
  "appState": { "viewBackgroundColor": "#ffffff" },
  "fileIds": ["9f3c…", "template:lined:v1"] }
```
الـ `fileId` إمّا uuid ملف للسبّورة (`MediaAsset` يملكه `Board`) أو معرّف قالب ثابت (`template:<name>:v<n>`) يُولَّد في المتصفّح ولا يُخزَّن أبداً.

## `customData` (على العناصر، بإصدار، يُرحَّل في المتصفّح)

```ts
type WbCustomData =
  | { kind: "frame"; v: 1 }
  | { kind: "doc-background"; v: 1; importUuid: string; page: number }
  | { kind: "template"; v: 1; name: "lined"|"grid"|"dotted"|"isometric"|"graph"|"arabic-lines" }
  | { kind: "table"; v: 1; dir: "rtl"|"ltr"; rows: { cells: { text: string; fill?: string }[] }[]; colWidths: number[] }
  | { kind: "math"; v: 1; latex: string; display: boolean }
  | { kind: "quran"; v: 1; surah: number; from: number; to: number; edition: "tanzil-uthmani-1.1" };
```

- **الترحيل:** `migrateCustomData(cd)` في `lib/whiteboard/custom-data.ts` دالّة خالصة. ترفع أيّ `v` إلى الإصدار الحالي، ويفحص vitest كل إصدار أقدم.
- **النوع المجهول:** العنصر الذي لا يعرف الكود `kind` الخاصّ به **يُحفَظ كما هو** (صورة عادية) ولا يُحذَف أبداً. وهذا يحمي التعاون لاحقاً، حين يكتب إصدار أحدث أنواعاً لا يعرفها إصدار أقدم.

## `board_imports`

| العمود | النوع | القاعدة |
|---|---|---|
| `id`، `uuid`، `workspace_id` | | |
| `board_id` | FK boards `cascadeOnDelete` | |
| `user_id` | FK users `cascadeOnDelete`، NOT NULL | من طلب الاستيراد، لحدّ «استيراد واحد جارٍ لكل مستخدم». عند محو الحساب يُعاد إسناده إلى مالك مساحة العمل |
| `source_asset_id` | FK media_assets null، `nullOnDelete` | الملف الأصلي (المالك `Board`). لا يُقدَّم أبداً من باب ملفات السبّورة |
| `status` | enum `uploading` · `queued` · `converting` · `done` · `failed`، **افتراضي قاعدة البيانات `uploading`** | |
| `failure_reason` | varchar(60) null | رمز (`too_many_pages`، `corrupt`، `unsupported`، `timeout`، `board_deleted`) يُحوَّل إلى نصّ عربي في المتصفّح. `too_large` ليس هنا: هو 422 قبل التذكرة أو عند `complete` |
| `dispatched_at` | `timestamp(3)` null | آخر إرسال إلى الطابور، تقرؤه المكنسة |
| `dispatch_attempts` | unsigned tinyint default 0 | عدّاد إعادة الإرسال، وسقفه في المكنسة ثم `failed/timeout` |
| `pages_count` | unsigned smallint null | |
| `insert_after_page_id` | FK board_pages null، `nullOnDelete` | الصفحة التي تُدرَج الصفحات بعدها. إن حُذفت أثناء التحويل تُلحَق الصفحات في الآخر |
| `started_at`، `finished_at`، `timestamps` | | |

**الفهارس:** `(status, created_at)` لعدّ موضع الانتظار عبر المنصّة (`withoutWorkspaceScope()`)، و`(user_id, status)` لحدّ المستخدم.

**انتقالات الحالة:**

```
uploading → queued → converting → done
    ↘          ↘          ↘ failed
   failed     failed
```

- لا مسار غيره. الانتقال `UPDATE … WHERE status = ?` مشروط.
- **`uploading → queued`** في `CompleteBoardImport`: الملف المصدر `Ready`، و`size_bytes` **الفعلي** ≤ `import_max_bytes` (لا الحجم المُعلَن في التذكرة).
- **استيراد واحد جارٍ لكل مستخدم** (`uploading`/`queued`/`converting`): المعاملة تبدأ بقفل صفّ المستخدم `UPDATE users SET id = id WHERE id = ?` بلا فحص للعدد، ثم تفحص وتُدرج. **لا** `INSERT … WHERE NOT EXISTS`. ومعه محدِّد المعدّل `whiteboard-import`.
- **لا صفحات نصف مبنيّة:** الصور الوسيطة في مجلّد مؤقّت باسم uuid الاستيراد، و`MediaAsset` لا يُنشأ إلا في المعاملة الأخيرة. تبدأ المعاملة ببوّابة الصفّ `UPDATE boards SET pages_count = pages_count + ? WHERE id = ? AND pages_count + ? <= ? AND pending_operation IS NULL` (وسائط موضعية؛ لا اسم مكرّر)، ثم تُركَن المواضع عند `max(position)+1+i` قبل الضغط (R-10)، ثم `done` بـ `WHERE status = 'converting'`، وصفر صفوف = تراجع. السبّورة التي تُحذَف تُنهي الاستيراد `failed/board_deleted`. التنظيف في `finally`.
- **المكنسة** (R-10): `converting` أقدم من مهلة المهمّة + هامش (900 + 300 ثانية) → `failed/timeout`؛ `uploading` العالق → `failed`؛ `queued` العالق يُعاد إرساله حتى سقف `dispatch_attempts`.
- **موضع الانتظار** يُرتَّب بـ `(created_at, id)`.
- المهمّة تختم `workspace_id` صراحةً (أو تعمل داخل `WorkspaceContext::forWorkspace`)، و`uuid` صراحةً إن أُدرجت الصفوف دفعةً (`insert` لا يُشغّل `HasUuid`).

## `board_lesson_exports`

| العمود | النوع | القاعدة |
|---|---|---|
| `id`، `uuid`، `workspace_id` | | |
| `board_id` | FK boards `cascadeOnDelete` | |
| `lesson_id` | FK lessons `cascadeOnDelete` | |
| `media_asset_id` | FK media_assets null، **`nullOnDelete`** | المرفق الحالي للدرس (المالك `Lesson`، الدور `Attachment`). بلا `nullOnDelete` يصير حذف الدرس أو مرفقه 500، لأن `ManageLessons`/`DeleteMediaAsset` يحذفان صفّ الوسائط |
| `timestamps` | | **unique** `(board_id, lesson_id)` |

**الاستبدال (Q2، D2):** الملف الجديد رُفع **مسبقاً** عبر باب Media القائم. `RecordBoardExport`:
1. يتحقّق أن الملف `Ready`، ومالكه الدرس نفسه، ونوعه `application/pdf`، ودوره `Attachment`، وغير مربوط بتصدير آخر، والمستدعي يجتاز نطاق `MediaAssetPolicy::create` على الدرس؛
2. إن كان الجديد هو القديم (`$new->is($old)`) يعود مبكّراً؛
3. إن وُجد قديم غير null: `Gate::authorize('delete', $old)`. القديم الذي صار null بعد `nullOnDelete` يُعامَل إرفاقاً أوّل؛
4. التبديل عبر Eloquent: `->where('id', $e)->where('media_asset_id', $old)->update(['media_asset_id' => $new])` (و`where(..., null)` يصير `IS NULL`)؛
5. يُحذَف القديم عبر `DeleteMediaAsset` **بعد الالتزام**، وفقط إن تغيّر صفّ واحد.

## `media_assets`: لا تغيير على الجدول

- مالك جديد `Board` (`owner_type = Board::class`)، النوع `Document`، الدور `Attachment`.
- الملفات التي تملكها السبّورة:
  - الصور التي يُدرجها المدرّس (مصغَّرة في المتصفّح إلى 2560 px كحدّ أقصى)؛
  - خلفيات الصفحات (JPEG)؛
  - الكائنات الغنية (PNG)؛
  - الملف الأصلي المستورَد.
- كل FK من جداول السبّورة إلى `media_assets` هو `nullOnDelete`.
- **نسخ السبّورة لا يمرّ بالمزوّد:** عقود Media لا تعرف النسخ (`ingestFromUrl` وحده). تُنشأ السبّورة الجديدة مخفيّة (`pending_operation = building`)، وتُنسخ البايتات على قرصنا المحلي عبر `Media\Actions\CopyLocalMediaAsset` (للمزوّد المحلي وحده، ويرفض غيره)، فتولد الملفات الجديدة `Ready` ومالكها السبّورة الجديدة، ثم تُعاد كتابة المعرّفات ويُفرَّغ `building`. التنظيف عند الفشل = حذف السبّورة المخفيّة.
- `config('media.full_allowance_owners')` تُضاف إليها `Board::class`.
- قائمة MIME المسموحة لملفات السبّورة: `image/png`، `image/jpeg` وحدهما (`CompleteBoardFile`)، إضافةً إلى PDF/Office للملف الأصلي المستورَد فقط (`CompleteBoardImport`). **لا SVG.**

## platform_settings (مفاتيح جديدة)

| المفتاح | الافتراضي | الاستخدام |
|---|---|---|
| `whiteboard.import_max_bytes` | 26214400 | Q4 |
| `whiteboard.import_max_pages` | 100 | Q4 |
| `whiteboard.max_scene_bytes` | 2097152 | رفض مشهد متضخّم |
| `whiteboard.max_board_bytes` | 52428800 | سقف مجموع مشاهد السبّورة الواحدة (`SaveBoardScene`) |
| `whiteboard.max_pages_per_board` | 300 | سقف السبّورة الواحدة |
