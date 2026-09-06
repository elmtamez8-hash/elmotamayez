# API Contracts — ٠٢٨ · شهادةٌ تُرى

كلُّ ردٍّ عبرَ API Resource، وكلُّ معرَّفٍ ظاهرٍ **uuid** لا رقماً تسلسليّاً (المبدأ السادس).

---

## أ · المسارُ العامّ — يُوسَّع

### `GET /api/v1/certificates/verify/{code}` · بلا استيثاق · `throttle:public`

```jsonc
{
  "valid": true,
  "certificate": {
    "certificate_number": "CERT-2026-A1B2C3D4",
    "verification_code": "8DBgEOCM…",
    "issue_reason": "course_completed",
    "issued_at": "2026-05-14T10:22:00Z",   // الإصدارُ الأوّل، لا يتحرّك
    "course_title": "أساسيات الجبر",
    "student_name": "كريم محمود",           // مجمَّد
    "teacher_name": "سامي عبد الله",        // ✚ مجمَّد — FR-007 · FR-031
    "subject_name": "الرياضيات",            // ✚ مجمَّد (المادّة، وإلّا عنوانُ الكورس)
    "design": {                              // ✚ يُقرَأُ حيّاً — FR-009
      "image_url": "/certificate-templates/classic.webp",
      "boxes": {
        "student": { "x": 0.24, "y": 0.40, "w": 0.52, "h": 0.09,
                     "align": "center", "max_font": 0.048, "min_font": 0.022,
                     "color": "secondary-ink" },
        "subject": { "…": "…" }, "teacher": { "…": "…" },
        "date":    { "…": "…" }, "number":  { "…": "…" },
        "qr":      { "x": 0.71, "y": 0.70, "w": 0.07, "h": 0.10 }
      }
    }
  }
}
```

**غيرُ الصالح**: `404` + `{ "valid": false }` — **بلا اسمٍ ولا رقمٍ ولا `design`**. لا صورةَ
قالبٍ تُحمَّلُ لرمزٍ خاطئ (`FR-034`).

⚠️ **`FR-032`**: لا حقلَ في هذه الحمولةِ خارجَ ما سُمّي أعلاه. لا بريدَ مدرّسٍ ولا معرّفَ
مساحةِ عملٍ ولا رابطَ مِلَفّ.

⚠️ **القراءةُ الداخليّةُ للتصميمِ** تمرُّ بـ`withoutWorkspaceScope()` + `where('workspace_id',
$certificate->workspace_id)`. السببُ والاختبارُ في `data-model.md` §٥.

---

## ب · معرضُ المدرّس — جديد

الحارسُ في الأربعةِ: `Permissions::CERTIFICATES_REGENERATE` (`research.md` ق-١٢) عبرَ سياسةٍ في
`Certificates/Policies/`، ومرفوضٌ **عندَ البابِ** لا على الشاشةِ (`FR-033`).

### `GET /api/v1/certificate-designs`

قائمةٌ واحدةٌ تجمعُ المشحونَ والمرفوع، **بشكلٍ واحدٍ لا تتفرّعُ عليه الواجهة**:

```jsonc
{
  "data": [
    { "uuid": null, "system_key": "classic", "name": "كلاسيكي",
      "image_url": "/certificate-templates/classic.webp",
      "source": "system", "is_selected": false, "is_ready": true, "boxes": { "…": "…" } },

    { "uuid": "6f1c…", "system_key": "students", "name": "طلّاب",
      "image_url": "/certificate-templates/students.webp",
      "source": "system", "is_selected": true,  "is_ready": true, "boxes": { "…": "…" } },

    { "uuid": "b93a…", "system_key": null, "name": "تصميم المركز",
      "image_url": "/storage/certificate-designs/b93a….webp",
      "source": "uploaded", "is_selected": false, "is_ready": false, "boxes": null }
  ],
  "upload_limit": 5,
  "uploads_used": 1
}
```

- `uuid: null` = قالبٌ مشحونٌ **لم تتبنَّه** مساحةُ العملِ بعد؛ يُتبنّى بـ`POST`.
- `is_ready: false` ⇒ مرفوعٌ بلا مواضع؛ **لا يُختار** (`FR-044`).

### `POST /api/v1/certificate-designs`

حالتان، وواحدةٌ منهما بالضبط:

| الحمولة | الأثر |
|---|---|
| `{ "system_key": "classic" }` | يُنشَأُ الصفُّ **ويُختارُ في الفعلِ نفسِه** |
| `multipart` · `image` + `name` | يُرفَعُ ويُعادُ ترميزُه؛ **لا يُختارُ** (بلا مواضعَ بعد) |

