# Contract: نقاطُ النهاية

كلُّ مسارٍ تحت `/api/v1` بمصادقةِ Sanctum، ويكشف `uuid` وحده. وكلُّ استجابةٍ عبر Resource.
والمُحدِّدُ **مُسمًّى** — `throttle:data-rights` — لأن `throttle:N,M` مضمَّناً ممنوعٌ: الضيوفُ
يُفتَرسون على `domain|ip` بلا مسارٍ في التجزئة، فيتشارك كلُّ حدٍّ مضمَّنٍ عدّاداً واحداً ويفوز
الأشدُّ (‏تصفّحُ السوق كان يُقفل الزائرَ خارج تسجيل الدخول).

---

## صاحبُ البيان ووليُّه

| الفعل | المسار | ملاحظة |
|---|---|---|
| `GET` | `/privacy/categories` | كتالوجُ الأصناف — اللازمُ مُميَّزٌ عن الاختياريّ (`FR-004`) |
| `GET` | `/privacy/policy` | النسخةُ السارية ونصُّها المُصيَّر من Markdown |
| `GET` | `/privacy/consents` | ما وقّعه هذا الشخص وبأيّ نسخة |
| `POST` | `/privacy/consents` | `RecordProcessingConsent` · `throttle:data-rights` |
| `GET` | `/privacy/requests` | طلباتُه |
| `POST` | `/privacy/requests` | `access` · `export` · `erasure`. `429` عند تجاوز حدٍّ معلَن (`FR-025`) |
| `GET` | `/privacy/requests/{request}/download` | يُعيد **`302`** إلى رابطٍ موقّعٍ قصيرِ المدة (`FR-018`) |
| `GET` | `/privacy/processors` | من يصله بيانٌ وحدُّ ما يمكن حذفُه لديه (`FR-024`) |

⚠️ **`/download` لا يُرجع مساراً في الحمولة إطلاقاً** — نفسُ قاعدةِ `PlaybackGrantResource` في 019
التي تُرسل مسارَنا لا عنوانَ المزوّد. مسارٌ في JSON هو رابطٌ يُنسَخ ويُلصَق ويبقى، وهذا الملفُّ
كلُّ ما تعرفه المنصةُ عن قاصرٍ في مكانٍ واحد.

---

## موظّفُ المنصة

| الفعل | المسار | الصلاحية **المنصّية** |
|---|---|---|
| `GET` | `/compliance/requests` | `compliance.requests.execute` |
| `POST` | `/compliance/requests/{request}/execute` | `compliance.requests.execute` |
| `POST` | `/compliance/requests/{request}/refuse` | نفسُها · بسببٍ إلزاميّ |
| `POST` · `DELETE` | `/compliance/holds` · `/compliance/holds/{hold}` | `compliance.holds.manage` |
| `PUT` | `/compliance/categories/{category}` · `/compliance/retention/{rule}` · `/compliance/processors/{processor}` | `compliance.registry.manage` |
| `POST` | `/compliance/offboardings` · `/compliance/offboardings/{offboarding}/execute` | `compliance.offboarding.execute` |

**الأربعُ منصّيّةٌ لا مستأجرة، والحارسُ على النموذج لا على الشاشة**: `Tenancy\Models\Role` يرفض
`givePermissionTo()` لصلاحيةٍ منصّيةٍ على دورٍ يحمل `team_id`، والمجموعةُ المنصّيةُ **مُشتقّة** —
الكلُّ ناقصَ ما تحمله أدوارُ المستأجر. فالأربعُ محميّةٌ **بمجرّد تعريفها**، ولا شيءَ يُضاف لحمايتها.

**وكلُّ قراءةٍ منصّيةٍ هنا تُعلن `withoutWorkspaceScope()`** — و`WorkspaceContext::id()` يرتدّ إلى
`users.last_workspace_id` **لكلّ مستخدمٍ بمن فيهم مديرُ المنصة**، فقراءةٌ متروكةٌ في النطاق تُظهر
طلباتِ مساحةِ عملٍ واحدةٍ على أنها طلباتُ المنصة — **وتنجح في اختبارِها على تجربةٍ بمساحةِ عملٍ
واحدة**. والتجاوزُ **لكلّ نموذجٍ على حِدة**: `->with('subject')` يشغّل النطاقَ العامَّ داخل
استعلام العلاقة. أيُّ اختبارٍ لقراءةٍ منصّيةٍ يحتاج **مساحتَي عملٍ** أو لا يُثبت شيئاً.

---

## الأخطاء

| الحالة | الرمز | المتطلَّب |
|---|---|---|
| حسابُ قاصرٍ بلا موافقةِ وليّ | `422` `code: consent_required` | `FR-003` |
| طلبٌ باسم طالبٍ ليس ابنَه | `403` | `FR-017` · `NFR-001أ` |
| حذفٌ على شخصٍ عليه تعليق | `409` `code: legal_hold` | `FR-030` |
| خروجُ مدرّسٍ قبل حسمِ مستحقاته | `409` `code: settlement_pending` | `FR-032` |
| تجاوزُ حدّ الطلبات | `429` | `FR-025` |

**ولا خطأٍ خامٍ يصل المستخدم**: `422` عبر `fieldErrors()` تحت حقله، وما سواه عبر `userMessage()`.
وكلُّ حقلٍ في كلّ `FormRequest` جديدٍ يحتاج مُدخلاً في `attributes` داخل `backend/lang/ar/` أو
يُصيَّر `subject_user_uuid` على الشاشة.
