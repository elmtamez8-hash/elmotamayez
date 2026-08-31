<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compliance\Models\DataCategory;
use App\Shared\Support\ErasureMode;
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
        foreach (self::categories() as $category) {
            /*
            | `firstOrCreate` on the KEY alone. These rows are reference data at
            | birth and operator data ever after — an operator who shortened a
            | retention or reworded a purpose must not have it undone by the next
            | deploy. Precedent, in the same words: `CreditPackageSeeder`.
            */
            DataCategory::query()->firstOrCreate(['key' => $category['key']], $category);
        }
    }

    /**
     * The shipped catalogue.
     *
     * ⚠️ PUBLIC AND STATIC BECAUSE THE `erasure_mode` MIGRATION READS IT, and the
     * alternative was a second copy of seventeen keys inside `Compliance`. Two
     * lists answering one question diverge at the first category anybody adds —
     * and the copy would additionally name `lesson_progress`, `exam_answer` and
     * every other module's table from inside `Compliance`, which is precisely
     * what `ContextIsolationTest` fails the build over.
     *
     * @return list<array<string, mixed>>
     */
    public static function categories(): array
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
                'erasure_mode' => ErasureMode::Anonymise,
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
                'erasure_mode' => ErasureMode::Anonymise,
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
                'erasure_mode' => ErasureMode::Anonymise,
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
                'erasure_mode' => ErasureMode::Delete,
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
                'erasure_mode' => ErasureMode::Delete,
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
                /*
                | ⚠️ `Delete`, AND IT SHIPPED AS `Anonymise` UNTIL THE SWEEP WAS
                | WRITTEN AGAINST THE ACTUAL SCHEMA. `exam_attempts.student_user_id`
                | is NOT NULL, so anonymising a row here means either a `->change()`
                | on a table carrying eight indexes — which re-declares the column
                | and REBUILDS THE TABLE on SQLite, the trade this repository has
                | already refused twice in writing — or a sentinel account, which is
                | a second answer to "who is this row about".
                |
                | And it buys nothing. `exam_answers` are deleted at 1095 days, so at
                | five years an attempt is a bare score with its detail already gone,
                | and the item analysis it might have fed is a nightly rollup that
                | was materialised into `concept_stats` the day it was taken. What
                | is left is a row naming a person for no reader.
                */
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
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
                'erasure_mode' => ErasureMode::Delete,
            ],
            /*
            | Spec 012 — the adaptive path's two tables.
            |
            | ⚠️ BOTH `Delete`, and `Anonymise` is not available for either:
            | `student_user_id` is NOT NULL on both, so anonymising means either a
            | `->change()` that REBUILDS the table on SQLite — a trade this
            | repository has refused three times in writing — or a sentinel
            | account, which is a second answer to «who is this row about».
            */
            [
                'key' => 'adaptive_session',
                'label_ar' => 'جلسات التدريب التكيّفي',
                'purpose_ar' => 'لتضبط صعوبة السؤال التالي على مستواك، ولتتابع تقدّمك في كل فكرة.',
                'audience' => 'المدرّس المسجَّل عنده',
                'is_required' => true,
                'owning_module' => 'assessments',
                'table_name' => 'adaptive_sessions',
                'column_name' => 'student_user_id',
                // Three years, matching `exam_answer`: the session is the frame
                // around answers that are themselves deleted at 1095 days, so
                // keeping it longer would leave a shell naming a person for no
                // reader.
                'retain_days' => 1095,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
            [
                'key' => 'concept_mastery',
                'label_ar' => 'الأفكار التي أتقنتها',
                'purpose_ar' => 'لتعرف أنت ومدرّسك ما أتقنته، ولئلّا يُعاد تدريبك عليه.',
                'audience' => 'المدرّس المسجَّل عنده',
                'is_required' => true,
                'owning_module' => 'assessments',
                'table_name' => 'concept_masteries',
                'column_name' => 'student_user_id',
                // Five years, matching `exam_attempt`: it is a result, and it
                // outlives the working detail that produced it.
                'retain_days' => 1825,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
            /*
            | Spec 012 · US3. TWO categories, because a room and a participation
            | are two facts about two different sets of people: the room is the
            | HOST's (they chose the concept, the difficulty and the hour), and
            | the participation is each participant's own.
            |
            | ⚠️ AND `study_room_questions` HAS NO CATEGORY OF ITS OWN, because it
            | names nobody. It is the frozen paper — reachable only through its
            | room id — so it is deleted with the room by `AssessmentsPersonalData`
            | rather than by a category, exactly as `attempt_items` is deleted with
            | its attempt. A table with no personal column that got its own
            | category would be a retention clock nothing could ever justify.
            */
            [
                'key' => 'study_room',
                'label_ar' => 'غرف المذاكرة التي أنشأتها',
                'purpose_ar' => 'لتفتح غرفةً تحلّ فيها مع أصدقائك المجموعةَ نفسها في الوقت نفسه.',
                'audience' => 'من انضمّ إلى الغرفة',
                'is_required' => true,
                'owning_module' => 'assessments',
                'table_name' => 'study_rooms',
                'column_name' => 'host_user_id',
                // Three years, matching `exam_answer`: a room is a working
                // session, not a result, and the answers inside it go at the same
                // clock.
                'retain_days' => 1095,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
            [
                'key' => 'study_room_participation',
                'label_ar' => 'مشاركاتك في غرف المذاكرة',
                'purpose_ar' => 'لتستأنف من حيث توقّفت إن انقطع اتّصالك، ولتظهر درجتك على لوحة الغرفة.',
                'audience' => 'من في الغرفة نفسها',
                'is_required' => true,
                'owning_module' => 'assessments',
                'table_name' => 'study_room_participants',
                'column_name' => 'user_id',
                'retain_days' => 1095,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
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
                'erasure_mode' => ErasureMode::Retain,
            ],

            /*
            | Spec 011 · US3 — who invited whom.
            |
            | ⚠️ THIS ROW SHIPS WITH THE TABLES IT DESCRIBES, because nothing
            | would have told us otherwise: `PersonalDataContractCoverageTest` is
            | a per-MODULE guard and `Identity` was already covered, so two new
            | tables holding two users' identities could land inside it with the
            | whole suite green. That limit is written down in `docs/README.md`
            | and this is the first change made after it.
            |
            | ⚠️ AND IT IS `Retain` WITH NO EXPIRY, for `payment_record`'s reason.
            | A completed referral is the audit trail behind points that were
            | actually awarded; deleting it while the `award_entries` rows stand
            | leaves a balance nobody can explain — and the referral names TWO
            | people, so erasing it on one party's request would delete the other
            | party's record of their own invitation. Identity is severed at the
            | `users` row instead, which is what makes SC-007 assertable.
            */
            [
                'key' => 'referral_record',
                'label_ar' => 'دعواتك وكود الدعوة',
                'purpose_ar' => 'لتتبُّع من دعوتَ ومن دعاك، ولصرف نقاط الدعوة عند اشتراك فعليّ.',
                'audience' => 'إدارة المنصّة',
                'is_required' => false,
                'owning_module' => 'identity',
                'table_name' => 'referrals',
                'column_name' => 'referrer_user_id',
                'retain_days' => null,
                'expiry_behaviour' => null,
                'erasure_mode' => ErasureMode::Anonymise,
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
                'erasure_mode' => ErasureMode::Anonymise,
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
                'erasure_mode' => ErasureMode::Anonymise,
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
                /*
                | ⚠️ `Archive`, NOT `Delete`, AND THE DIFFERENCE IS A LESSON IN A
                | COURSE TREE. A recording IS a lesson (spec 017), so deleting the
                | asset row leaves that lesson pointing at an id nothing resolves —
                | with no record anywhere that a retention rule rather than a bug is
                | why the video is gone. Archived, the row keeps the duration, the
                | filename and the date; the FILE is deleted at the provider, which
                | is the part that costs money and holds a student's face.
                |
                | It is also the one category that exercises `ExpiryBehaviour::Archive`
                | at all, and a behaviour with no shipped category is a code path
                | nothing runs — every assertion about it green, vacuously.
                */
                'expiry_behaviour' => ExpiryBehaviour::Archive->value,
                'erasure_mode' => ErasureMode::Delete,
            ],

            // ── Notifications ───────────────────────────────────────────────
            [
                /*
                | Spec 012 · US2 · T074. ⚠️ THE PER-MODULE
                | `PersonalDataContractCoverageTest` CANNOT SEE A MISSING ROW FOR A
                | NEW TABLE INSIDE AN ALREADY-REGISTERED MODULE — its own docblock
                | says so, and spec 010 shipped `announcements.author_user_id`
                | uncovered with 1916 tests green. So this row, its three walks in
                | `NotificationsPersonalData` and the backfill migration are one
                | change, because nothing will tell us otherwise.
                |
                | 730 days: a subscription nobody has used in two years belongs to a
                | browser profile that no longer exists. `Delete`, not `Anonymise` —
                | an endpoint with its user cleared is a device identifier belonging
                | to nobody, which is worse than no row at all.
                */
                'key' => 'push_subscription',
                'label_ar' => 'الأجهزة المشتركة في الإشعارات الفوريّة',
                'purpose_ar' => 'ليصلك إشعار الحصّة أو الرصيد على هاتفك دون فتح الموقع.',
                'audience' => 'أنت',
                'is_required' => false,
                'owning_module' => 'notifications',
                'table_name' => 'push_subscriptions',
                'column_name' => 'user_id',
                'retain_days' => 730,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
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
                'erasure_mode' => ErasureMode::Delete,
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
                'erasure_mode' => ErasureMode::Anonymise,
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
                'erasure_mode' => ErasureMode::Retain,
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
                'erasure_mode' => ErasureMode::Retain,
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
                'erasure_mode' => ErasureMode::Delete,
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
                'erasure_mode' => ErasureMode::Anonymise,
            ],

            // ── Community ───────────────────────────────────────────────────
            //
            // ⚠️ ALL THREE ARE `Delete`, AND THE REASON IS THE SCHEMA RATHER THAN
            // A PREFERENCE. `messages.sender_user_id`, `periodic_reviews`'
            // student and teacher columns and `report_card_segments`'
            // `student_user_id` are every one of them `NOT NULL` — anonymising
            // means a `->change()`, which rebuilds the table on SQLite, a trade
            // this repository has refused twice in writing (`exam_attempts` is
            // the precedent, and it shipped as `Anonymise` until the sweep was
            // written against the actual schema).
            [
                'key' => 'chat_message',
                'label_ar' => 'رسائلك في المحادثات',
                'purpose_ar' => 'لتسأل مدرّسك ويجيبك، ولتُراجَع أيّ إساءة عند البلاغ.',
                'audience' => 'الطرف الآخر في المحادثة · المشرف عند البلاغ',
                'is_required' => false,
                'owning_module' => 'community',
                'table_name' => 'messages',
                'column_name' => 'sender_user_id',
                // Two years: long enough that «ما الذي اتّفقنا عليه؟» has an
                // answer across a school year and the one after it, short enough
                // that a childhood of conversations is not kept indefinitely.
                'retain_days' => 730,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
            [
                'key' => 'periodic_review',
                'label_ar' => 'تقييمات مدرّسك الدورية عنك',
                'purpose_ar' => 'ليعرف الطالب ووليّ أمره موضعَه ويتابعا تحسّنه.',
                'audience' => 'الطالب · وليّ أمره المخوَّل بالنتائج · المدرّس الكاتب',
                'is_required' => false,
                'owning_module' => 'community',
                'table_name' => 'periodic_reviews',
                'column_name' => 'student_user_id',
                'retain_days' => 1825,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
            [
                'key' => 'report_card',
                'label_ar' => 'كشوف تقديراتك',
                'purpose_ar' => 'سجلُّ تقديرك في كلّ فترة عبرَ مدرّسيك جميعاً.',
                'audience' => 'الطالب · وليّ أمره المخوَّل بالنتائج',
                'is_required' => false,
                'owning_module' => 'community',
                'table_name' => 'report_cards',
                'column_name' => 'student_user_id',
                // Five years, matching `exam_attempt`: the card is the summary of
                // the same term those attempts belong to, and two different
                // durations would leave a card citing marks that no longer exist.
                'retain_days' => 1825,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
            /*
            | ⚠️ THE TEACHER'S ROW, NOT THE STUDENT'S — `author_user_id`, and the
            | student's copy of the same words is a `notifications` row that
            | Notifications already owns. Two categories over one message on
            | purpose: the announcement is the teacher's outbound record and it
            | outlives the feed entries it produced.
            |
            | ⚠️ AND IT ARRIVED SILENTLY. Phase 8 added this table inside a module
            | that was already registered, and `PersonalDataContractCoverageTest`
            | is a per-MODULE guard — its own docblock says so — so the suite
            | stayed green over an author column with no export path and no
            | expiry. The table-level version of that check was measured before
            | this line was written: it lights up forty-two tables across every
            | module, which is the guard-silenced-by-exemptions shape the file
            | already rejected once. Recorded rather than half-built.
            */
            [
                'key' => 'announcement',
                'label_ar' => 'الإعلانات التي نشرتَها',
                'purpose_ar' => 'لتبلّغ طلابك أمراً يخصّ الصفَّ أو الحصّة.',
                'audience' => 'طلاب المدرّس المعنيّون بنطاق الإعلان',
                'is_required' => false,
                'owning_module' => 'community',
                'table_name' => 'announcements',
                'column_name' => 'author_user_id',
                // Two years, matching `chat_message`: an announcement is the same
                // conversation addressed to a class instead of to one person, and
                // two durations over one exchange leave a reply citing a notice
                // that no longer exists.
                'retain_days' => 730,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],

            // ── Store (spec 011) ──────────────────────────────────────
            [
                'key' => 'store_purchase',
                'label_ar' => 'مشترياتك من المتجر',
                'purpose_ar' => 'إثباتُ ما اشتريتَه ومتى — ولفتحِ الكتابِ الرقميِّ الذي دفعتَ ثمنَه.',
                'audience' => 'إدارة المنصّة · المدرّس البائع',
                'is_required' => true,
                'owning_module' => 'store',
                'table_name' => 'store_orders',
                'column_name' => 'buyer_user_id',
                // ⚠️ A SALE IS A LEGAL RECORD, so no sweep touches it — the same
                // reasoning as `payment_record`, and the same consequence: erasure
                // severs the identity at the `users` row rather than deleting the
                // purchase. It is ALSO what entitles the buyer to open the file
                // they paid for, so a duration here would take a book away from
                // somebody who owns it.
                'retain_days' => null,
                'expiry_behaviour' => null,
                'erasure_mode' => ErasureMode::Anonymise,
            ],
            [
                'key' => 'shipping_address',
                'label_ar' => 'عنوان الشحن',
                'purpose_ar' => 'لإيصالِ النسخةِ المطبوعةِ إلى الباب.',
                'audience' => 'المدرّس البائع · شركة الشحن',
                'is_required' => false,
                'owning_module' => 'store',
                'table_name' => 'shipments',
                'column_name' => 'recipient_name',
                // Two years. It is a place where a child lives, no invariant
                // counts it, and nobody reads it again once the parcel arrives.
                // The ROW survives — the purchase still shows something was
                // posted — and only the address inside it is cleared.
                'retain_days' => 730,
                'expiry_behaviour' => ExpiryBehaviour::Anonymise->value,
                'erasure_mode' => ErasureMode::Anonymise,
            ],

            // ── Analytics (spec 011 · US6) ────────────────────────────
            /*
            | ⚠️ ONE ROW FOR ONE TABLE, AND IT IS WHAT TAKES `Analytics` OFF
            | `PersonalDataContractCoverageTest`'s EXEMPTION LIST. That list
            | carried «Analytics — has no `Schema::create` of its own», which
            | stopped being true the moment `report_subscriptions` landed; an
            | exemption whose stated reason has expired is a guard passing over a
            | lie.
            |
            | `Delete` on both counts, and unusually easy to justify: the row is a
            | preference, not a record of anything that happened. Nobody's rights
            | depend on remembering that somebody once asked for a weekly report,
            | and no invariant counts it.
            */
            [
                'key' => 'report_subscription',
                'label_ar' => 'اشتراكك في التقارير المجدولة',
                'purpose_ar' => 'لإرسالِ أرقامِ المنصّةِ التي طلبتَها في موعدِها.',
                'audience' => 'إدارة المنصّة',
                'is_required' => false,
                'owning_module' => 'analytics',
                'table_name' => 'report_subscriptions',
                'column_name' => 'user_id',
                'retain_days' => 730,
                'expiry_behaviour' => ExpiryBehaviour::Delete->value,
                'erasure_mode' => ErasureMode::Delete,
            ],
        ];
    }
}
