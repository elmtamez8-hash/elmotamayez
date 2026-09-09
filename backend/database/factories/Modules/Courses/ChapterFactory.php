<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Chapter>
 */
class ChapterFactory extends Factory
{
    protected $model = Chapter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'section_id' => Section::factory(),
            /*
            | ⚠️ DERIVED FROM THE SECTION, NEVER A SECOND `Course::factory()`.
            |
            | It was one, and a bare `Chapter::factory()` therefore produced a
            | chapter whose `course_id` named a course its own section had never
            | heard of — exactly what `ManageChapters::create()` says in as many
            | words it exists to prevent. The tree walk follows `section_id` and
            | so shows the chapter; `StoreLessonRequest` asks
            | `exists('course_chapters','uuid')->where('course_id', $course)` and
            | so refuses every item added to it. Eight such rows reached the
            | development database and read as a product defect.
            |
            | Key ORDER is load-bearing: `expandAttributes()` walks the definition
            | in order and hands each closure what it has already resolved, so
            | `section_id` must be named above the closure that reads it.
            |
            | `DB::table`, not `Section::find` — the latter runs under
            | `WorkspaceScope`, and a test with a current workspace other than 1
            | would read null out of a row that is sitting right there.
            */
            'course_id' => fn (array $attributes) => DB::table('course_sections')
                ->where('id', $attributes['section_id'])
                ->value('course_id'),
            'title' => fake()->sentence(2),
            // Position assigned by HasSiblingOrder — see SectionFactory.
            'status' => ContentStatus::Published,
        ];
    }
}
