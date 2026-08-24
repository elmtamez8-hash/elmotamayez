<?php

declare(strict_types=1);

use App\Modules\Community\Actions\ReadAnnouncementStats;
use App\Modules\Community\Models\Announcement;
use App\Modules\Courses\Models\Course;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| «How many were told, and how many read it» — `FR-046`.
|
| ⚠️ BOTH NUMBERS ARE COUNTED LIVE, AND THAT IS THE REQUIREMENT RATHER THAN AN
| IMPLEMENTATION TASTE. A stored pair drifts the first time a notification is
| deleted, and then reports more readers than there were recipients — permanently,
| with nothing in the product able to notice. So deleting an old notification
| LOWERS «who was told»: the row was the evidence, and the counter is a statement
| about rows that exist.
|
| ⚠️ AND THEY COME OFF THE INDEXED COLUMNS. Without `source_type`/`source_id` the
| question is `JSON_EXTRACT(payload, …)` — a function around a column, so no index
| at all, on the fastest-growing table in the product, twice per row, inside a
| list. On the handful of rows a local fixture holds it is instantaneous, and
| green.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->students = collect(range(1, 3))->map(function (): mixed {
        $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $student);

        return $student;
    });
});

function publishedAnnouncement(): Announcement
{
    $uuid = test()->postJson('/api/v1/manage/announcements', [
        'body' => 'الحصة القادمة في القاعة الثانية.',
        'scope' => Announcement::SCOPE_ALL,
    ])->assertCreated()->json('uuid');

    test()->postJson("/api/v1/manage/announcements/{$uuid}/publish")->assertOk();

    return Announcement::query()->where('uuid', $uuid)->firstOrFail();
}

it('counts the audience and the readers off the source key', function (): void {
    Sanctum::actingAs($this->teacher);

    $announcement = publishedAnnouncement();

    $stats = app(ReadAnnouncementStats::class)->handle($announcement);

    expect($stats)->toBe(['notified' => 3, 'read' => 0]);

    // One student opens it.
    Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('recipient_user_id', $this->students->first()->getKey())
        ->update(['read_at' => now()]);

    expect(app(ReadAnnouncementStats::class)->handle($announcement))
        ->toBe(['notified' => 3, 'read' => 1]);
});

it('lowers the audience when an old notification is deleted rather than lying', function (): void {
    Sanctum::actingAs($this->teacher);

    $announcement = publishedAnnouncement();

    Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('recipient_user_id', $this->students->first()->getKey())
        ->delete();

    // A stored counter would still say three. The evidence is gone, so the
    // number is two — which is the whole reason FR-046 forbids storing it.
    expect(app(ReadAnnouncementStats::class)->handle($announcement))
        ->toBe(['notified' => 2, 'read' => 0]);
});

it('shows both counters on the publisher\'s list', function (): void {
    Sanctum::actingAs($this->teacher);

    $announcement = publishedAnnouncement();

    Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('recipient_user_id', $this->students->first()->getKey())
        ->update(['read_at' => now()]);

    $row = $this->getJson('/api/v1/manage/announcements')->assertOk()->json('0');

    expect($row['uuid'])->toBe($announcement->uuid)
        ->and($row['notified_count'])->toBe(3)
        ->and($row['read_count'])->toBe(1);
});

it('keeps the list flat however many announcements it holds', function (): void {
    Sanctum::actingAs($this->teacher);

    publishedAnnouncement();

    [$one] = countingQueries(fn () => $this->getJson('/api/v1/manage/announcements')->assertOk());

    publishedAnnouncement();
    publishedAnnouncement();

    [$three] = countingQueries(fn () => $this->getJson('/api/v1/manage/announcements')->assertOk());

    /*
    | ⚠️ A COST THAT DOES NOT MOVE, AND THE FIELD ASSERTED PRESENT BESIDE IT.
    |
    | A Resource runs once per row, so two counts inside it are two queries per
    | announcement — the `ClassSessionResource` defect, twice over. But a budget
    | test alone reports the WRONG fix as an improvement: drop the stamping and
    | the keys are simply absent, the page is cheaper, and the teacher is shown a
    | list with nobody's numbers against it. Both assertions, or neither guards
    | anything.
    */
    expect($three)->toBe($one);

    $rows = $this->getJson('/api/v1/manage/announcements')->assertOk()->json();

    foreach ($rows as $row) {
        expect($row['notified_count'])->not->toBeNull()
            ->and($row['read_count'])->not->toBeNull();
    }
});

it('counts one announcement\'s readers and not another\'s', function (): void {
    Sanctum::actingAs($this->teacher);

    $first = publishedAnnouncement();
    $second = publishedAnnouncement();

    Notification::query()
        ->where('source_type', Announcement::SOURCE_TYPE)
        ->where('source_id', $second->getKey())
        ->update(['read_at' => now()]);

    // Keyed on `source_id`, not on `source_type` alone — which would report every
    // announcement in the workspace as fully read the moment one of them was.
    expect(app(ReadAnnouncementStats::class)->handle($first))
        ->toBe(['notified' => 3, 'read' => 0]);
});
