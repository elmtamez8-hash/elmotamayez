<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Identity\Support\PlatformRole;
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

        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'Getting Started', 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'Installation & Setup', 'order' => 1,
        ]);

        foreach (['Composer & Laravel Installer', 'Project Structure', 'Configuration'] as $i => $title) {
            Lesson::create([
                'workspace_id' => $workspace->id, 'course_id' => $course->id,
                'section_id' => $section->id, 'chapter_id' => $chapter->id,
                'uuid' => Str::uuid(), 'title' => $title, 'type' => 'article',
                'content' => "Content for: {$title}", 'order' => $i + 1,
                'is_preview' => $i === 0,
            ]);
        }

        $exam = Exam::create([
            'workspace_id' => $workspace->id, 'uuid' => Str::uuid(),
            'course_id' => $course->id, 'title' => 'Laravel Basics Quiz',
            'description' => 'Test your knowledge of Laravel fundamentals.',
            'duration_minutes' => 30, 'passing_score' => 70, 'max_attempts' => 3,
            'status' => 'published',
        ]);

        foreach ($this->sampleQuestions() as $qData) {
            $question = Question::create([
                'workspace_id' => $workspace->id, 'exam_id' => $exam->id,
                'type' => 'mcq', 'content' => $qData['content'], 'points' => 1,
            ]);

            foreach ($qData['options'] as $i => $opt) {
                QuestionOption::create([
                    'workspace_id' => $workspace->id, 'question_id' => $question->id,
                    'content' => $opt['text'], 'is_correct' => $opt['correct'], 'order' => $i + 1,
                ]);
            }
        }

        $this->command->info('Demo data seeded: workspace, teacher, student, course with lessons, and exam.');
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
