<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compliance\Models\DataCategory;
use App\Shared\Support\ExpiryBehaviour;
use Illuminate\Database\Seeder;

/**
 * The catalogue — REFERENCE DATA, not fixtures (spec 013 · FR-004 · SC-002).
 *
 * ⚠️ AN EMPTY CATALOGUE MAKES EVERY ASSERTION IN THIS PHASE PASS VACUOUSLY. The
 * consent screen lists categories, the sweep iterates categories, and the schema
 * coverage test compares against categories — over zero rows all three are
 * green and none of them tested anything. It is therefore seeded in
 * `tests/Pest.php` before every Feature test, exactly as
 * `NotificationTemplateSeeder` and `GamificationCatalogSeeder` are, and for the
 * same reason each of those carries in its own docblock.
 *
 * ⚠️ `class_recording` IS SEEDED REQUIRED, AND THAT ROW IS THE WHOLE OF DECISION
 * Q4. There is no separate recording-consent entity: appearing in a class
 * recording — voice and image — is a REQUIRED category inside the one consent, so
 * a guardian who will not accept it does not enrol. Making it optional would
 * produce a class the teacher may not record because one seat withdrew.
 *
 * ⚠️ AND `retain_days` IS NULL WHEREVER THE ROW MUST OUTLIVE THE STUDENT. A
 * certificate is a credential, and a payment record is a legal obligation; a
 * duration on either is a sweep that destroys them on a schedule.
 */
class DataCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categories() as $category) {
            /*
            | `firstOrCreate` on the KEY alone. These rows are reference data at
            | birth and operator data ever after — an operator who shortened a
            | retention or reworded a purpose must not have it undone by the next
            | deploy. Precedent, in the same words: `CreditPackageSeeder`.
            */
            DataCategory::query()->firstOrCreate(['key' => $category['key']], $category);
        }
    }

    /** @return list<array<string, mixed>> */
    private function categories(): array
    {
        return [
            // ── Identity ────────────────────────────────────────────────────
            [
                'key' => 'student_name',
                'label_ar' => 'الاسم',
                'purpose_ar' => 'لمخاطبتك باسمك، ولطباعة اسمك على الشهادة، وليعرف مدرّسك من في صفّه.',
                'audience' => 'المدرّسون المسجَّل عندهم · ولي الأمر · إدارة المنصّة',
                'is_required' => true,
                'owning_module' => 'identity',
                'table_name' => 'users',
                'column_name' => 'first_name',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],
            [
                'key' => 'contact_phone',
                'label_ar' => 'رقم الهاتف',
                'purpose_ar' => 'لإرسال تنبيهات الحصص والتقارير إليك أو إلى وليّ أمرك، وللتحقّق من حسابك.',
                'audience' => 'إدارة المنصّة · مزوّد الرسائل',
                'is_required' => true,
                'owning_module' => 'identity',
                'table_name' => 'users',
                'column_name' => 'phone',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],
            [
                'key' => 'date_of_birth',
                'label_ar' => 'تاريخ الميلاد',
                'purpose_ar' => 'لمعرفة هل أنت دون الثامنة عشرة، فتُطلب موافقةُ وليّ أمرك — ولتنتقل إليك ملكيةُ بياناتك يوم تبلغ.',
                'audience' => 'إدارة المنصّة',
                'is_required' => true,
                'owning_module' => 'identity',
                'table_name' => 'student_profiles',
                'column_name' => 'date_of_birth',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],

            // ── Learning ────────────────────────────────────────────────────
            [
                'key' => 'enrollment_record',
                'label_ar' => 'سجلّ التسجيل في الكورسات',
                'purpose_ar' => 'ليعرف النظامُ ما يحقّ لك فتحُه، وليتابع مدرّسُك تقدّمك.',
                'audience' => 'المدرّس المسجَّل عنده · ولي الأمر',
                'is_required' => true,
                'owning_module' => 'learning',
                'table_name' => 'enrollments',
                'column_name' => 'student_user_id',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],
            [
                'key' => 'lesson_progress',
                'label_ar' => 'تقدّمك في الدروس',
                'purpose_ar' => 'لحفظ موضعِ توقّفك ولحساب نسبة إتمامك للكورس.',
                'audience' => 'المدرّس المسجَّل عنده · ولي الأمر',
                'is_required' => true,
                'owning_module' => 'learning',
                'table_name' => 'lesson_progress',
                'column_name' => 'enrollment_id',
                // Three years: long enough that a student returning after a gap
                // still sees where they stopped, short enough that a childhood of
                // click-by-click behaviour is not kept for ever.
                'retain_days' => 1095,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
            ],

            // ── Assessments ─────────────────────────────────────────────────
            [
                'key' => 'exam_attempt',
                'label_ar' => 'محاولاتك في الاختبارات',
                'purpose_ar' => 'لاحتساب درجتك، ولتعرف أنت ومدرّسك مواضعَ الضعف.',
                'audience' => 'المدرّس المسجَّل عنده · ولي الأمر إن كان مُخوَّلاً بالنتائج',
                'is_required' => true,
                'owning_module' => 'assessments',
                'table_name' => 'exam_attempts',
                'column_name' => 'student_user_id',
                'retain_days' => 1825,
                'expiry_behaviour' => ExpiryBehaviour::Anonymise->value,
            ],
            [
                'key' => 'exam_answer',
                'label_ar' => 'إجاباتك التفصيلية',
                'purpose_ar' => 'لتصحيح المحاولة، ولبناء دفتر أخطائك.',
                'audience' => 'المدرّس المسجَّل عنده',
                'is_required' => true,
                'owning_module' => 'assessments',
                'table_name' => 'exam_answers',
                'column_name' => 'student_user_id',
                'retain_days' => 1095,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
            ],

            // ── Certificates ────────────────────────────────────────────────
            [
                'key' => 'certificate',
                'label_ar' => 'شهاداتك',
                'purpose_ar' => 'إفادةٌ بما أتممتَه، يمكن لأيّ جهةٍ التحقّق منها برقمها.',
                'audience' => 'علنيّ لمن يحمل رمز التحقّق',
                'is_required' => true,
                'owning_module' => 'certificates',
                'table_name' => 'certificates',
                'column_name' => 'student_user_id',
                // ⚠️ NEVER EXPIRES, AND NEVER ERASED EITHER. A credential the
                // student earned, verifiable by its code — a duration here would
                // destroy it on a schedule, and nulling its owner would leave a
                // certificate that verifies as belonging to nobody.
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],

            // ── Payments ────────────────────────────────────────────────────
            [
                'key' => 'payment_record',
                'label_ar' => 'سجلّ المدفوعات',
                'purpose_ar' => 'إثباتُ ما دُفع ومقابلَ ماذا — ونحن ملزمون بحفظه.',
                'audience' => 'إدارة المنصّة · المدرّس المسجَّل عنده',
                'is_required' => true,
                'owning_module' => 'payments',
                'table_name' => 'orders',
                'column_name' => 'user_id',
                // ⚠️ A LEGAL OBLIGATION, so no sweep touches it. Erasure detaches
                // the subject from it instead — which is why `SC-007` can be
                // asserted at all.
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],

            // ── LiveSessions ────────────────────────────────────────────────
            [
                'key' => 'attendance_record',
                'label_ar' => 'حضورك وغيابك',
                'purpose_ar' => 'لتسجيل حضورك الحصص ومدّة بقائك، ولإخبار وليّ أمرك.',
                'audience' => 'المدرّس المسجَّل عنده · ولي الأمر إن كان مُخوَّلاً بالحضور',
                'is_required' => true,
                'owning_module' => 'livesessions',
                'table_name' => 'attendances',
                'column_name' => 'student_user_id',
                'retain_days' => 1095,
                'expiry_behaviour' => ExpiryBehaviour::Anonymise->value,
            ],

            // ── Media ───────────────────────────────────────────────────────
            [
                'key' => 'class_recording',
                'label_ar' => 'الظهور في تسجيلات الحصص (‏صوتاً وصورةً)',
                'purpose_ar' => 'تُسجَّل الحصص لتتمكّن أنت وزملاؤك من مراجعتها. قد يظهر صوتُك وصورتُك في التسجيل، ويصل إلى كلّ من حجز الحصة.',
                'audience' => 'من حجز الحصة · المدرّس · مزوّد الفيديو',
                // ⚠️ REQUIRED, AND THIS ROW IS ALL OF Q4. There is no second
                // consent entity for recordings: it is a required category inside
                // the one consent, so refusing it means not enrolling — rather
                // than a class the teacher may not record because one seat said no.
                'is_required' => true,
                'owning_module' => 'media',
                'table_name' => 'media_assets',
                'column_name' => 'owner_id',
                'retain_days' => 730,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
            ],

            // ── Notifications ───────────────────────────────────────────────
            [
                'key' => 'notification_record',
                'label_ar' => 'الإشعارات المرسَلة إليك',
                'purpose_ar' => 'لتقرأها في مركز الإشعارات، ولنعرف ما أُرسل ومتى.',
                'audience' => 'أنت · ولي الأمر بحسب تخويله',
                'is_required' => true,
                'owning_module' => 'notifications',
                'table_name' => 'notifications',
                'column_name' => 'recipient_user_id',
                'retain_days' => 180,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
            ],

            // ── Marketplace ─────────────────────────────────────────────────
            [
                'key' => 'review',
                'label_ar' => 'تقييماتك للمدرّسين',
                'purpose_ar' => 'لتساعد غيرَك على الاختيار. يظهر باسمٍ مختصر.',
                'audience' => 'علنيّ',
                'is_required' => false,
                'owning_module' => 'marketplace',
                'table_name' => 'reviews',
                'column_name' => 'student_id',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],

            // ── Settlement ──────────────────────────────────────────────────
            [
                'key' => 'teacher_earnings',
                'label_ar' => 'سجلّ أجر المدرّس',
                'purpose_ar' => 'لحساب مستحقّات المدرّس وصرفها. لا يخصّ الطلاب.',
                'audience' => 'المدرّس · إدارة المنصّة',
                'is_required' => true,
                'owning_module' => 'settlement',
                'table_name' => 'ledger_entries',
                'column_name' => 'teacher_profile_id',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],

            // ── Courses ─────────────────────────────────────────────────────
            [
                'key' => 'authored_content',
                'label_ar' => 'المحتوى الذي ألّفته',
                'purpose_ar' => 'كورساتُك ودروسُك. يخصّ المدرّسين، ويُسلَّم لهم نسخةً عند الخروج.',
                'audience' => 'المدرّس · طلابه المسجَّلون',
                'is_required' => true,
                'owning_module' => 'courses',
                'table_name' => 'courses',
                'column_name' => 'created_by',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],

            // ── Tenancy ─────────────────────────────────────────────────────
            [
                'key' => 'workspace_invitation',
                'label_ar' => 'دعوات الانضمام',
                'purpose_ar' => 'بريدُ من دُعي للانضمام إلى مساحة عمل، حتى يقبل الدعوة أو تنتهي.',
                'audience' => 'صاحب مساحة العمل · إدارة المنصّة',
                'is_required' => true,
                'owning_module' => 'tenancy',
                'table_name' => 'invitations',
                'column_name' => 'email',
                // A bare email address with no account behind it. Ninety days is
                // well past any invitation's own expiry.
                'retain_days' => 90,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
            ],

            // ── CMS ─────────────────────────────────────────────────────────
            [
                'key' => 'cms_authorship',
                'label_ar' => 'ما نشرتَه من مقالات',
                'purpose_ar' => 'مقالاتُ المدوّنة ومن كتبها.',
                'audience' => 'علنيّ',
                'is_required' => false,
                'owning_module' => 'cms',
                'table_name' => 'cms_articles',
                'column_name' => 'author_id',
                'retain_days' => null,
                'expiry_behaviour' => null,
            ],
        ];
    }
}
