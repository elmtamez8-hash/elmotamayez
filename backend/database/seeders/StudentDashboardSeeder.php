<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessments\Actions\GradeSubmission;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Community\Actions\PublishPeriodicReview;
use App\Modules\Community\Actions\SubmitPeriodicReview;
use App\Modules\Community\Data\PeriodicReviewData;
use App\Modules\Community\Jobs\BuildReportCardsJob;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\EvaluateBadges;
use App\Modules\Gamification\Actions\RollUpLeaderboards;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Throwable;

/**
 * ما يجعل صفحاتِ الطالبِ تُقرأ: جدولٌ وحصصٌ ونقاطٌ وشاراتٌ وصدارةٌ وأرصدةٌ وواجباتٌ وتقارير.
 *
 * ⚠️ يُضيفُ فقط، ولا يَهدِمُ شيئاً. يُستدعى وحدَه:
 *
 *     php artisan db:seed --class=StudentDashboardSeeder
 *
 * ولهذا هو **غيرُ مسجَّلٍ** في {@see DatabaseSeeder}: مسارُ ذاك هو
 * `migrate:fresh --seed`، وهو المسارُ الذي طُلِبَ تجنُّبُه على قاعدةٍ محليّةٍ فيها عملٌ
 * قائم. يبدأُ من حسابٍ موجودٍ ومن تسجيلٍ موجود، ولا يُنشئُ مساحةَ عملٍ ولا كورساً.
 *
 * ⚠️ وهو قابلٌ لإعادةِ التشغيل: كلُّ صفٍّ يُكتَبُ بمفتاحٍ ثابتٍ (عنوانٌ أو
 * `sourceId`)، فالتشغيلةُ الثانيةُ **تُحدِّثُ مواعيدَ ما زرعته هي** ولا تُكرِّرُه —
 * وهو المطلوب، لأنّ «حصّةُ الغد» تصيرُ أمساً بعدَ يومين، وجدولاً فارغاً من جديد.
 * ما لم يزرعْه هذا الملفُّ لا يمسُّه.
 */
class StudentDashboardSeeder extends Seeder
{
    /** الحسابُ الذي تُعلَّقُ عليه البيانات — نفسُ حسابِ العرضِ في {@see DemoDataSeeder}. */
    private const STUDENT_EMAIL = 'student@example.com';

