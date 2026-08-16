<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Support\CreditAccounts;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::factory()->create([
            'first_name' => 'Demo',
            'last_name' => 'Teacher',
            'email' => 'teacher@example.com',
            'password' => 'password',
            'platform_role' => PlatformRole::Teacher,
        ]);

        $workspace = app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray([
                'name' => 'Demo Academy',
                'type' => 'academy',
                'slug' => 'demo-academy',
            ]),
            $owner,
        );

        app(WorkspaceContext::class)->set($workspace);

        $student = User::factory()->create([
            'first_name' => 'Demo',
            'last_name' => 'Student',
            'email' => 'student@example.com',
            'password' => 'password',
            // Not the same thing as the workspace role attached below: the device
            // limit and every other platform-level rule key off this one, so a
            // demo student without it is a student none of those rules apply to.
            'platform_role' => PlatformRole::Student,
        ]);
        $workspace->members()->attach($student->getKey(), [
            'role' => 'student',
            'joined_at' => now(),
        ]);

        $student->update(['last_workspace_id' => $workspace->getKey()]);

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($workspace->getKey());
        try {
            $student->assignRole(Roles::STUDENT);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Introduction to Laravel',
            'description' => 'Learn the fundamentals of Laravel framework.',
            'is_sequential' => true,
            'created_by' => $owner->getKey(),
        ]);

        // Published throughout: seeded content exists to be looked at. A draft
        // course would leave every demo screen and every e2e spec empty.
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'Getting Started', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'Installation & Setup', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        foreach (['Composer & Laravel Installer', 'Project Structure', 'Configuration'] as $i => $title) {
            Lesson::create([
                'workspace_id' => $workspace->id, 'course_id' => $course->id,
                'section_id' => $section->id, 'chapter_id' => $chapter->id,
                'uuid' => Str::uuid(), 'title' => $title, 'type' => 'article',
                'status' => ContentStatus::Published,
                'content' => "Content for: {$title}", 'order' => $i + 1,
                'is_preview' => $i === 0,
            ]);
        }

        // One video lesson, deliberately with no media asset behind it. Without
        // it the demo course is articles only, /learn/{lesson} has nothing
        // linking to it from seeded data, and the player e2e suite skips every
        // run — which is how it stayed unreachable for a whole phase. It plays
        // once someone uploads a file from the lesson management page.
        Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => 'Your First Route',
            'type' => 'video', 'status' => ContentStatus::Published,
            'order' => 4, 'duration_seconds' => 600,
            'is_preview' => false,
        ]);

        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'course_id' => $course->id, 'title' => 'Laravel Basics Quiz',
            'description' => 'Test your knowledge of Laravel fundamentals.',
            'duration_minutes' => 30, 'passing_score' => 70, 'max_attempts' => 3,
            'status' => 'published',
        ]);

        /*
        | ⚠️ THE LAST WRITER OF `questions.exam_id`, AND `$fillable` DID NOT STOP IT.
        | `SeedCommand` runs every seeder inside `Model::unguarded()`, so dropping
        | the column from `Question::$fillable` in 008 protected the application
        | and left this line writing it — the one place mass-assignment guards do
        | not reach. Removing it is what makes the column droppable next release;
        | left in, the drop turns `migrate --seed` into "Unknown column".
        |
        | The concept is `firstOrCreate` because migration `_000110` seeded
        | «غير مصنّف» for the workspaces alive AT THAT MOMENT, and this workspace
        | is born afterwards. A seeder that assumed the row exists would pass on a
        | database that had been migrated once and fail on a fresh one.
        */
        $concept = Concept::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Laravel Basics'],
            ['uuid' => (string) Str::uuid()],
        );

        foreach ($this->sampleQuestions() as $order => $qData) {
            $question = Question::create([
                'workspace_id' => $workspace->id, 'concept_id' => $concept->id,
                'type' => 'mcq', 'difficulty' => 'medium', 'bloom_level' => 'understand',
                'content' => $qData['content'], 'content_hash' => Question::hashOf($qData['content']),
                'points' => 1,
            ]);

            ExamItem::create([
                'workspace_id' => $workspace->id, 'exam_id' => $exam->id,
                'question_id' => $question->id, 'order' => $order + 1,
            ]);

            foreach ($qData['options'] as $i => $opt) {
                QuestionOption::create([
                    'workspace_id' => $workspace->id, 'question_id' => $question->id,
                    'content' => $opt['text'], 'is_correct' => $opt['correct'], 'order' => $i + 1,
                ]);
            }
        }

        // A booked seat for the demo student, so /schedule is not empty for the
        // account the e2e suite signs in as. Without it the sessions specs walk
        // into an empty state and prove only that an empty state renders — the
        // same silent skip that let spec 004's player ship unreachable.
        app(EnrollStudent::class)->handle($course, $student);

        $profile = TeacherProfile::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->getKey(),
        ]);

        /*
        | ⚠️ DAYS OUT, NOT MINUTES — AND THE ENTERABLE ROOM WAS THE TRADE.
        |
        | This used to be `now()->addMinutes(10)` so the room could be walked into
        | during a run that followed the seed. It bought seventy minutes: after
        | that the session is in the past, drops off the student's timetable, and
        | `e2e/sessions.spec.ts` — "جدولي ← حصة ← الغرفة" — skips itself with a
        | message telling the reader to re-seed. A suite that reports "575 passed"
        | while a walk silently stopped running an hour after the last seed is
        | worse than one that fails.
        |
        | Nothing is lost by moving it: that test asserts the room renders a
        | ticket OR a stated refusal, and the refusal is the branch that can
        | actually break. The open room is the easy case.
        */
        $startsAt = now()->addDays(3);

        $session = ClassSession::factory()->create([
            'teacher_profile_id' => $profile->id,
            'course_id' => $course->id,
            'title' => 'حصة تجريبية — مراجعة Laravel',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'duration_minutes' => 60,
            'seats_total' => 5,
        ]);

        /*
        | ⚠️ THE SEAT IS PAID FOR FIRST, BECAUSE 006 PUT A FLOOR UNDER BOOKING.
        | `BookSeat` asks `openingRefusal()`, which refuses a student with no
        | credits in this course — so a seeder written before the credit engine
        | existed now throws on the last line of the demo. The credits are posted
        | through `CreditLedger` rather than written onto the balance, because the
        | balance is the sum of its entries and a hand-written number is the
        | discrepancy `ReconcileCreditBalancesJob` reports on a demo box.
        */
        app(CreditLedger::class)->post(new CreditMovement(
            balance: app(CreditAccounts::class)->balanceFor($student, $course),
            type: CreditTransactionType::Purchase,
            credits: 4,
            sourceType: 'seeded_purchase',
            sourceId: (int) $course->id,
        ));

        app(BookSeat::class)->handle($session, $student);

        $this->command->info('Demo data seeded: workspace, teacher, student, course with lessons, exam, and a booked session.');
    }

    /**
     * @return array<int, array{content: string, options: array<int, array{text: string, correct: bool}>}>
     */
    private function sampleQuestions(): array
    {
        return [
            [
                'content' => 'Which command creates a new Laravel project?',
                'options' => [
                    ['text' => 'laravel new project', 'correct' => true],
                    ['text' => 'php create project', 'correct' => false],
                    ['text' => 'npm install laravel', 'correct' => false],
                ],
            ],
            [
                'content' => 'Where are database migrations stored?',
                'options' => [
                    ['text' => 'app/Migrations', 'correct' => false],
                    ['text' => 'database/migrations', 'correct' => true],
                    ['text' => 'storage/migrations', 'correct' => false],
                ],
            ],
        ];
    }
}
