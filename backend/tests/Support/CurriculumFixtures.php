<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;

/**
 * One course tree, and the student production actually has.
 *
 * ⚠️ THE STUDENT IS BUILT WITHOUT `addWorkspaceMember()` AND WITHOUT A SEEDER,
 * so `users.last_workspace_id` stays NULL — which is the only true shape. Nothing
 * on a student's path writes that column: enrolling writes nothing and signing in
 * writes nothing, and its only writers are `CreateWorkspace`,
 * `WorkspaceContext::set()` (reached from `AcceptInvitation` and `SwitchWorkspace`,
 * both about workspace MEMBERS) and the two seeders. A helper that stamps it
 * hands the test a context production never grants, and every assertion made
 * through it is about a person who does not exist. That is the fixture defect
 * that hid five dead endpoints — including the only lesson player in the product
 * — for an entire phase.
 *
 * `WorkspaceScope` is therefore inert for this student: it adds no condition when
 * the context is null. Ownership is the whole guard, and these fixtures exist so
 * every US1 test measures it.
 */
trait CurriculumFixtures
{
    /**
     * A sequential course carrying every refusal the gate can produce, laid out
     * so each one is REACHABLE rather than hidden behind an earlier lock.
     *
     * @return array{
     *     workspace: Workspace,
     *     owner: User,
     *     student: User,
     *     course: Course,
     *     enrollment: Enrollment,
     *     lessons: array<string, Lesson>,
     *     section: Section,
     *     chapter: Chapter,
     * }
     */
    protected function curriculumTree(bool $sequential = true): array
    {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = User::factory()->create();

        $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $sequential): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'created_by' => $owner->getKey(),
                'is_sequential' => $sequential,
                'title' => 'الرياضيات — التاسع',
                'cover_path' => 'courses/cover.jpg',
            ]);

            $section = Section::create([
                'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                'title' => 'الوحدة الأولى', 'status' => ContentStatus::Published, 'order' => 1,
            ]);

            $chapter = Chapter::create([
                'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
                'course_id' => $course->getKey(),
                'title' => 'الفصل الأول', 'status' => ContentStatus::Published, 'order' => 1,
            ]);

            $order = 0;
            $make = function (string $title, string $type, array $extra = []) use ($workspace, $course, $section, $chapter, &$order): Lesson {
                $order++;

                return Lesson::create([
                    'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                    'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
                    'uuid' => Str::uuid(), 'title' => $title, 'type' => $type,
                    'status' => ContentStatus::Published, 'order' => $order,
                    'content' => 'نصّ',
                    ...$extra,
                ]);
            };

            $paper = fn (string $title): Exam => Exam::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'title' => $title,
                'passing_score' => 60,
            ]);

            $satisfiedExam = $paper('اختبار مُؤدًّى');
            $failedExam = $paper('اختبار لم يُجتَز');
            $untouchedExam = $paper('اختبار لم يُفتَح');

            $profile = TeacherProfile::factory()->create(['workspace_id' => $workspace->getKey()]);
            $session = ClassSession::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'teacher_profile_id' => $profile->getKey(),
                'course_id' => $course->getKey(),
            ]);

            $lessons = [];

            // Done, so the chain starts open.
            $lessons['done'] = $make('الدرس الأول', 'article');
            // An exam item whose gate the student has already answered.
            $lessons['exam_ok'] = $make('اختبار مؤدًّى', 'exam', [
                'reference_id' => $satisfiedExam->getKey(), 'exam_gate' => ExamGate::Attempt, 'content' => null,
            ]);
            // Open, and deliberately NOT completed: it is what locks the next one.
            $lessons['open'] = $make('الدرس الثاني', 'article');
            $lessons['sequence'] = $make('الدرس الثالث', 'article');
            // Never rendered at all (FR-004).
            $lessons['draft'] = $make('مسوّدة', 'article', ['status' => ContentStatus::Draft]);
            $lessons['archived'] = $make('مؤرشَف', 'article', ['status' => ContentStatus::Archived]);
            /*
             | ⚠️ PREVIEW, AND ITS POSITION IS THE TEST. It sits behind a locked
             | item, so a gate that asked the sequence before the preview flag
             | would refuse it — and on an expired enrolment it is the one item
             | that must still open.
             */
            $lessons['preview'] = $make('درس تجريبي', 'article', ['is_preview' => true]);
            // A recording, entitled by a seat this student does not hold.
            $lessons['no_seat'] = $make('تسجيل حصّة', 'article', ['class_session_id' => $session->getKey()]);
            // An exam item under the stronger gate, sat and failed.
            $lessons['exam_failed'] = $make('اختبار النجاح', 'exam', [
                'reference_id' => $failedExam->getKey(), 'exam_gate' => ExamGate::Pass, 'content' => null,
            ]);
            $lessons['exam_pass'] = $make('الدرس الرابع', 'article');
            // An exam item nobody has opened.
            $lessons['exam_untouched'] = $make('اختبار غير مُؤدًّى', 'exam', [
                'reference_id' => $untouchedExam->getKey(), 'exam_gate' => ExamGate::Attempt, 'content' => null,
            ]);
            $lessons['exam_attempt'] = $make('الدرس الخامس', 'article');

            $enrollment = Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            /*
             | ⚠️ THE EXAM ITEM GETS A PROGRESS ROW TOO, BECAUSE THE LISTENER
             | WRITES ONE. Submitting an exam ticks its item off; the attempt and
             | the row are two different facts and both exist in a real database.
             | A fixture with the attempt alone leaves the item open-but-unfinished
             | for ever, which sends «تابعْ من هنا» to an exam the student has
             | already passed.
             */
            foreach (['done', 'exam_ok'] as $finished) {
                $enrollment->progress()->create([
                    'workspace_id' => $workspace->getKey(),
                    'lesson_id' => $lessons[$finished]->getKey(),
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }

            $this->sitExam($satisfiedExam, $enrollment, $student, passed: true);
            $this->sitExam($failedExam, $enrollment, $student, passed: false);

            return compact('course', 'section', 'chapter', 'lessons', 'enrollment');
        });

        return [
            'workspace' => $workspace,
            'owner' => $owner,
            'student' => $student,
            ...$built,
        ];
    }

    /**
     * The same course shape at a chosen size, for the query budget.
     *
     * ⚠️ THE EXAM ITEMS AND THE RECORDING ARE IN BOTH SIZES ON PURPOSE. A budget
     * measured over plain articles alone measures the cheap third of the walk and
     * reports the exam predicate and the seat lookup as free — which is exactly
     * how the presence heartbeat's budget guarded six queries out of twenty-one.
     *
     * @return array{workspace: Workspace, student: User, course: Course, enrollment: Enrollment}
     */
    protected function curriculumOfSize(int $lessonCount): array
    {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = User::factory()->create();

        $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $lessonCount): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'created_by' => $owner->getKey(),
                'is_sequential' => true,
                'cover_path' => 'courses/cover.jpg',
            ]);

            $profile = TeacherProfile::factory()->create(['workspace_id' => $workspace->getKey()]);
            $session = ClassSession::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'teacher_profile_id' => $profile->getKey(),
                'course_id' => $course->getKey(),
            ]);

            $order = 0;

            // Several sections and chapters rather than one of each: the nesting
            // is a pass over the flat list, and a single parent would never
            // exercise it.
            for ($s = 1; $s <= 4; $s++) {
                $section = Section::create([
                    'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                    'title' => "الوحدة {$s}", 'status' => ContentStatus::Published, 'order' => $s,
                ]);

                for ($c = 1; $c <= 2; $c++) {
                    $chapter = Chapter::create([
                        'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
                        'course_id' => $course->getKey(),
                        'title' => "الفصل {$c}", 'status' => ContentStatus::Published,
                        'order' => $c,
                    ]);

                    for ($i = 0; $i < (int) ceil($lessonCount / 8); $i++) {
                        if ($order >= $lessonCount) {
                            break;
                        }

                        $order++;

                        $extra = match ($order % 7) {
                            // One exam item in every seven, each with its own
                            // paper, so the bulk predicate is asked about many.
                            3 => [
                                'type' => 'exam',
                                'content' => null,
                                'exam_gate' => ExamGate::Attempt,
                                'reference_id' => Exam::factory()->published()->create([
                                    'workspace_id' => $workspace->getKey(),
                                    'course_id' => $course->getKey(),
                                ])->getKey(),
                            ],
                            // And one recording, so the seat lookup is reached.
                            5 => ['type' => 'article', 'class_session_id' => $session->getKey()],
                            default => ['type' => 'article'],
                        };

                        Lesson::create([
                            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
                            'uuid' => Str::uuid(), 'title' => "الدرس {$order}",
                            'status' => ContentStatus::Published, 'order' => $order,
                            'content' => 'نصّ',
                            ...$extra,
                        ]);
                    }
                }
            }

            $enrollment = Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            return compact('course', 'enrollment');
        });

        return ['workspace' => $workspace, 'student' => $student, ...$built];
    }

    protected function sitExam(Exam $exam, Enrollment $enrollment, User $student, bool $passed): Attempt
    {
        return Attempt::create([
            'workspace_id' => $exam->workspace_id,
            'exam_id' => $exam->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'graded',
            'score' => $passed ? 80 : 20,
            'passed' => $passed,
            'is_practice' => false,
            'random_seed' => 1,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
        ]);
    }

    /**
     * Drop the cached workspace resolution, so the next request resolves exactly
     * as the student's own would: to null.
     */
    public function forgetWorkspace(): void
    {
        app()->forgetInstance(WorkspaceContext::class);
        app()->instance(WorkspaceContext::class, new WorkspaceContext);
    }
}
