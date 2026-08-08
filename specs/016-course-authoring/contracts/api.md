# API Contract: سطح تأليف الكورسات

**Feature**: [spec.md](../spec.md) · **Date**: 2026-08-08

كل المسارات تحت `/api/v1` بمصادقة Sanctum، وكلها تتعامل بـ`uuid` حصراً. والمعرّفات التسلسلية
التي يكشفها الـAPI اليوم (`section_id` · `chapter_id` في إنشاء الدرس) **تُستبدل** — R1.

---

## ١ — الشجرة

| الطريقة | المسار | الصلاحية | ملاحظة |
|---|---|---|---|
| `GET` | `/courses/{course}/tree` | `LESSONS_MANAGE` | شجرة المؤلّف: تشمل المسودّة والمؤرشف بحالاتها الحقيقية وسبب الحجب |
| `POST` | `/courses/{course}/sections` | `LESSONS_MANAGE` | قائم — يُعاد بناؤه على Action |
| `PUT` · `DELETE` | `/courses/{course}/sections/{section}` | `LESSONS_MANAGE` · `LESSONS_DELETE` | قائم — `{section}` صار uuid |
| `POST` | `/courses/{course}/chapters` | `LESSONS_MANAGE` | قائم |
| `PUT` · `DELETE` | `/courses/{course}/chapters/{chapter}` | `LESSONS_MANAGE` · `LESSONS_DELETE` | قائم |
| `POST` | `/courses/{course}/lessons` | `LESSONS_MANAGE` | قائم — يستقبل `chapter_uuid` لا `section_id`+`chapter_id` |
| `PUT` · `DELETE` | `/courses/{course}/lessons/{lesson}` | `LESSONS_MANAGE` · `LESSONS_DELETE` | قائم |

`GET /courses/{course}/sections` القائم يبقى للاستهلاك الطلابي بشجرة **منشورة فقط**.

> **الفرق بين المسارين هو الحارس كلّه**: `/tree` مسار مؤلِّف يعرض المسودّة، و`/sections`
> مسار قارئ لا يعرضها. خلطهما في مسار واحد بمُعامِل `?include_drafts=1` يجعل تسريب المسودّة
> نسيانَ مُعامِل (`FR-025`).

### إعادة الترتيب

| الطريقة | المسار | الحمولة |
|---|---|---|
| `PUT` | `/courses/{course}/sections/order` | `{ structure_version, order: [uuid, …] }` |
| `PUT` | `/courses/{course}/sections/{section}/chapters/order` | كما أعلاه |
| `PUT` | `/courses/{course}/chapters/{chapter}/lessons/order` | كما أعلاه |

القائمة **كاملة ومرتّبة**، فالترتيب المكرّر غير قابل للتعبير عنه (R3). وناقص عنصر أو زائد
عنصر ليس من الإخوة ⇒ 422. و`structure_version` مختلف ⇒ **409** بحالة الشجرة الجديدة في الردّ
(`FR-009`).

### النشر

| الطريقة | المسار | الحمولة |
|---|---|---|
| `POST` | `/courses/{course}/tree/publish` | `{ structure_version, items: [{uuid, status}, …] }` |
| `GET` | `/courses/{course}/tree/publish-preview` | `?items[n][uuid]` و`?items[n][status]` — **اختيارية** |

`publish-preview` بلا `items` يُسعّر **كل ما حالته مسودّة**، ويعيد تلك القائمة في `items` — وهي
التي يُرسلها العميل إلى `/tree/publish`. الاشتقاق في الخادم لا في الواجهة عمداً: نسختان من
«أيّها مسودّة» تعنيان أن المعروض قد يصف دفعةً غير التي نُفِّذت. والصيغة الصريحة لازمة للاتجاه
الآخر — إلغاء النشر والأرشفة هما دفعتا `FR-053` و`FR-055`، ولا يصل إليهما «انشر كل المسودّات».

الردّ: `structure_version` · `items` · `added_items` · `removed_items` · `students_affected` ·
`largest_drop_pct` (سالب أو صفر) · `largest_gain_pct` · `resequenced[{uuid,title,unlocked_by}]`
(فارغة في كورس غير متسلسل) · `warnings[{code,message}]`.

عدد وطرفان لا كشف أسماء: `FR-049` يسأل عن **عدد** الطلاب، والعقد يضيف مقدار التغيّر.

وهو **نفس الحساب** الذي ينفّذه النشر لا تقديرٌ ثانٍ (`SC-018`): القائمة تُحلّ بـ`resolve()`
نفسها، والدفعة تُرفَض بـ`assertReady()` نفسها (فلا يَعِد بنشرٍ سيفشل بـ422)، والمقام نصفه
الثابت من `Lesson::progressEligible()`، والنسبة من `CourseProgress::percentage()`، ومَن
سيُحتسب له الاختبار من `ExamGateSatisfaction` — المُحاكى شيء واحد فقط: شروط الحالة الثلاثة،
وهي بالضبط ما يُغيّره النشر.

---

## ٢ — الرفع والمرفقات