- `422` إن أُرسِلَ الاثنانِ أو لم يُرسَلْ أيٌّ منهما.
- `422` للصيغةِ أو الحجمِ — **برسالةٍ تقولُ الحدَّ والصيغَ المقبولة** (`FR-043`).
- `422` عندَ بلوغِ حدِّ الرفعِ (`FR-046`) — والرسالةُ تقولُ العددَ وتقترحُ الحذف.
- ⚠️ **إعادةُ الترميزُ على الخادمِ قبلَ الحفظ** (`FR-042` · `research.md` ق-٨). `Gd` صراحةً،
  بلا `Fit::Crop` (القصُّ يقطعُ الزخرفة) وبلا `optimize()`.
- `201` بالصفِّ الجديدِ بالشكلِ أعلاه.

### `PATCH /api/v1/certificate-designs/{design}`

```jsonc
{ "boxes": { "student": { "…": "…" }, "…": "…" },   // اختياريّ
  "is_selected": true }                              // اختياريّ
```

- `boxes`: **المفاتيحُ الستّةُ كاملةً**، لا تعديلاً جزئيّاً — بنيةٌ نصفُ مكتوبةٍ تضعُ حقلاً حيثُ
  لا يُرى. المدياتُ والحدودُ في `data-model.md` §٣، **مفروضةً في الإجراء**.
- `boxes: null` على صفٍّ **متبنّىً** = «أعِدْ إلى مواضعِ السجلّ» (`FR-021`). وعلى **مرفوعٍ**
  ⇒ `422`.
- `is_selected: true` على صفٍّ `is_ready: false` ⇒ **`422` بسببٍ مذكور** (`FR-044`).
- التبديلُ **معاملةٌ واحدة**: إفراغُ `selected_for_workspace_id` من القديمِ ثمَّ كتابتُه هنا.

### `DELETE /api/v1/certificate-designs/{design}`

- `204`. ويقعُ المِلَفُّ على الافتراضيِّ إن كانَ المحذوفُ هو المختار.
- **الشهاداتُ الصادرةُ لا تُكسَر** (`FR-017` · `FR-045`) — لأنّها لا تخزّنُ التصميمَ أصلاً.
- الصورةُ المرفوعةُ تُحذَفُ من القرصِ فعلاً (إملاءُ `SaveAccountPhoto`: صورةٌ لا يشيرُ إليها
  شيءٌ تخزينٌ لا يُقرَأُ أبداً).

---

## ج · ما يُحذَفُ من العقدِ الظاهر

```
DELETE  GET    /api/v1/certificate-templates
DELETE  POST   /api/v1/certificate-templates
DELETE  GET    /api/v1/certificate-templates/{template}
DELETE  PUT    /api/v1/certificate-templates/{template}
DELETE  DELETE /api/v1/certificate-templates/{template}
```

⚠️ **حذفٌ في مكانِه لا إصدارٌ جديد**: المبدأُ السادسُ يوجبُ الإصدارَ لتغييرٍ كاسرٍ **لعميلٍ
قائم**، والمقيسُ أنّ **لا ملفَّ في `frontend/src` ينادي أيّاً منها**. مُوثَّقٌ هنا وفي وصفِ الـPR،
ويُحدَّثُ `docs/README.md` في التغييرِ نفسِه.

---

## د · إصلاحُ شكلِ التصفّح

`GET /api/v1/certificates` يردُّ `->response()->getData(true)` بدلَ
`response()->json(Resource::collection(...))` — فيعودُ `meta` و`links` بعدَ أن كانا يُسقَطانِ
بصمت.

**الأثر**: `data` يبقى في أعلى المستوى فلا يتغيّرُ شيءٌ عندَ المستدعينَ الأربعةِ المفحوصين؛
المضافُ هو `meta`. **وبه يظهرُ زرُّ «عرض المزيد»** في `manage/certificates` لأوّلِ مرّة
(`FR-038`). وتُراجَعُ توكيداتُ `CertificateListingTest`.

---

## هـ · عقدُ الواجهةِ الداخليّ

`CertificateArtwork` — مكوّنٌ واحدٌ يرسمُ الشهادةَ من `{ image_url, boxes, values }`،
يستعملُه صفحةُ التحقّقِ **ومعاينةُ المعرضِ ومحرّرُ المواضع**. إملاءٌ واحدٌ للرسمِ في ثلاثةِ
مواضعَ — وإلّا اختلفتِ المعاينةُ عن الشهادة، وهو أسوأُ ما يمكنُ لمحرّرِ مواضعَ أن يفعلَه.

⚠️ **`<img>` عاديٌّ لا `next/image`** (`research.md` ق-١٤).
⚠️ **مُلاءمةُ النصِّ تُقاسُ بعدَ الرسم**، وتسري على الحقولِ النصّيّةِ الخمسةِ لا على الاسمِ
وحدَه (`FR-026`).
