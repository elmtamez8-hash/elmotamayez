<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Certificates\Models\CertificateTemplate;
use App\Modules\CMS\Models\Article;
use App\Modules\CMS\Models\Category;
use App\Modules\CMS\Models\Tag;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Product;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every business table with a mix of states so the platform can be exercised
 * end-to-end without clicking through setup first:
 *
 * - draft / published / archived courses, sequential and free
 * - pending / approved / rejected orders (+ payment transactions)
 * - fresh / in-progress / completed enrollments with progress history
 * - in-progress / failed / passed exam attempts with answers
 * - certificates from both triggers (course completion and exam pass)
 * - CMS categories, tags and articles (published / draft / scheduled)
 * - pending / accepted / expired invitations
 * - a second workspace to verify cross-workspace isolation
 *
 * Every seeded user's password is "password".
 */
final class ScenarioSeeder extends Seeder
{
    public function run(): void
    {
        // Enrollment-from-order, certificate issuance and notification listeners are
        // ShouldQueue; run them inline so the seeded data lands complete without a worker.
        config(['queue.default' => 'sync']);

        $this->seedAcademy();
        $this->seedSoloTeacher();

        $this->command->info('Scenario data seeded — see database/seeders/ScenarioSeeder.php for the account list.');
    }

    /**
     * The main tenant: an academy with staff, students, paid + free courses,
     * the full payment → enrollment → exam → certificate flow, and CMS content.
     */
    private function seedAcademy(): void
    {
        $owner = $this->user('Nour', 'Owner', 'owner@academy.test');

        $workspace = app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray([
                'name' => 'Nour Academy',
                'type' => 'academy',
                'slug' => 'nour-academy',
            ]),
            $owner,
        );

        app(WorkspaceContext::class)->set($workspace);

        $teacher = $this->member($workspace, Roles::TEACHER, 'Sami', 'Teacher', 'sami@academy.test');
        $this->member($workspace, Roles::ASSISTANT_TEACHER, 'Lina', 'Assistant', 'lina@academy.test');
        $buyer = $this->member($workspace, Roles::STUDENT, 'Ali', 'Buyer', 'ali@academy.test');
        $halfway = $this->member($workspace, Roles::STUDENT, 'Mona', 'Halfway', 'mona@academy.test');
        $graduate = $this->member($workspace, Roles::STUDENT, 'Omar', 'Graduate', 'omar@academy.test');
        $browser = $this->member($workspace, Roles::STUDENT, 'Hana', 'Newcomer', 'hana@academy.test');

        $this->invitations($workspace);

