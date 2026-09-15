<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;

/**
 * شجرةٌ صغيرةٌ متسلسلةٌ فيها **عنصرٌ مقصورٌ يقفُ في وسطِ الترتيب** (٠٢٦).
 *
 * ⛔ **وموضعُ المقصورِ هو الاختبارُ كلُّه.** عنصرٌ لا يراه الطالبُ ولا يستطيعُ
 * إتمامَه، إن وقفَ في طريقِ ما بعدَه، يُقفِلُ بقيّةَ الكورسِ عليه **إلى الأبد**
 * — فلا يقعُ حدثُ الإتمامِ ولا تصدرُ شهادةٌ أبداً. وهي عائلةُ أسوأِ عطلٍ يسجّلُه
 * هذا المستودع، تصلُ هنا من بابِ الترتيبِ بدلاً من بابِ المقام. وتجهيزةٌ يقعُ
 * المقصورُ في آخرِها لا ترى ذلكَ أبداً.
 *
 * ⚠️ **وفي ملفٍّ مستقلٍّ عن `CurriculumFixtures` عن قصد**: تلك التجهيزةُ يقرؤُها
 * أحدَ عشرَ ملفّاً تؤكّدُ خرائطَ رموزٍ بالضبط، وإضافةُ صفَّينِ إليها تكسرُ
 * توكيداتٍ لا علاقةَ لها بهذه المواصفة.
 */
trait ScopedTreeFixture
{
    /**
     * @return array{
     *     workspace: Workspace,
     *     owner: User,
     *     student: User,
     *     course: Course,
     *     enrollment: Enrollment,
     *     mine: Cohort,
     *     theirs: Cohort,
     *     lessons: array<string, Lesson>,
     *     chapter: Chapter,
     * }
     */
    protected function scopedTree(): array
    {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        // بلا `addWorkspaceMember` وبلا بذرة: `last_workspace_id` يبقى فارغاً،
        // وهو الشكلُ الوحيدُ الصحيحُ لطالبٍ سجّلَ نفسَه واشترى.
        $student = User::factory()->create();

        $built = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'created_by' => $owner->getKey(),
                'is_sequential' => true,
                'cover_path' => 'courses/cover.jpg',
            ]);

            $section = Section::create([
                'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
            ]);

            $chapter = Chapter::create([
                'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
                'course_id' => $course->getKey(),
                'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
            ]);

            $order = 0;
            $make = function (string $title, array $extra = []) use ($workspace, $course, $section, $chapter, &$order): Lesson {
                $order++;

                return Lesson::create([
                    'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                    'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
                    'uuid' => Str::uuid(), 'title' => $title, 'type' => 'article',
                    'status' => ContentStatus::Published, 'order' => $order,
                    'content' => 'نصّ',
                    ...$extra,
                ]);
            };

            $cohortNamed = fn (string $name): Cohort => Cohort::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $owner->getKey(),
                'name' => $name,
            ]);

            $mine = $cohortNamed('مجموعتي');
            $theirs = $cohortNamed('مجموعةٌ أخرى');

            CohortMembership::query()->create([
                'workspace_id' => $workspace->getKey(),
                'cohort_id' => $mine->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'joined_at' => now(),
            ]);

            $lessons = [
                'shared_first' => $make('المشتَركُ الأوّل'),
                'scoped_away' => $make('مقصورٌ على مجموعةٍ أخرى'),
                'shared_last' => $make('المشتَركُ الأخير'),
            ];

            LessonCohortScope::query()->create([
                'workspace_id' => $workspace->getKey(),
                'lesson_id' => $lessons['scoped_away']->getKey(),
                'cohort_id' => $theirs->getKey(),
            ]);

            $enrollment = Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            // الأوّلُ متَمٌّ، فالسلسلةُ تبدأُ مفتوحةً وما بعدَها يقولُ شيئاً.
            $enrollment->progress()->create([
                'workspace_id' => $workspace->getKey(),
                'lesson_id' => $lessons['shared_first']->getKey(),
                'status' => 'completed',
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            return compact('course', 'chapter', 'lessons', 'enrollment', 'mine', 'theirs');
        });

        return [
            'workspace' => $workspace,
            'owner' => $owner,
            'student' => $student,
            ...$built,
        ];
    }
}