    public function run(): void
    {
        $student = User::query()->where('email', self::STUDENT_EMAIL)->first();

        if ($student === null) {
            $this->command->error('لا حساب بالبريد '.self::STUDENT_EMAIL.' — شغّل DemoDataSeeder أوّلاً.');

            return;
        }

        // التسجيلُ هو المصدرُ: منه الكورسُ ومنه مساحةُ العمل. البدءُ من مساحةِ عملٍ
        // «أوّلٍ» في الجدولِ يزرعُ عندَ مدرّسٍ لا يدرسُ الطالبُ عنده، فتظهرُ الحصصُ
        // في «حصصي» عندَ ذاكَ المدرّسِ ولا تظهرُ في جدولِ الطالبِ إطلاقاً.
        $enrollment = Enrollment::query()
            ->withoutGlobalScopes()
            ->where('student_user_id', $student->getKey())
            ->latest('id')
            ->first();

        if ($enrollment === null) {
            $this->command->error('الطالب غير مسجَّل في أيّ كورس — شغّل DemoDataSeeder أوّلاً.');

            return;
        }

        $workspace = Workspace::query()->findOrFail($enrollment->workspace_id);
        $course = Course::query()->withoutGlobalScopes()->findOrFail($enrollment->course_id);

        /*
         | ⚠️ `forWorkspace`، لا `set`. السيدر يعملُ داخلَ أمرِ كونسول، و`WorkspaceContext`
         | مفردةٌ تُخبّئُ حلَّها — فـ`set()` هنا يُسرِّبُ المساحةَ إلى ما بعدَه في نفسِ
         | العمليّة. وهو أيضاً ما يدفعُ `team_id` إلى spatie، فبدونه كلُّ صلاحيّةٍ كاذبة.
         */
        app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $student, $course): void {
            $profile = TeacherProfile::query()->where('workspace_id', $workspace->getKey())->first();

            if ($profile === null) {
                $this->command->error('لا ملفَّ مدرّسٍ في مساحة '.$workspace->name.'.');

                return;
            }

            $teacher = User::query()->findOrFail($profile->user_id);

            $this->consents($student);
            $this->credits($student, $course);
            $this->sessions($workspace, $profile, $course, $student);
            $this->homework($workspace, $teacher, $course, $student);
            $this->gamification($workspace, $student, $course);
            $this->reports($workspace, $teacher, $student);
        });

        $this->command->info('بيانات لوحة الطالب: جدول وحصص · نقاط وشارات وصدارة · رصيد · واجبات · تقارير.');
    }

    /**
     * الموافقتان اللتان تسبقان الرصيدَ على الشاشة.
     *
     * ⚠️ بدونهما تفتحُ «رصيدي» على «موافقات مطلوبة» فوقَ الأرصدة — وهي شاشةٌ
     * صحيحةٌ تماماً، لكنّها ليست ما زُرِعَتِ البياناتُ لعرضِه. والنسخةُ تُقرأُ من
     * `ConsentRegistry` ولا تُكتَبُ رقماً هنا: نسخةٌ منشورةٌ جديدةٌ تُبطِلُ التأجيلَ
     * لمن لم يوقّعْها، فرقمٌ ثابتٌ في سيدرٍ يصيرُ موافقةً لوثيقةٍ لا وجودَ لها.
     */
    private function consents(User $student): void
    {
        $registry = app(ConsentRegistry::class);

        foreach ([ConsentDocument::DeferredPaymentTerms, ConsentDocument::DataProcessing] as $document) {
            TermsConsent::query()->firstOrCreate(
                [
                    'user_id' => $student->getKey(),
                    'student_user_id' => $student->getKey(),
                    'document' => $document->value,
                    'version' => $registry->currentVersion($document),
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'StudentDashboardSeeder',
                    'consented_at' => now()->subMonth(),
                ],
            );
        }
    }

    /**
     * رصيدٌ يكفي الحجزَ ويُظهِرُ صفحةَ «رصيدي».
     *
     * ⚠️ عبرَ `CreditLedger`، لا بكتابةِ رقمٍ على الرصيد: الرصيدُ هو مجموعُ قيودِه،
     * ورقمٌ مكتوبٌ باليدِ هو الفرقُ الذي يُبلِّغُ عنه `ReconcileCreditBalancesJob`.
     * و`sourceId` ثابتٌ عمداً — هو نصفُ مفتاحِ التكرار، فالتشغيلةُ الثانيةُ لا تُضيفُ
     * رصيداً ثانياً.
     */
    private function credits(User $student, Course $course): void
    {
        app(CreditLedger::class)->post(new CreditMovement(
            balance: app(CreditAccounts::class)->balanceFor($student, $course),
            type: CreditTransactionType::Purchase,
            credits: 12,
            sourceType: 'student_dashboard_seed',
            sourceId: (int) $course->getKey(),
            reason: 'باقةُ عرضٍ لملءِ لوحةِ الطالب.',
        ));
    }

    /**
     * الجدولُ أوّلاً: ثلاثُ حصصٍ قادمةٍ في مواعيدَ مختلفة، وواحدةٌ مضت بسجلِّ حضور.
     *
     * ⚠️ والقادمةُ تُحجَزُ بـ`BookSeat`، لا بصفٍّ مكتوبٍ باليد: الحجزُ يُنقِصُ الرصيدَ
     * ويُطالبُ المقعدَ بتحديثٍ شرطيٍّ ويسألُ شرطَ الفتح — وصفٌّ مكتوبٌ مباشرةً هو
     * تطبيقٌ ثانٍ لكلِّ ذلك، يفترقُ عن الأوّلِ عندَ أوّلِ تغييرٍ في القاعدة.
     *
     * أمّا الماضيةُ فتُكتَبُ مباشرةً، لأنّ `BookSeat` يرفضُ حصّةً بدأت — وهو محقّ.
     */
    private function sessions(Workspace $workspace, TeacherProfile $profile, Course $course, User $student): void
    {
        $today = CarbonImmutable::now();

        $upcoming = [
            ['حصة اليوم — مراجعةٌ سريعة', $today->addHours(3), 1, true],
            ['حصة الغد — تمارينُ محلولة', $today->addDay()->setTime(17, 0), 6, true],
            ['حصةُ نهايةِ الأسبوع — مقاعدُ متاحة', $today->addDays(4)->setTime(19, 0), 8, false],
        ];

        foreach ($upcoming as [$title, $startsAt, $seats, $book]) {
            $session = $this->stageSession($workspace, $profile, $course, $title, [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'duration_minutes' => 60,
                'seats_total' => $seats,
                'type' => $seats > 1 ? ClassSessionType::Group : ClassSessionType::Individual,
                'status' => ClassSessionStatus::Scheduled,
            ]);

            if (! $book || $this->hasBooking($session, $student)) {
                continue;
            }

            /*
             | ⚠️ الرفضُ يُطبَعُ ولا يُبتلَع. `BookSeat` يرفضُ لأسبابٍ حقيقيّةٍ — رصيدٌ
             | تحتَ الحدّ، شرطُ فتحٍ غيرُ مستوفى، مقاعدُ اكتملت — وسيدرٌ يبتلعُ الرفضَ
             | يُسلِّمُ جدولاً فارغاً ويقولُ «تمّ»، وهو أسوأُ من أن يفشل.
             */
            try {
                app(BookSeat::class)->handle($session, $student);
            } catch (Throwable $e) {
                $this->command->warn('تعذّر حجز «'.$title.'»: '.$e->getMessage());
            }
        }

        // الحصّةُ الماضية: منها سجلُّ الحضورِ الذي يقرأُه كشفُ التقديرات، ومنها
        // «حضرتُ ٪» في التقرير. بلا صفِّ حضورٍ واحدٍ يُبنى الكشفُ فوقَ لا شيء.
        $past = $this->stageSession($workspace, $profile, $course, 'حصةٌ ماضية — الأعدادُ النسبيّة', [
            'starts_at' => $today->subDays(2)->setTime(17, 0),
            'ends_at' => $today->subDays(2)->setTime(18, 0),
            'duration_minutes' => 60,
            'seats_total' => 6,
            'seats_taken' => 1,
            'type' => ClassSessionType::Group,
            'status' => ClassSessionStatus::Completed,
            'delivered_at' => $today->subDays(2)->setTime(18, 0),
            'billable_seats' => 1,
        ]);

        SessionBooking::query()->firstOrCreate(
            ['class_session_id' => $past->getKey(), 'student_user_id' => $student->getKey()],
            [
                'workspace_id' => $workspace->getKey(),
                'status' => BookingStatus::Booked,
                'is_billable' => true,
                'booked_at' => $past->starts_at->subWeek(),
            ],
        );

        Attendance::query()->firstOrCreate(
            ['class_session_id' => $past->getKey(), 'student_user_id' => $student->getKey()],
            [
                'workspace_id' => $workspace->getKey(),
                'status' => AttendanceStatus::Present,
                'auto_status' => AttendanceStatus::Present,
                'source' => AttendanceSource::Automatic,
                'stay_seconds' => 3480,
                'first_joined_at' => $past->starts_at,
                'confirmed_at' => $past->ends_at,
            ],
        );
    }

    /**
     * حصّةٌ بعنوانٍ ثابت: تُنشأُ مرّةً، وتُعادُ جدولتُها في كلِّ تشغيلةٍ بعدَها.
     *
     * ⚠️ العنوانُ هو المفتاح، والتحديثُ مقصود. «حصة الغد» تصيرُ أمساً بعدَ يومين،
     * فسيدرٌ يُنشئُ ثمّ يتخطّى يتركُ الجدولَ فارغاً بعدَ ٤٨ ساعةً من أوّلِ تشغيل —
     * وهو بالضبطِ العرَضُ الذي كُتِبَ هذا الملفُّ لعلاجِه.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function stageSession(
        Workspace $workspace,
        TeacherProfile $profile,
        Course $course,
        string $title,
        array $attributes,
    ): ClassSession {
        $session = ClassSession::query()->firstOrNew([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'title' => $title,
        ]);

        // مقعدٌ محجوزٌ من تشغيلةٍ سابقةٍ يبقى محجوزاً: الكتابةُ فوقَه بصفرٍ تُنقِصُ
        // العدّادَ الذي يحرسُ المقاعدَ، فيُباعُ مقعدٌ مرّتين.
        if ($session->exists) {
            unset($attributes['seats_taken']);
        }

        $session->fill([
            ...$attributes,
            'uuid' => $session->uuid ?? (string) Str::uuid(),
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $course->getKey(),
            'title' => $title,
        ])->save();

        return $session->refresh();
    }

    private function hasBooking(ClassSession $session, User $student): bool
    {
        return SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->where('student_user_id', $student->getKey())
            ->where('status', BookingStatus::Booked)
            ->exists();
    }

    /**
     * واجبان منشوران: واحدٌ سُلِّمَ وصُحِّح، وواحدٌ لم يُسلَّمْ بعد.
     *
     * ⚠️ واجبٌ واحدٌ لا يكفي: الحالةُ تُشتَقُّ من الموعدِ داخلَ `SubmitAssignment`،
     * وشاشةُ «واجباتي» موضوعُها الفرقُ بين مُسلَّمٍ ومُعلَّق. وبموعدٍ ماضٍ وحدَه يصيرُ
     * كلُّ تسليمٍ متأخّراً، بما فيه الصفُّ المزروعُ ليُظهِرَ التسليمَ في وقتِه.
     */
    private function homework(Workspace $workspace, User $teacher, Course $course, User $student): void
    {
        $done = Assignment::query()->firstOrCreate(
            ['workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(), 'title' => 'واجب المراجعة الأولى'],
            [
                'uuid' => (string) Str::uuid(),
                'description' => 'حلَّ التمارين من ١ إلى ٥ واكتب خطواتِ الحل.',
                'points' => 20,
                'due_at' => now()->addDays(5),
                'submission_type' => 'text',
                'status' => 'published',
                'published_at' => now()->subDays(3),
                'created_by' => $teacher->getKey(),
            ],
        );

        $submission = Submission::query()
            ->withoutGlobalScopes()
            ->where('assignment_id', $done->getKey())
            ->where('student_user_id', $student->getKey())
            ->first();

        if ($submission === null) {
            $submission = app(SubmitAssignment::class)->handle($done, $student, 'حلَلتُ الخمسةَ وأرفقتُ الخطوات.');
            app(GradeSubmission::class)->handle($submission, $teacher, 17.0, 'ممتاز. انتبه لخطوةِ التبسيطِ في الثالث.');
        }

        Assignment::query()->firstOrCreate(
            ['workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(), 'title' => 'واجب هذا الأسبوع — لم يُسلَّمْ بعد'],
            [
                'uuid' => (string) Str::uuid(),
                'description' => 'اكتب ملخّصاً في صفحةٍ واحدةٍ لما شرحناه في الحصة.',
                'points' => 10,
                'due_at' => now()->addDays(2),
                'submission_type' => 'text',
                'status' => 'published',
                'published_at' => now()->subDay(),
                'created_by' => $teacher->getKey(),
            ],
        );
    }

    /**
     * نقاطٌ وشاراتٌ وصدارة.
     *
     * ⚠️ عبرَ `AwardPoints`، فالقيمةُ تُقرَأُ من الكتالوجِ ولا تُكتَبُ هنا (NFR-002)،
     * والسقفُ اليوميُّ يُحتَرَم. و`sourceId` مُرقَّمٌ ثابتاً لأنّه نصفُ مفتاحِ
     * التكرار: تشغيلةٌ ثانيةٌ لا تُضاعِفُ النقاط.
     *
     * ⚠️ والترتيبُ لازم: `EvaluateBadges` يقرأُ `student_progress` الذي يكتبُه
     * `AwardPoints`، و`RollUpLeaderboards` يقرأُ القيودَ نفسَها — فتقديمُ أيٍّ منهما
     * يُنتِجُ صفراً بلا خطأٍ واحد.
     */
    private function gamification(Workspace $workspace, User $student, Course $course): void
    {
        $award = app(AwardPoints::class);

        // المفاتيحُ وسقوفُها من `GamificationCatalogSeeder`: حضورٌ ٤ · واجبٌ ٣ · اختبارٌ ٢.
        $plan = [
            ['session_attended', 4],
            ['homework_submitted', 3],
            ['exam_passed', 2],
            ['mistake_resolved', 5],
        ];

        foreach ($plan as [$key, $times]) {
            for ($i = 1; $i <= $times; $i++) {
                $award->handle(new AwardRequest(
                    studentUserId: (int) $student->getKey(),
                    actionKey: $key,
                    sourceType: 'student_dashboard_seed',
                    sourceId: $i,
                    workspaceId: (int) $workspace->getKey(),
                    courseId: (int) $course->getKey(),
                ));
            }
        }

        app(EvaluateBadges::class)->handle($student);
        app(RollUpLeaderboards::class)->handle();
    }

    /**
     * التقارير: كشفُ التقديرات وتقييمٌ دوريٌّ منشور.
     *
     * ⚠️ كشفُ التقديراتِ يُبنى بالوظيفةِ نفسِها التي تبنيه ليلاً، لا بصفٍّ مكتوب.
     * هي تقرأُ التسجيلَ والدرجاتِ والحضورَ وتزنُها، وتتخطّى كشفاً منشوراً — فهي
     * قابلةٌ لإعادةِ التشغيلِ بذاتِها، وأيُّ نسخةٍ يدويّةٍ منها تطبيقٌ ثانٍ للأوزان.
     */
    private function reports(Workspace $workspace, User $teacher, User $student): void
    {
        $from = CarbonImmutable::now()->subMonth()->startOfMonth();
        $to = $from->endOfMonth();

        BuildReportCardsJob::dispatchSync($from->toDateString(), $to->toDateString());

        $exists = PeriodicReview::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->getKey())
            ->where('student_user_id', $student->getKey())
            ->where('period_start', $from->toDateString())
            ->exists();

        if ($exists) {
            return;
        }

        try {
            /*
             | ⚠️ الباني بالوسائطِ المسمّاة، لا `fromArray`. مفاتيحُ ذاك snake_case،
             | و`?? ''` تحتَ كلِّ واحدٍ منها — فمفتاحٌ بحرفٍ مختلفٍ يسقطُ صامتاً
             | ويُبنى الكائنُ بنصوصٍ فارغة، ثمّ يُرفَضُ بـ«لا يوجد طالب» عن طالبٍ
             | مسجَّلٍ فعلاً. الباني يُسمّي الخطأَ عندَ الترجمة.
             */
            $review = app(SubmitPeriodicReview::class)->handle($workspace, $teacher, new PeriodicReviewData(
                studentUuid: (string) $student->uuid,
                periodStart: $from->toDateString(),
                periodEnd: $to->toDateString(),
                commitment: 4,
                participation: 5,
                homework: 4,
                improvement: 4,
                note: 'تحسُّنٌ واضحٌ في المشاركة. يحتاجُ انتظاماً أكثرَ في التسليم.',
            ));

            app(PublishPeriodicReview::class)->handle($review);
        } catch (Throwable $e) {
            $this->command->warn('تعذّر زرع التقييم الدوري: '.$e->getMessage());
        }
    }
}