        // Created before any certificate so issued certificates reference it.
        CertificateTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Default Certificate',
            'html_template' => '<div class="cert"><h1>{{ course_title }}</h1><p>{{ student_name }}</p><small>{{ certificate_number }}</small></div>',
            'defaults' => ['orientation' => 'landscape', 'accent' => '#4f46e5'],
        ]);

        $paid = $this->course($workspace, $teacher, [
            'title' => 'Laravel Mastery',
            'slug' => 'laravel-mastery',
            'description' => 'Build production APIs with Laravel: routing, Eloquent, queues and testing.',
            'price' => 49.99,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => true,
        ]);
        $paidLessons = $this->curriculum($paid, [
            ['Fundamentals', [
                ['Course Introduction', 'video', true],
                ['Installing PHP & Composer', 'article', false],
                ['Project Structure', 'pdf', false],
            ]],
            ['Going Deeper', [
                ['Eloquent Relationships', 'video', false],
                ['Queues & Jobs', 'article', false],
                ['Exercise Files', 'file', false],
            ]],
        ]);

        $free = $this->course($workspace, $teacher, [
            'title' => 'Git Basics',
            'slug' => 'git-basics',
            'description' => 'Version control from zero: commits, branches and merges.',
            'price' => 0,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => false,
        ]);
        $this->curriculum($free, [
            ['Getting Started', [
                ['What is Git', 'article', true],
                ['Your First Commit', 'video', false],
            ]],
        ]);

        $this->course($workspace, $teacher, [
            'title' => 'Vue 3 Composition API',
            'slug' => 'vue-3-composition-api',
            'description' => 'Work in progress — not visible to students yet.',
            'price' => 39.00,
            'status' => 'draft',
            'visibility' => 'private',
            'is_sequential' => true,
        ]);

        $this->course($workspace, $teacher, [
            'title' => 'PHP 7 Legacy Track',
            'slug' => 'php-7-legacy-track',
            'description' => 'Retired course, kept for existing students.',
            'price' => 19.00,
            'status' => 'archived',
            'visibility' => 'private',
            'is_sequential' => false,
        ]);

        Product::create([
            'workspace_id' => $workspace->id, 'course_id' => $paid->id,
            'name' => 'Laravel Mastery — lifetime access', 'type' => 'course',
            'price' => 49.99, 'currency' => 'USD', 'is_active' => true,
        ]);
        Product::create([
            'workspace_id' => $workspace->id, 'course_id' => $free->id,
            'name' => 'Git Basics — free', 'type' => 'course',
            'price' => 0, 'currency' => 'USD', 'is_active' => true,
        ]);

        // Approved order → the PaymentApproved listener creates the enrollment (source=order).
        $approved = app(CreateOrder::class)->handle($paid, $buyer);
        app(ApproveOrder::class)->handle($approved, $owner);

        // Pending order awaiting review, with the matching pending transaction.
        $pending = app(CreateOrder::class)->handle($paid, $browser);
        PaymentTransaction::create([
            'workspace_id' => $workspace->id, 'order_id' => $pending->id,
            'provider' => 'manual', 'amount' => $pending->amount, 'currency' => $pending->currency,
            'status' => 'pending', 'reference' => 'bank-transfer-'.$pending->id,
            'payload' => ['bank' => 'Demo Bank', 'sender' => 'Hana Newcomer'],
        ]);

        // Rejected order.
        $rejected = app(CreateOrder::class)->handle($paid, $halfway);
        app(RejectOrder::class)->handle($rejected, $owner, 'Transfer receipt did not match the order amount.');

        // Manual enrollments in three states.
        $halfwayEnrollment = app(EnrollStudent::class)->handle($paid, $halfway);
        foreach ($paidLessons->take(3) as $lesson) {
            app(MarkLessonComplete::class)->handle($halfwayEnrollment, $lesson->id);
        }

        $graduateEnrollment = app(EnrollStudent::class)->handle($paid, $graduate);
        foreach ($paidLessons as $lesson) {
            app(MarkLessonComplete::class)->handle($graduateEnrollment, $lesson->id);
        }

        app(EnrollStudent::class)->handle($free, $browser);

        $exam = $this->exam($workspace, $paid, [
            'title' => 'Laravel Mastery — Final Exam',
            'description' => 'Covers routing, Eloquent and queues.',
            'status' => 'published',
            'passing_score' => 60,
            'max_attempts' => 3,
            'shuffle_questions' => true,
            'shuffle_answers' => true,
        ]);

        $this->exam($workspace, $paid, [
            'title' => 'Mid-term Quiz (unpublished)',
            'description' => 'Draft exam, not yet visible to students.',
            'status' => 'draft',
            'passing_score' => 50,
            'max_attempts' => 1,
        ]);

        $buyerEnrollment = Enrollment::where('course_id', $paid->id)
            ->where('student_user_id', $buyer->id)
            ->firstOrFail();

        // Passed attempt → ExamPassed → certificate with reason "exam_passed".
        $passing = app(StartAttempt::class)->handle($exam, $halfway, $halfwayEnrollment);
        app(GradeAttempt::class)->handle($passing, $this->answers($exam, correct: true));

        // Failed attempt.
        $failing = app(StartAttempt::class)->handle($exam, $buyer, $buyerEnrollment);
        app(GradeAttempt::class)->handle($failing, $this->answers($exam, correct: false));

        // Attempt still in progress (no answers yet).
        app(StartAttempt::class)->handle($exam, $graduate, $graduateEnrollment);

        $this->sessions($workspace, $teacher, [$buyer, $halfway, $graduate]);

        $this->cms($workspace, $owner);
    }

    /**
     * The four session states the e2e suite walks through.
     *
     * Without them every sessions spec skips itself on an empty schedule and
     * reports green — which is the failure mode this seeder exists to prevent,
     * and the one that let spec 004's player ship unreachable.
     *
     * @param  list<User>  $students
     */
    private function sessions(Workspace $workspace, User $teacherUser, array $students): void
    {
        $profile = TeacherProfile::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $teacherUser->id,
        ]);

        // 1. Upcoming with seats free — the one a student can actually book.
        $upcoming = ClassSession::factory()->create([
            'teacher_profile_id' => $profile->id,
            'title' => 'مراجعة Laravel — أسئلة مفتوحة',
            'starts_at' => now()->addDays(2)->setHour(17)->startOfHour(),
            'ends_at' => now()->addDays(2)->setHour(18)->startOfHour(),
            'seats_total' => 6,
        ]);

        app(BookSeat::class)->handle($upcoming, $students[0]);

        // 2. Full — so the seat badge has a state other than "available" to show.
        ClassSession::factory()->create([
            'teacher_profile_id' => $profile->id,
            'title' => 'ورشة الطوابير — اكتملت المقاعد',
            'starts_at' => now()->addDays(3)->setHour(19)->startOfHour(),
            'ends_at' => now()->addDays(3)->setHour(20)->startOfHour(),
            'seats_total' => 2,
            'seats_taken' => 2,
        ]);

        // 3. Finished, with a register that has both outcomes in it.
        $past = ClassSession::factory()->past()->create([
            'teacher_profile_id' => $profile->id,
            'title' => 'حصة العلاقات في Eloquent',
            'seats_total' => 6,
            'delivered_at' => now()->subDays(2),
            'billable_seats' => 2,
        ]);

        foreach ([AttendanceStatus::Present, AttendanceStatus::Absent] as $index => $status) {
            Attendance::create([
                'workspace_id' => $workspace->id,
                'class_session_id' => $past->id,
                'student_user_id' => $students[$index]->id,
                'status' => $status,
                'auto_status' => $status,
                'source' => AttendanceSource::Automatic,
                'stay_seconds' => $status === AttendanceStatus::Present ? 3480 : 0,
                'first_joined_at' => $status === AttendanceStatus::Present ? $past->starts_at : null,
                'confirmed_at' => $past->ends_at,
            ]);
        }

        // 4. A holiday, far enough out that it suspends nothing seeded above —
        // a seeder that silently cancels its own scheduled sessions leaves the
        // schedule screen empty for reasons nobody can see.
        FreezePeriod::create([
            'workspace_id' => $workspace->id,
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonth()->startOfMonth()->addDays(9)->toDateString(),
            'reason' => 'إجازة نصف العام',
            'created_by' => $teacherUser->id,
        ]);
    }

    /**
     * A second, unrelated tenant. Anything visible from here while acting as a
     * Nour Academy member is a workspace-isolation bug.
     */
    private function seedSoloTeacher(): void
    {
        $owner = $this->user('Khaled', 'Solo', 'khaled@teacher.test');

        $workspace = app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray([
                'name' => 'Khaled Private Lessons',
                'type' => 'teacher',
                'slug' => 'khaled-private-lessons',
            ]),
            $owner,
        );

        app(WorkspaceContext::class)->set($workspace);

        $student = $this->member($workspace, Roles::STUDENT, 'Yara', 'Student', 'yara@teacher.test');

        $course = $this->course($workspace, $owner, [
            'title' => 'Arabic Calligraphy',
            'slug' => 'arabic-calligraphy',
            'description' => 'Private lessons in classical Arabic calligraphy.',
            'price' => 25.00,
            'status' => 'published',
            'visibility' => 'public',
            'is_sequential' => true,
        ]);
        $lessons = $this->curriculum($course, [
            ['Basics', [
                ['Holding the Qalam', 'video', true],
                ['Letter Forms', 'pdf', false],
            ]],
        ]);

        $enrollment = app(EnrollStudent::class)->handle($course, $student);
        app(MarkLessonComplete::class)->handle($enrollment, $lessons->firstOrFail()->id);

        Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'Autumn schedule', 'slug' => 'autumn-schedule',
            'body' => 'New slots open on Sundays and Wednesdays.',
            'status' => 'published', 'published_at' => now()->subDay(),
            'author_id' => $owner->id,
        ]);
    }

    private function user(string $first, string $last, string $email): User
    {
        return User::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'password' => 'password',
        ]);
    }

    /**
     * Create a user, attach them to the workspace and assign the spatie role
     * within that workspace's team.
     */
    private function member(Workspace $workspace, string $role, string $first, string $last, string $email): User
    {
        $user = $this->user($first, $last, $email);

        $workspace->members()->attach($user->getKey(), [
            'role' => $role,
            'joined_at' => now(),
        ]);
        $user->update(['last_workspace_id' => $workspace->getKey()]);

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($workspace->getKey());
        try {
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }

        return $user;
    }

    private function invitations(Workspace $workspace): void
    {
        Invitation::create([
            'workspace_id' => $workspace->id, 'email' => 'pending.invite@academy.test',
            'role' => Roles::TEACHER, 'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        Invitation::create([
            'workspace_id' => $workspace->id, 'email' => 'expired.invite@academy.test',
            'role' => Roles::STUDENT, 'token' => Str::random(40),
            'expires_at' => now()->subDays(3),
        ]);

        Invitation::create([
            'workspace_id' => $workspace->id, 'email' => 'sami@academy.test',
            'role' => Roles::TEACHER, 'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
            'accepted_at' => now()->subDay(),
            'accepted_by' => User::where('email', 'sami@academy.test')->value('id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function course(Workspace $workspace, User $author, array $attributes): Course
    {
        return Course::create(array_merge([
            'workspace_id' => $workspace->id,
            'currency' => 'USD',
            'language' => 'en',
            'created_by' => $author->getKey(),
        ], $attributes));
    }

    /**
     * Build sections → chapters → lessons for a course. Each spec entry is
     * [section title, [[lesson title, type, is_preview], ...]].
     *
     * @param  array<int, array{0: string, 1: array<int, array{0: string, 1: string, 2: bool}>}>  $spec
     * @return Collection<int, Lesson>
     */
    private function curriculum(Course $course, array $spec): Collection
    {
        $lessons = new Collection;
        $order = 1;

        foreach ($spec as $sectionIndex => [$sectionTitle, $lessonSpecs]) {
            $section = Section::create([
                'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
                'title' => $sectionTitle, 'order' => $sectionIndex + 1,
            ]);

            $chapter = Chapter::create([
                'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
                'section_id' => $section->id, 'title' => $sectionTitle.' — Part 1', 'order' => 1,
            ]);

            foreach ($lessonSpecs as [$title, $type, $isPreview]) {
                $lessons->push(Lesson::create([
                    'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
                    'section_id' => $section->id, 'chapter_id' => $chapter->id,
                    'title' => $title, 'type' => $type,
                    'content' => $type === 'article' ? "Written material for: {$title}" : null,
                    'order' => $order++,
                    'duration_seconds' => $type === 'video' ? 600 : 0,
                    'is_preview' => $isPreview,
                    'is_free' => $isPreview,
                ]));
            }
        }

        return $lessons;
    }

    /**
     * Create an exam with a fixed question set covering both types and all difficulties.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function exam(Workspace $workspace, Course $course, array $attributes): Exam
    {
        $exam = Exam::create(array_merge([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'duration_minutes' => 30,
        ], $attributes));

        $questions = [
            ['mcq', 'easy', 'Which command starts the Laravel dev server?', [
                ['php artisan serve', true],
                ['php artisan run', false],
                ['npm start', false],
            ]],
            ['mcq', 'medium', 'Which Eloquent method eager-loads a relationship?', [
                ['with()', true],
                ['join()', false],
                ['attach()', false],
            ]],
            ['true_false', 'easy', 'Migrations can be rolled back with php artisan migrate:rollback.', [
                ['True', true],
                ['False', false],
            ]],
            ['mcq', 'hard', 'Which queue driver runs jobs immediately in-process?', [
                ['sync', true],
                ['redis', false],
                ['database', false],
            ]],
        ];

        foreach ($questions as [$type, $difficulty, $content, $options]) {
            $question = Question::create([
                'workspace_id' => $workspace->id, 'exam_id' => $exam->id,
                'type' => $type, 'difficulty' => $difficulty,
                'content' => $content, 'points' => $difficulty === 'hard' ? 2 : 1,
                'explanation' => 'Reviewed in the course material.',
            ]);

            foreach ($options as $i => [$text, $isCorrect]) {
                QuestionOption::create([
                    'workspace_id' => $workspace->id, 'question_id' => $question->id,
                    'content' => $text, 'is_correct' => $isCorrect, 'order' => $i + 1,
                ]);
            }
        }

        return $exam;
    }

    /**
     * Build a GradeAttempt payload picking either the correct or an incorrect option
     * for every question.
     *
     * @return array<int, array{question_id: int, selected_option_ids: array<int>}>
     */
    private function answers(Exam $exam, bool $correct): array
    {
        return $exam->questions()->with('options')->get()
            ->map(fn (Question $question) => [
                'question_id' => $question->id,
                'selected_option_ids' => [
                    ($question->options->first(fn (QuestionOption $o) => $o->is_correct === $correct)
                        ?? $question->options->firstOrFail())->id,
                ],
            ])
            ->all();
    }

    private function cms(Workspace $workspace, User $author): void
    {
        $news = Category::create([
            'workspace_id' => $workspace->id, 'name' => 'News', 'slug' => 'news',
        ]);
        Category::create([
            'workspace_id' => $workspace->id, 'name' => 'Releases', 'slug' => 'releases',
            'parent_id' => $news->id,
        ]);

        $tags = new Collection;
        foreach (['Laravel', 'Study Tips', 'Announcements'] as $name) {
            $tags->push(Tag::create([
                'workspace_id' => $workspace->id, 'name' => $name, 'slug' => Str::slug($name),
            ]));
        }

        $published = Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'Laravel Mastery is now open for enrollment',
            'slug' => 'laravel-mastery-open',
            'body' => 'Our flagship backend track is live, with six lessons and a final exam.',
            'excerpt' => 'The flagship backend track is live.',
            'status' => 'published', 'published_at' => now()->subDays(2),
            'author_id' => $author->id, 'category_id' => $news->id,
            'seo_title' => 'Laravel Mastery — enroll today',
            'seo_description' => 'A production-focused Laravel course with a graded final exam and certificate.',
            'canonical_url' => 'https://nour-academy.test/blog/laravel-mastery-open',
        ]);
        $published->tags()->sync($tags->take(2)->pluck('id')->all());

        Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'How to study for the final exam',
            'slug' => 'how-to-study',
            'body' => 'Draft — outline only.',
            'status' => 'draft',
            'author_id' => $author->id, 'category_id' => $news->id,
        ]);

        $scheduled = Article::create([
            'workspace_id' => $workspace->id,
            'title' => 'Winter cohort dates',
            'slug' => 'winter-cohort-dates',
            'body' => 'Registration opens next month.',
            'status' => 'published', 'published_at' => now()->addWeek(),
            'author_id' => $author->id,
        ]);
        $scheduled->tags()->sync([$tags->reverse()->firstOrFail()->id]);
    }
}
