<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Events\MessagePosted;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Who is told when a student writes into their private thread.
|
| ⛔ Until 2026-09-30 the answer was «every member of the workspace whom
| `mayActOnStudent()` lets through» — and that directory answers `true` for
| anybody who is not a confined assistant. So a STUDENT-role member, and a
| staff member with no chat access at all, were told by name and with a link
| each time this student wrote. The recipients are now the thread's staff side:
| `ConversationPolicy::teacherSide()`'s three conditions (a non-student member,
| `chat.reply` in this workspace, the assistant scope), read in a fixed number of
| queries.
|
| The recipients are read off the `MessagePosted` event, where `PostMessage`
| puts them, so the count below is the Action's own and not the notification
| pipeline's.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->nearCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->nearCourse, $this->student);

    Sanctum::actingAs($this->student);

    $this->conversationUuid = (string) $this->postJson('/api/v1/conversations', [
        'body' => 'السلام عليكم',
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json('uuid');

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());
});

/** An assistant with a live assignment, optionally holding `chat.reply` and confined to one course. */
function cnrAssistant(bool $chat = true, ?Course $confinedTo = null): User
{
    $test = test();
    $assistant = $test->addWorkspaceMember($test->workspace, Roles::ASSISTANT_TEACHER);

    if ($chat) {
        $assistant->givePermissionTo(Permissions::CHAT_REPLY);
    }

    $assignment = AssistantAssignment::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'assistant_user_id' => $assistant->getKey(),
        'invited_by_user_id' => $test->owner->getKey(),
    ]);

    if ($confinedTo !== null) {
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $assignment->getKey(),
            'course_id' => $confinedTo->getKey(),
        ]);
    }

    return $assistant;
}

/**
 * The student writes once; the recipients the Action named, and the queries it
 * cost.
 *
 * @return array{0: list<string>, 1: int}
 */
function cnrWrite(): array
{
    $test = test();

    app()->forgetScopedInstances();
    Event::fake([MessagePosted::class]);
    Sanctum::actingAs($test->student);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->postJson("/api/v1/conversations/{$test->conversationUuid}/messages", [
        'body' => 'سؤال عن الواجب',
    ])->assertCreated();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $recipients = [];

    Event::assertDispatched(MessagePosted::class, function (MessagePosted $event) use (&$recipients): bool {
        $recipients = array_values($event->recipientUuids);

        return true;
    });

    return [$recipients, $queries];
}

it('tells the thread\'s staff side and nobody else in the workspace', function (): void {
    $near = cnrAssistant(confinedTo: $this->nearCourse);
    $far = cnrAssistant(confinedTo: $this->farCourse);
    $unconfined = cnrAssistant();
    $noChat = cnrAssistant(chat: false);

    // ⚠️ A STUDENT-ROLE MEMBER WHO HOLDS `chat.reply` DIRECTLY — without the
    // grant the permission filter alone would drop them, and the pivot rule this
    // test is for would go unmeasured.
    $classmate = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $classmate->givePermissionTo(Permissions::CHAT_REPLY);
    $this->createEnrollment($this->workspace, $this->nearCourse, $classmate);

    // A teacher of ANOTHER workspace, holding chat.reply there.
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();

    [$recipients] = cnrWrite();

    expect($recipients)
        ->toContain((string) $this->owner->uuid)
        ->toContain((string) $near->uuid)
        ->toContain((string) $unconfined->uuid)
        ->not->toContain((string) $far->uuid)
        ->not->toContain((string) $noChat->uuid)
        ->not->toContain((string) $classmate->uuid)
        ->not->toContain((string) $otherOwner->uuid)
        // The sender never hears about their own message.
        ->not->toContain((string) $this->student->uuid)
        ->toHaveCount(3);
});

it('reads the recipients in a fixed number of queries, however many staff the workspace has', function (): void {
    cnrAssistant(confinedTo: $this->nearCourse);
    cnrAssistant(confinedTo: $this->farCourse);
    cnrAssistant();

    // Warm until steady: the first write carries one-off reads.
    cnrWrite();
    [$few, $fewQueries] = cnrWrite();

    foreach (range(1, 6) as $i) {
        cnrAssistant(confinedTo: $i % 2 === 0 ? $this->nearCourse : $this->farCourse);
        cnrAssistant(chat: false);
        cnrAssistant();
    }

    [$many, $manyQueries] = cnrWrite();

    expect(count($many))->toBeGreaterThan(count($few))
        ->and($manyQueries)->toBe($fewQueries);
});