| الطريقة | المسار | الصلاحية | ملاحظة |
|---|---|---|---|
| `POST` | `/lessons/{lesson}/assets` | `LESSONS_MANAGE` | قائم — يستقبل `kind` و`role` ويقبل المستند والصوت (R5) |
| `POST` | `/media/assets/{asset}/complete` | `LESSONS_MANAGE` | قائم — التحقّق بالمحتوى يقابل قائمة الصنف لا قائمة واحدة |
| `PUT` | `/media/assets/{asset}/disposition` | `LESSONS_MANAGE` | `{ is_downloadable }` — مفتاح العرض/التحميل (`FR-035`) |
| `DELETE` | `/media/assets/{asset}` | `LESSONS_DELETE` + `2fa.required` | قائم بلا تغيير — **وهو الباب الوحيد لإتلاف أصل `primary`** |
| `GET` | `/playback/{grant}/stream` | — (المنحة هي الحارس) | قائم — يقدّم المستند بـ`inline` أو `attachment` حسب `is_downloadable` |

> **`2fa.required` تبقى على حذف الأصل وحده، ولا تُضاف إلى حذف العنصر.** والقاعدة التي تجعل
> ذلك متماسكاً في الـAction: حذف عنصر يملك أصلاً **أساسياً** (`role = primary`) **مرفوض**
> (`FR-038أ`) — يُحذف الأصل أولاً من مساره المحمي، أو يُؤرشف العنصر. البديل (وضع
> `2fa.required` على حذف كل عنصر) يفرض تحقّقاً بخطوتين لحذف مسودّة فارغة، والبديل الثالث
> (تركها تسقط بالتبعية) باب خلفي على قرار أُحيط بالحماية عمداً منذ 004.
>
> **والمرفقات تتبع العنصر، بلا تحقّق ثنائي.** `TreeDeletionGuard` يسأل عن `role = primary`
> وحده، و`ManageLessons::delete` يمرّر كل مرفق عبر `DeleteMediaAsset` — فتذهب البايتات
> وتُلغى المنح. وهذا **هو المطلوب** (`FR-038`: «بلا ملف يتيم»)، والخطّ حيث هو لأن الأصل
> الأساسي **هو** العنصر: حذفه يترك درساً فارغاً بعنوان، فرفض الحذف يحمي شيئاً لا يُستعاد.
> والمرفق ملفٌّ **بجواره**؛ عنصر عليه ثلاث أوراق عمل يصير غير قابل للحذف إلا بثلاث تأكيدات
> ثنائية، فيتعلّم المدرّس أن يتجاوز التأكيد لا أن يقرأه.
>
> فالجملة الدقيقة: مسار `DELETE /media/assets/{asset}` هو الباب الوحيد إلى إتلاف أصل
> **أساسي**، ولا يزعم أكثر من ذلك.

**يُمنع** أي مسار عام دائم إلى مستند أو صوت أو مرفق (`FR-034` · `SC-010`).

---

## ٣ — محدّدات المعدّل

| المحدّد | يغطّي |
|---|---|
| `throttle:authoring` — **جديد ومسمّى** | كل كتابة تأليف: إنشاء · تعديل · ترتيب · نشر · حذف |
| `throttle:upload` القائم | تذاكر الرفع |

**يُمنع** `throttle:N,M` داخل تعريف مسار (`FR-061`): `ThrottleRequests` يفهرس الضيوف بـ
`domain|ip` بلا المسار في البصمة، فكل حدّ مضمَّن يتشارك عدّاداً واحداً ويسود أشدّها.

---

## ٤ — قائمة الحقول المصرّح بها للطالب

الشجرة الطلابية **يُمنع** أن تحمل أيّاً مما يلي، ويُفحص ذلك في `PublicExposureTest`
و`CourseTreeExposureTest`:

| ممنوع | لماذا |
|---|---|
| عنصر حالته `draft` أو `archived` — بأي حقل منه | `FR-025` — والتسريب هنا هو المحتوى نفسه قبل أن يُراد نشره |
| `structure_version` | تفصيل تزامن للمؤلّف |
| `provider` · `provider_asset_id` على أي أصل | قائم من 004 · `FR-011` هناك |
| `external_url` لعنصر غير منشور | نفس التسريب بصيغة أخرى |
| `reference_id` خاماً | معرّف تسلسلي — يُقدَّم uuid الكيان المُشار إليه |

---

## ٥ — رموز الردّ المتفَق عليها

| الرمز | متى |
|---|---|
| `409` | `structure_version` قديم — الردّ يحمل `structure_version` الجديد و`tree` الحالية ليعيد المحرّر بناء خريطته بلا قراءة ثانية |
| `422` | قائمة ترتيب ناقصة أو زائدة · حقل مطلوب لنوعه غائب عند النشر · `type = assignment` |
| `423` | حذف درس عليه تقدّم مسجَّل — مع الأرشفة بديلاً في جسم الردّ (`FR-007`) |
| `403` | خارج مساحة العمل، أو بلا `LESSONS_MANAGE` |
| `404` | معرّف لا يخصّ الكورس المذكور في المسار (`FR-059`) |

> رسالة `422` لنوع `assignment` تذكر 008 بالاسم. رفض بلا سبب مسمّى يُنتج تذكرة دعم
> (`FR-046`).
