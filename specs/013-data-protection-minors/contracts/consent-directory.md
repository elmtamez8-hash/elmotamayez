# Contract: `ConsentDirectory` — العقدُ نحو الموافقةِ القائمة

**السبيك**: [`../spec.md`](../spec.md) `FR-003` · `FR-005` · `FR-006` · `FR-007` · `FR-008`
**البحث**: [`../research.md`](../research.md) §R1 · **المراجعة**: [`../review-findings.md`](../review-findings.md)

الموافقةُ **مبنيّةٌ وشغّالةٌ منذ 006**، والمبدأُ III يمنع الوصولَ إلى نماذج وحدةٍ أخرى مباشرةً —
فالحلُّ عقدٌ في `App\Shared\Contracts\` على غرار `EnrollmentDirectory` و`GuardianDirectory`.

---

## ما هو قائمٌ بالفعل

| الشيء | ماذا يعطي |
|---|---|
| `Payments\Models\TermsConsent` | `user_id` (المُوقِّع) · `student_user_id` (المعنيّ) · `document` · `version` · `ip_address` · `user_agent` · `consented_at` |
| `Payments\Enums\ConsentDocument` | حالتان، و`DataProcessing` **منهما بالفعل** بتعليقٍ يقول إن نصَّها وقواعدَ محوِها تخصّ سبيك 013 |
| `Payments\Support\ConsentRegistry` | `currentVersion()` · `has()` · `everAccepted()` · `forStudentIds()` |
| `Payments\Actions\RecordTermsConsent` | **الكاتبُ الوحيد**، ويسأل الوصايةَ عبر `GuardianDirectory` |
| `Payments\Http\Controllers\TermsConsentController` | **`index` و`store` يخدمان الوثيقتَين معاً** بما فيها `data_processing` |
| `config/consents.php` | مفتاحُ `consents.versions.data_processing` **موجودٌ سلفاً** ويقرؤه `currentVersion()` |

**ومتطلَّبان مُنفَّذان ومختبَران قبل أن تبدأ هذه المرحلة**:

- **`FR-006`** — النسخةُ **جزءٌ من السؤال** لا عمودٌ يُقارَن لاحقاً، فنشرُ نسخةٍ جديدةٍ يُسقط كلَّ
  موافقةٍ سابقةٍ في الطلب التالي **بلا كنسِ صفٍّ واحد**. مُشتقٌّ لا مخزَّن.
- **`FR-008`** — لا تُغني موافقةٌ عن أخرى، **والحارسُ أن الوثيقةَ وسيطٌ بلا قيمةٍ افتراضية**:
  «وسيطٌ له قيمةٌ افتراضيةٌ هو حُجّةٌ منسيّةٌ واحدةٌ بعيدةٌ عن جعلهما الشيءَ نفسَه».

---

## ⚠️ وما ليس منفَّذاً — وقولُ العكسِ كان خطأً في ثلاثة ملفّات

**«`FR-005` حرفياً» خطأ.** المتطلَّبُ يطلب **خمسةَ** أشياء، خامسُها **الأصنافُ الموافَق عليها**، ولا
عمودَ أصنافٍ في الجدول بأيّ شكل. فتحقيقُه **جزئيّ**: مَن ومتى ومن أين وبأيّ نسخةٍ — **لا على
ماذا**.

**والأثقلُ ما كشفه الخطأ**: `FR-007` — سحبُ الموافقة على صنفٍ اختياريٍّ يوقف معالجتَه فوراً — كان
**بلا جدولٍ ولا عمودٍ ولا Action ولا نقطةِ نهايةٍ ولا اختبار**. و`data_categories.is_required` يصف
الكتالوجَ لا اختيارَ شخص.

فتُضاف **`categories` json nullable** إلى `terms_consents`، **بهجرةٍ في
`Payments/Database/Migrations/`** لأن الجدولَ لها. و`null` ≠ `[]`: الأولى «وثيقةٌ بلا أصناف»
(‏صفوفُ شروطِ الدفع القائمة)، والثانية «وافق على لا شيء».

**ويُضاف `unique(student_user_id, document, version, consented_at)`** — الجدولُ اليومَ فهارسُ
عاديةٌ فقط، فضغطتان تكتبان صفَّين ويُطلَق الحدثُ مرّتين فيُفعَّل حسابٌ مرّتين ويُشعَر وليٌّ مرّتين.

---

## الواجهة

`App\Shared\Contracts\ConsentDirectory`

| الدالة | الإرجاع |
|---|---|
| `currentVersion(string $document)` | `string` |
| `hasCurrent(User $subject, string $document)` | `bool` — **معناها لا يتغيّر** |
| `everAccepted(User $subject, string $document)` | `bool` |
| **`consentedCategories(User $subject, string $document)`** | `?array` — أصنافُ **أحدثِ** صفٍّ للنسخة السارية |
| `record(User $signer, User $subject, string $document, array $categories, string $ip, ?string $ua)` | `void` |

**و`hasCurrent()` معناها ثابتٌ عن قصد**: «هل وقّع النسخةَ السارية» — وهو ما تعتمد عليه الفوترةُ في
سقفِ الائتمان، **ولا يجوز أن ينقلب معناه** إلى «هل ما زال موافقاً على كلّ صنف». `FR-007` يُقرأ
بـ`consentedCategories()` وحدها.

**والوثيقةُ سلسلةٌ نصّيةٌ لا enum**، لأن الـenum يعيش في `Payments` وعقدٌ في `Shared` يستورده يعيد
الاقترانَ الذي وُجد ليقطعه. **ولا قيمةَ افتراضيةً للوسيط** — نفسُ حراسةِ الـenum القائمة.

## ⚠️ الربطُ: `EloquentConsentDirectory` لا `ConsentRegistry` مباشرةً

نسخةٌ أولى قالت «تُربَط بـ`ConsentRegistry`» و«صفر تعديلٍ في Payments». **الاثنان لا يصحّان معاً**:
`ConsentRegistry` **بلا `record()`** — قرأتُ الصنفَ كلَّه — والكاتبُ الوحيد هو الـAction
`RecordTermsConsent`.

فالربطُ إلى **`Payments\Support\EloquentConsentDirectory`** يفوّض القراءةَ إلى `ConsentRegistry`
والكتابةَ إلى `RecordTermsConsent` — مطابقاً `EloquentEnrollmentDirectory` و
`EloquentApprovedRateDirectory` و`EloquentGuardianDirectory` المشحونة.

**فادّعاءُ «صفر تعديلٍ في Payments» يسقط صراحةً**: هجرةُ العمود · `consentedCategories()` ·
`EloquentConsentDirectory` · ووسيطُ `$categories` على الـAction.

---

## ⚠️ حالةٌ سادسةٌ في `GuardianPermission`

الخمسُ القائمةُ: `Attendance` · `Payments` · `Schedule` · `Results` · `AcademicWarnings` — **ولا
واحدةَ عن حقوق البيانات**. و`RecordTermsConsent::maySignFor()` يسأل
`isAuthorised($signer, $student, GuardianPermission::Payments)`.

**فاليومَ لا يوقّع وليٌّ موافقةَ معالجةِ بياناتِ ابنه إلا إن كان يحمل صلاحيةَ «المدفوعات»** —
اقترانٌ لا معنى له، ويجعل قاعدةَ `R6` («الوصيُّ المُخوَّل يمنح، وغيرُ المُخوَّل لا») **غيرَ قابلةٍ
للتعبير**.

تُضاف **`DataRights`** بتسميتها العربية، ويُسأل بها للوثيقة `data_processing` ولطلبات الحقوق.
**وهو تعديلٌ في `App\Shared\Support\`** — تعديلٌ لم تكن الخطةُ تعلنه.

---

## ⚠️ و«الرفضُ يغلب» يحتاج مخزناً، لا قاعدةً في نصّ

`R6` قال: عند تعارضِ وصيَّين مُخوَّلَين **الرفضُ يغلب**. **والجدولُ يسجّل موافقةً فقط** — فمع وليٍّ
موافقٍ وآخرَ رافضٍ، `hasCurrent()` يجد صفَّ الموافق ويُرجع `true`: **الرفضُ لا يغلب، والأسبقُ
إدراجاً يفوز.**

فيُضاف عمودُ **`decision`** إلى `terms_consents`: enum `granted` · `refused`، افتراضُه `granted`
لكلّ صفٍّ قائم. و`hasCurrent()` تصير: **يوجد صفُّ `granted` ولا يوجد صفُّ `refused` أحدثُ منه لأيّ
مُوقِّعٍ مُخوَّل.**

**والرفضُ صفٌّ لا حذفٌ** لنفس سبب أن السحبَ صفٌّ: الجدولُ سجلُّ قراراتٍ في لحظاتٍ، ومحوُ أحدها
يمحو الدليلَ على ما وقع. **ولا يُكشَف من رفض** في أيّ إشعار — الطرفان قد يكونان في خلافِ حضانة،
ورسالةٌ تقول «أمُّك رفضت» بيانٌ شخصيٌّ عن ثالثٍ في إشعارٍ آليّ.

---

## ما تُضيفه 013 فعلاً

1. عمودا `categories` و`decision` + المفتاحُ الفريد — هجرةٌ في Payments.
2. `consentedCategories()` على `ConsentRegistry`، و`EloquentConsentDirectory`، ووسيطُ `$categories`
   و`$decision` على `RecordTermsConsent`.
3. `GuardianPermission::DataRights`.
4. نصُّ السياسةِ ملفَّ Markdown يمرّ بـ`MarkdownRenderer` القائم — **يُجرِّد HTML الخام** فقائمةُ
   المسموح هي مجموعةُ ميزات Markdown ولا مُنظِّفَ يُضبَط خطأً.
5. مسارُ سحبٍ/تعديلِ أصناف: `PUT /privacy/consents/categories` **بالمجموعة الكاملة** والنسخةِ
   المقروءة — فرقٌ مُطبَّقٌ على حالةٍ قُرئت قبل ثانيةٍ هو التحديثُ المفقود، ونفسُ صيغةِ إعادةِ
   الترتيب في 016.
6. `ActivateStudentAccount` في **Identity** تسأل `hasCurrent()` وترفض بدونها.

**وما لا تُضيفه**: ~~`RecordProcessingConsent`~~ و~~`POST/GET /privacy/consents`~~ — **مقطوعان**،
`TermsConsentController` يخدمهما اليوم. و~~صفُّ `consents.versions.data_processing`~~ — **موجودٌ
سلفاً** في `config/consents.php`.

---

## ⚠️ بندُ نشرٍ يخرج من هذا العقد

هجرةُ `terms_consents` تحمل هذا، وهو شرطُ صحّةٍ لعمودٍ في سجلٍّ قانونيّ لا ملاحظة:

> `TrustProxies` **يجب** أن يُضبَط قبل أن يعني هذا العمودُ شيئاً: بلا ضبطٍ يُرجع `$request->ip()`
> عنوانَ موازِن الحمل في الإنتاج — **العنوانَ نفسَه للجميع**، في الصفّ الذي يوجد ليُعتمَد عليه في
> نزاع.

وسجلُّ موافقةٍ بعنوانٍ واحدٍ لكلّ الناس ليس ناقصاً بل **مُضلِّلاً**: يبدو كاملاً ويُقرأ في محكمةٍ
على أنه دليل. يدخل `docs/deployment.md` بنداً قبل الإطلاق.
