# Contract: `ConsentDirectory` — العقدُ نحو الموافقةِ القائمة

**السبيك**: [`../spec.md`](../spec.md) `FR-003` · `FR-005` · `FR-006` · `FR-008`
**البحث**: [`../research.md`](../research.md) §R1

الموافقةُ **مبنيّةٌ وشغّالةٌ منذ 006** في `Modules/Payments/`، و`Compliance` تحتاجها. والمبدأُ III
يمنع الوصولَ إلى نماذج وحدةٍ أخرى مباشرةً — فالحلُّ عقدٌ في `App\Shared\Contracts\` على غرار
`App\Shared\Contracts\EnrollmentDirectory` القائم.

**صفر نقلِ ملفٍّ وصفر هجرة.**

---

## ما هو قائمٌ بالفعل — ولا يُكتب من جديد

| الشيء | المكان | ماذا يعطي |
|---|---|---|
| `TermsConsent` | `Payments\Models\` | `user_id` (‏المُوقِّع) · `student_user_id` (‏المعنيّ) · `document` · `version` · `ip_address` · `user_agent` · `consented_at` — أي **`FR-005` حرفياً** |
| `ConsentDocument` | `Payments\Enums\` | حالتان، و`DataProcessing` **منهما بالفعل** بتعليقٍ يقول إن نصَّها وقواعدَ محوِها تخصّ سبيك 013 |
| `ConsentRegistry` | `Payments\Support\` | `currentVersion()` من `platform_settings` · `has()` (‏نسخةٌ سارية) · `everAccepted()` (‏أيُّ نسخة) · `forStudentIds()` (‏دفعةٌ في استعلام) |

**وثلاثةُ متطلَّباتٍ مُنفَّذةٌ ومختبَرةٌ قبل أن تبدأ هذه المرحلة**:

- **`FR-006`** — النسخةُ **جزءٌ من السؤال** لا عمودٌ يُقارَن لاحقاً، فنشرُ نسخةٍ جديدةٍ يُسقط كلَّ
  موافقةٍ سابقةٍ في الطلب التالي **بلا كنسِ صفٍّ واحد**. مُشتقٌّ لا مخزَّن.
- **`FR-008`** — لا تُغني موافقةٌ عن أخرى، **والحارسُ أن الوثيقةَ وسيطٌ بلا قيمةٍ افتراضية**.
  بنصّ تعليقِ الـenum: «وسيطٌ له قيمةٌ افتراضيةٌ هو حُجّةٌ منسيّةٌ واحدةٌ بعيدةٌ عن جعلهما
  الشيءَ نفسَه».
- **`FR-017`** — المُوقِّعُ منفصلٌ عن المعنيّ، وبلا إثباتِ الرابط أيُّ مستخدمٍ يوقّع باسم غيره
  والردُّ يؤكّد أن المعرّف لشخصٍ حقيقيّ.

---

## الواجهة

`App\Shared\Contracts\ConsentDirectory` — تُربَط بـ`Payments\Support\ConsentRegistry`.

| الدالة | الإرجاع |
|---|---|
| `currentVersion(string $document)` | `string` |
| `hasCurrent(User $subject, string $document)` | `bool` |
| `everAccepted(User $subject, string $document)` | `bool` |
| `record(User $signer, User $subject, string $document, string $ip, ?string $userAgent)` | `void` |

**الوثيقةُ سلسلةٌ نصّيةٌ في العقد، لا enum** — لأن الـenum يعيش في `Payments` وعقدٌ في `Shared`
يستورده يعيد الاقتران الذي يوجد ليقطعه. القيمُ المقبولةُ تُتحقَّق عند الحدّ.

**ولا قيمةَ افتراضيةً للوسيط** — نفسُ حراسةِ الـenum القائمة، بنفس السبب.

---

## ما تُضيفه 013 فعلاً

1. صفٌّ في `platform_settings`: `consents.versions.data_processing`.
2. نصُّ السياسةِ ملفَّ Markdown يمرّ بـ`MarkdownRenderer` القائم من 016 — **يُجرِّد HTML الخام
   بدل أن يهرّبه**، فقائمةُ المسموح هي مجموعةُ ميزات Markdown نفسُها ولا مُنظِّفَ يُضبَط خطأً.
3. `RecordProcessingConsent` تنادي `ConsentDirectory::record()` وتُطلق `ProcessingConsentGranted`.
4. `ActivateStudentAccount` تسأل `hasCurrent()` وترفض بدونها (`FR-003`).

**صفر تعديلٍ في `Payments`** خارج سطرِ الربط في مزوّدها.

---

## ⚠️ بندُ نشرٍ يخرج من هذا العقد

هجرةُ `terms_consents` تحمل هذا التعليق، وهو ليس ملاحظةً بل شرطُ صحّةٍ لعمودٍ في سجلٍّ قانونيّ:

> `TrustProxies` **يجب** أن يُضبَط قبل أن يعني هذا العمودُ شيئاً: بلا ضبطٍ يُرجع
> `$request->ip()` عنوانَ موازِن الحمل في الإنتاج — **العنوانَ نفسَه للجميع**، في الصفّ الذي
> يوجد ليُعتمَد عليه في نزاع.

وسجلُّ موافقةٍ بعنوانٍ واحدٍ لكلّ الناس ليس سجلّاً ناقصاً بل سجلٌّ **مُضلِّل**: يبدو كاملاً
ويُقرأ في محكمةٍ على أنه دليل. يدخل `docs/deployment.md` بنداً في قائمةِ ما قبل الإطلاق.
