<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\CreateCohort;
use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\AssignSessionsToCohort;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Throwable;

/**
 * ما تحتاجُه فحوصُ `quickstart.md` الثمانيةُ في سبيك ٠٢١ لتُمشى يدويّاً.
 *
 * ⚠️ يُضيفُ فقط، ولا يَهدِمُ شيئاً — سابقةُ {@see StudentDashboardSeeder}، وهو
 * كذلك **غيرُ مسجَّلٍ** في {@see DatabaseSeeder}: مسارُ ذاك `migrate:fresh --seed`،
 * وهو ما طُلِبَ تجنُّبُه على قاعدةٍ محليّةٍ فيها عملٌ قائم.
 *
 *     php artisan db:seed --class=CohortDemoSeeder
 *
 * ⚠️ وكلُّ صفٍّ بمفتاحٍ ثابتٍ يُقرَأُ بـ`firstOrCreate`، فالتشغيلةُ الثانيةُ تجدُ
 * ما زرعتْه ولا تُكرِّرُه. المواعيدُ وحدَها تُحدَّث: «حصّةُ الغد» تصيرُ أمساً بعدَ
 * يومين، وجدولاً فارغاً من جديد.
 */
class CohortDemoSeeder extends Seeder
{
    private const STUDENT_EMAIL = 'student@example.com';

    /** أسماءُ المجموعاتِ الثلاثِ — وهي مفاتيحُها: `unique(course_id, name)`. */
    private const SATURDAY = 'السبت ٤م';

    private const SUNDAY = 'الأحد ٦م';

    private const FULL = 'الثلاثاء ٧م — مكتملة';

