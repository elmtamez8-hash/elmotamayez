<?php

declare(strict_types=1);

use App\Modules\Courses\Actions\ReorderTreeNodes;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * The concurrency token actually holds, and the parking pass cannot collide.
 *
 * Both defects were invisible to the suite because both need a second writer or a
 * long-lived group, and nothing tested either.
 *
 * **The lost update.** `assertMatches` compared the submitted version against an
 * already-loaded model and the bump was a separate `increment()` later, so two
 * teachers could both pass the check and both write: no 409 anywhere, and the
 * first one's ordering gone. Which is a write to ACCESS RIGHTS — `accessTo`
 * derives what a student may open from these positions.
 *
 * **The park offset.** It was the constant 1,000, and `HasSiblingOrder` allocates
 * `max('order') + 1` with nothing renumbering after a delete — so `max(order)`
 * tracks the group's lifetime create count, not its row count. Ten rows in a
 * chapter that has seen a thousand items hold orders past 1,000, and parking the
 * first at 1,000 hit the row already sitting there: a duplicate-key 500 carrying a
 * raw SQL message.
 */
function concurrentTree(): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): array {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id]);

        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'قسم', 'status' => ContentStatus::Published, 'order' => 0,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'فصل', 'status' => ContentStatus::Published, 'order' => 0,
        ]);

        $lessons = collect(range(0, 2))->map(fn (int $i): Lesson => Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => "درس {$i}", 'type' => 'article',
            'status' => ContentStatus::Published, 'content' => 'x', 'order' => $i,
        ]));

        return [$course, $chapter, $lessons];
    });
}

it('refuses the second write computed against the same version', function (): void {
    [$course, $chapter, $lessons] = concurrentTree();

    $version = (int) $course->fresh()?->structure_version;
    $reversed = $lessons->pluck('uuid')->reverse()->values()->all();
    $original = $lessons->pluck('uuid')->all();

    $action = app(ReorderTreeNodes::class);

    // Two teachers, one version. The first lands.
    $action->handle($course, $chapter->lessons()->getQuery(), $reversed, $version);

    // The second was computed against the same map. It must 409 rather than
    // overwrite — the model handed to it still says `$version`, exactly as it
    // would in a second HTTP request that loaded the tree at the same moment.
    expect(fn () => $action->handle($course, $chapter->lessons()->getQuery(), $original, $version))
        ->toThrow(HttpResponseException::class);

    // And the first teacher's ordering survived.
    $order = $chapter->lessons()->orderBy('order')->pluck('uuid')->all();

    expect($order)->toBe($reversed);
});

it('parks above the highest live order, not at a fixed 1000', function (): void {
    [$course, $chapter, $lessons] = concurrentTree();

    // The state a long-lived chapter reaches on its own: three rows, orders far
    // apart, the top one past the old constant. No teacher has to do anything
    // unusual to get here — `max('order') + 1` never reuses a deleted position.
    $ids = $lessons->pluck('id')->all();

    DB::table('lessons')->where('id', $ids[0])->update(['order' => 998]);
    DB::table('lessons')->where('id', $ids[1])->update(['order' => 1_000]);
    DB::table('lessons')->where('id', $ids[2])->update(['order' => 1_200]);

    $version = (int) $course->fresh()?->structure_version;
    $target = [$lessons[2]->uuid, $lessons[0]->uuid, $lessons[1]->uuid];

    // With the constant, parking the first row at 1000 collided with the row
    // already there and the unique index raised a 500.
    app(ReorderTreeNodes::class)->handle($course, $chapter->lessons()->getQuery(), $target, $version);

    expect($chapter->lessons()->orderBy('order')->pluck('uuid')->all())->toBe($target)
        // Densified from zero, so the group does not carry its history forward.
        ->and($chapter->lessons()->orderBy('order')->pluck('order')->all())->toBe([0, 1, 2]);
});
