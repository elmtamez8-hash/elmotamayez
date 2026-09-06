<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ THE SECOND COLUMN IN TWO DAYS WITH READERS AND NO WRITER.
|
| `availability_slots` is written by `SubmitTeacherApplication` and by nothing
| else in the tree — once, at submission, for the life of the account. Meanwhile
| `GenerateSessionsFromAvailability` builds a teacher's whole schedule from it,
| `RequestPrivateSession` refuses anything outside it, `ReadPublicCourse` and
| `PublicTeacherDetailResource` publish it, and `AvailabilitySlot::coversUtc()`
| answers with it. A teacher whose week changed had no screen and no route.
|
| Same family as `photo_path`, found the day before.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->workspace);
});

it('replaces the whole week rather than merging into it', function (): void {
    // A merge leaves a deleted window bookable — the teacher declares Sunday and
    // Monday, removes Sunday, and students keep booking it.
    AvailabilitySlot::query()->create([
        'workspace_id' => $this->workspace->id,
        'teacher_profile_id' => $this->teacher->id,
        'day_of_week' => 0,
        'start_time' => '13:00:00',
        'end_time' => '15:00:00',
    ]);

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00'],
        ],
    ])->assertOk()->assertJsonCount(1, 'availability');

    $slots = AvailabilitySlot::query()
        ->where('teacher_profile_id', $this->teacher->id)
        ->get();

    expect($slots)->toHaveCount(1)
        ->and((int) $slots->first()?->day_of_week)->toBe(1);
});

it('refuses two windows that overlap on one day', function (): void {
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '11:00', 'end_time' => '13:00'],
        ],
    ])->assertStatus(422);

    // ⚠️ AND NOTHING IS WRITTEN. `SetAvailability` deletes before it inserts, so
    // a refusal raised INSIDE the transaction rather than above it would have
    // emptied the teacher's week on the way to saying no.
    expect(AvailabilitySlot::query()->where('teacher_profile_id', $this->teacher->id)->count())
        ->toBe(0);
});

it('refuses an empty week', function (): void {
    // Not an oversight: an empty week is a teacher nothing can be booked with at
    // all, and the way to take time off here is a freeze period — which is read
    // and never written to, so the timetable comes back untouched afterwards.
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', ['availability' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('availability');
});

it('sends the week back with the profile, sorted, so one read fills the form', function (): void {
    foreach ([[3, '15:00:00'], [1, '09:00:00'], [1, '07:00:00']] as [$day, $start]) {
        AvailabilitySlot::query()->create([
            'workspace_id' => $this->workspace->id,
            'teacher_profile_id' => $this->teacher->id,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => '23:00:00',
        ]);
    }

    Sanctum::actingAs($this->teacher->user);

    $this->getJson('/api/v1/teacher/profile')
        ->assertOk()
        ->assertJsonPath('availability.0.day_of_week', 1)
        ->assertJsonPath('availability.0.start_time', '07:00:00')
        ->assertJsonPath('availability.1.start_time', '09:00:00')
        ->assertJsonPath('availability.2.day_of_week', 3);
});

it('refuses an account with no teacher profile, and writes nothing', function (): void {
    // 403 and not 404: the account exists and is signed in, it simply has no
    // listing to give hours to. The same sentence the two neighbouring doors use.
    $student = User::factory()->create();

    Sanctum::actingAs($student);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [
            ['day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '10:00'],
        ],
    ])->assertStatus(403);

    expect(AvailabilitySlot::query()->count())->toBe(0);
});