    public function run(): void
    {
        $student = User::query()->where('email', self::STUDENT_EMAIL)->first();

        if ($student === null) {
            $this->command->error('لا حساب بالبريد '.self::STUDENT_EMAIL.' — شغّل DemoDataSeeder أوّلاً.');

            return;
        }

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
         | ⚠️ `forWorkspace`، لا `set()`. السيدرُ يعملُ داخلَ أمرِ كونسول و
         | `WorkspaceContext` مفردةٌ تُخبّئُ حلَّها، فـ`set()` يُسرِّبُ المساحةَ إلى
         | ما بعدَه في العمليّةِ نفسِها. وهو أيضاً ما يدفعُ `team_id` إلى spatie.
         */
        app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $course, $student): void {
            $profile = TeacherProfile::query()->where('workspace_id', $workspace->getKey())->first();

            if ($profile === null) {
                $this->command->error('لا ملفَّ مدرّسٍ في مساحة '.$workspace->name.'.');

                return;
            }

            $teacher = User::query()->findOrFail($profile->user_id);

            /*
             | ⚠️ النوعُ يُحوَّلُ إلى `group`، وهو التعديلُ الوحيدُ الذي يُجريه هذا
             | الملفُّ على صفٍّ قائم — مذكورٌ هنا لأنّه ليس إضافة. `CreateCohort`
             | يرفضُ أيَّ نوعٍ آخَرَ (FR-037)، فبدونَ هذا السطرِ لا تُزرَعُ مجموعةٌ
             | واحدةٌ والسيدرُ يقولُ «تمّ» عن قاعدةٍ لم يتغيّرْ فيها شيء.
             */
            if ($course->course_type !== Course::TYPE_GROUP) {
                $course->forceFill(['course_type' => Course::TYPE_GROUP])->save();
                $this->command->warn('حُوِّل «'.$course->title.'» إلى كورس مجموعات ليقبل المجموعات.');
            }

            $saturday = $this->cohort($course, $teacher, self::SATURDAY, 'مساءُ السبت — للمبتدئين.', 8);
            $sunday = $this->cohort($course, $teacher, self::SUNDAY, 'مساءُ الأحد — نفسُ المنهج.', 8);
            $full = $this->cohort($course, $teacher, self::FULL, 'اكتملت — تُرى ولا يُنضَمُّ إليها.', 1);

            $this->fill($workspace, $course, $saturday, 'زميل السبت', 'cohort-demo-sat@example.test');
            $this->fill($workspace, $course, $sunday, 'زميل الأحد', 'cohort-demo-sun@example.test');
            // مقعدُها الوحيدُ يُشغَل، فتُقرَأُ «اكتملت» من العدّادِ لا من الاسم.
            $this->fill($workspace, $course, $full, 'زميل الثلاثاء', 'cohort-demo-full@example.test');

            $this->joinOurStudent($sunday, $student);
            $this->cohortSessions($workspace, $profile, $course, $saturday, $sunday);
            $this->unassignedSessions($workspace, $profile, $course, $student);
        });

        $this->command->info('مجموعاتُ ٠٢١: ثلاثٌ (اثنتان مفتوحتان بمواعيدَ متباعدة · واحدةٌ مكتملة) · حصّةٌ لكلٍّ · وحصّتان غيرُ مُسنَدتَين إحداهما بمقعدٍ محجوزٍ لطالبِنا.');
    }

    private function cohort(Course $course, User $teacher, string $name, string $description, int $capacity): Cohort
    {
        $existing = Cohort::query()
            ->withoutWorkspaceScope()
            ->where('course_id', $course->getKey())
            ->where('name', $name)
            ->first();

        if ($existing instanceof Cohort) {
            return $existing;
        }

        return app(CreateCohort::class)->handle($course, $teacher, $name, $description, $capacity);
    }

    /** زميلٌ واحدٌ في المجموعة، لتحملَ قائمةُ الزملاءِ وخيطُ النقاشِ أحداً. */
    private function fill(Workspace $workspace, Course $course, Cohort $cohort, string $name, string $email): void
    {
        $peer = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'uuid' => (string) Str::uuid(),
                'first_name' => $name,
                'last_name' => 'التجريبيّ',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ],
        );

        Enrollment::query()->firstOrCreate(
            ['course_id' => $course->getKey(), 'student_user_id' => $peer->getKey()],
            [
                'workspace_id' => $workspace->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ],
        );

        $this->join($cohort, $peer);
    }

    /**
     * ⚠️ الطالبُ في **الأحد** لا في السبت، وذلك هو فحصُ `quickstart` رقم ٣: «سابقةُ»
     * حصّةٍ يجبُ أن تعني السابقةَ **في مجموعتِه**، فحصّةُ السبتِ التي لم يرَها قطّ
     * لا يجوزُ أن تحجزَ عنه شيئاً. بلا هذا الفصلِ لا يوجدُ ما يُقاس.
     */
    private function joinOurStudent(Cohort $cohort, User $student): void
    {
        $this->join($cohort, $student);
    }

    private function join(Cohort $cohort, User $student): void
    {
        try {
            app(JoinCohort::class)->handle($cohort, $student);
        } catch (Throwable $e) {
            // ⚠️ الرفضُ يُطبَعُ ولا يُبتلَع: «له عضويّةٌ مفتوحة» في التشغيلةِ الثانيةِ
            // متوقَّعٌ، و«اكتملت» على مجموعةٍ يُنتظَرُ أن تُملأَ ليست كذلك.
            $this->command->warn('«'.$cohort->name.'» — '.$student->email.': '.$e->getMessage());
        }
    }

    /** حصّةٌ في كلِّ مجموعةٍ، وحصّةُ السبتِ **تسبقُ** حصّةَ الأحد. */
    private function cohortSessions(
        Workspace $workspace,
        TeacherProfile $profile,
        Course $course,
        Cohort $saturday,
        Cohort $sunday,
    ): void {
        $now = CarbonImmutable::now();

        /*
         | ⚠️ الحصّةُ تُسنَدُ وهي في المستقبلِ ثمّ تُشاخُ إلى الماضي — لا العكس.
         | `AssignSessionsToCohort` يرفضُ حصّةً بدأت أو انتهت، وهو رفضٌ صحيحٌ في
         | شاشةِ المدرّس (إسنادُ ماضٍ يُعيدُ كتابةَ تاريخِ حضورٍ حدثَ فعلاً). لكنَّ
         | السيدرَ يزرعُ تاريخاً، وفحصَ `quickstart` رقم ٣ لا يقومُ بغيرِ حصّةٍ
         | **ماضيةٍ في مجموعةٍ أخرى**. فالإسنادُ يمرُّ بالفعلِ — وهو المالكُ الوحيدُ
         | لـ`cohort_id`، الذي ليس `fillable` أصلاً — والتقادمُ وحدَه يُكتَبُ بعدَه.
         */
        $first = $this->stage($workspace, $profile, $course, 'مجموعة السبت — الحصّةُ الأولى', $now->addDays(7)->setTime(16, 0), ClassSessionStatus::Scheduled);
        $second = $this->stage($workspace, $profile, $course, 'مجموعة الأحد — الحصّةُ الأولى', $now->addDay()->setTime(18, 0), ClassSessionStatus::Scheduled);

        $this->assign($course, $saturday, [$first]);
        $this->assign($course, $sunday, [$second]);

        $held = $now->subDays(3)->setTime(16, 0);

        $first->forceFill([
            'starts_at' => $held,
            'ends_at' => $held->addHour(),
            'status' => ClassSessionStatus::Completed,
            'delivered_at' => $held->addHour(),
        ])->save();
    }

    /**
     * ⚠️ الحصّتان غيرُ المُسنَدتَين، وإحداهما بمقعدٍ محجوزٍ لطالبِنا — **بيتُ القصيد**.
     *
     * حجبُ Q3 يُخفي كلَّ حصّةٍ بلا مجموعةٍ من الاكتشافِ فورَ إنشاءِ أوّلِ مجموعة؛
     * وبغيرِ صفٍّ محجوزٍ بينها لا يستطيعُ أحدٌ رؤيةَ ما إذا كان ذلك الحجبُ يبتلعُ
     * مقعداً دفعَ الطالبُ ثمنَه. الحصّةُ تبقى في «حصصي» لصاحبِ المقعدِ مهما اختفت
     * من الاكتشاف.
     */
    private function unassignedSessions(Workspace $workspace, TeacherProfile $profile, Course $course, User $student): void
    {
        $now = CarbonImmutable::now();

        $booked = $this->stage($workspace, $profile, $course, 'حصّةٌ بلا مجموعة — لطالبنا فيها مقعد', $now->addDays(2)->setTime(19, 0), ClassSessionStatus::Scheduled);
        $this->stage($workspace, $profile, $course, 'حصّةٌ بلا مجموعة — لا مقاعدَ محجوزة', $now->addDays(5)->setTime(19, 0), ClassSessionStatus::Scheduled);

        SessionBooking::query()->firstOrCreate(
            ['class_session_id' => $booked->getKey(), 'student_user_id' => $student->getKey()],
            [
                'workspace_id' => $workspace->getKey(),
                'status' => BookingStatus::Booked,
                'is_billable' => true,
                'booked_at' => now(),
            ],
        );
    }

    /** @param  list<ClassSession>  $sessions */
    private function assign(Course $course, Cohort $cohort, array $sessions): void
    {
        try {
            app(AssignSessionsToCohort::class)->handle(
                $course,
                (string) $cohort->uuid,
                array_map(fn (ClassSession $session): string => (string) $session->uuid, $sessions),
            );
        } catch (Throwable $e) {
            $this->command->warn('تعذّر إسناد حصص «'.$cohort->name.'»: '.$e->getMessage());
        }
    }

    private function stage(
        Workspace $workspace,
        TeacherProfile $profile,
        Course $course,
        string $title,
        CarbonImmutable $startsAt,
        ClassSessionStatus $status,
    ): ClassSession {
        $session = ClassSession::query()->firstOrNew([
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'title' => $title,
        ]);

        $session->fill([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'duration_minutes' => 60,
            'seats_total' => 8,
            'type' => ClassSessionType::Group,
            'status' => $status,
            'uuid' => $session->uuid ?? (string) Str::uuid(),
            'workspace_id' => $workspace->getKey(),
            'teacher_profile_id' => $profile->getKey(),
            'course_id' => $course->getKey(),
            'title' => $title,
        ])->save();

        // ⚠️ `seats_taken` NEVER WRITTEN HERE. It is the counter the atomic seat
        // claim owns; a seeder rewriting it on a second run frees a seat that is
        // still booked, and the same seat is then sold twice.
        return $session->refresh();
    }
}
