<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Courses;

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'chapter_id' => Chapter::factory(),
            /*
            | ⚠️ BOTH DERIVED FROM THE CHAPTER — the same two lines
            | `ManageLessons::create()` writes, and for the same reason its own
            | comment gives: asking for a section as well is what let a lesson
            | hold a section from one branch and a chapter from another. All
            | three were independent factories here, so a bare
            | `Lesson::factory()` created four unrelated courses and left a
            | lesson none of them could reach. See `ChapterFactory` for why the
            | key order and `DB::table` both matter.
            |
            | The ceiling: `->for($course)` overrides `course_id` before this
            | closure runs, so it still gets a chapter of its own. Pin the
            | chapter instead when the course has to be a particular one.
            */
            'section_id' => fn (array $attributes) => DB::table('course_chapters')
                ->where('id', $attributes['chapter_id'])
                ->value('section_id'),
            'course_id' => fn (array $attributes) => DB::table('course_chapters')
                ->where('id', $attributes['chapter_id'])
                ->value('course_id'),
            'uuid' => Str::uuid(),
            'title' => fake()->sentence(4),
            'type' => fake()->randomElement(['article', 'video', 'pdf', 'file']),
            // Published by default: the overwhelming majority of tests are about
            // a lesson a student can reach, and a factory that produced drafts
            // would make every one of them set the state by hand.
            'status' => ContentStatus::Published,
            'content' => fake()->paragraphs(3, true),
            // Position assigned by HasSiblingOrder — see SectionFactory.
            'duration_seconds' => fake()->numberBetween(60, 3600),
            'is_preview' => false,
            'is_free' => false,
        ];
    }

    public function preview(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_preview' => true,
        ]);
    }
}
