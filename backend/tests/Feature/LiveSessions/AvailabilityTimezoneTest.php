<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\GenerateSessionsFromAvailability;
use App\Modules\Marketplace\Actions\SetAvailability;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| A teacher's weekly availability is WALL-CLOCK time on the teacher's own clock,
| with that clock named (owner decision 2026-09-25).
|
| ⛔ THE DEFECT THIS REPLACED: the row was stored in UTC with the offset of the
| week it was saved in. Qatar has no daylight saving, so for Doha it was exact.
| Egypt leaves daylight saving on 2026-10-29 (UTC+3 → UTC+2), and a Cairo
| teacher's «Tuesday 17:00» — stored as «Tuesday 14:00 UTC» — generated lessons
| at 14:00Z all year: 17:00 on their clock in October, 16:00 from November.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function cairoTuesdayAtFive(TeacherProfile $teacher): AvailabilitySlot
{
    return AvailabilitySlot::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'day_of_week' => 2,
        'start_time' => '17:00:00',
        'end_time' => '18:00:00',
        'timezone' => 'Africa/Cairo',
    ]);
}

/** @return list<string> */
function generatedStarts(TeacherProfile $teacher, Course $course, mixed $actor, string $from, string $to): array
{
    $result = app(GenerateSessionsFromAvailability::class)->handle(
        $teacher,
        $course,
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        $actor,
    );

    return array_map(
        static fn ($session): string => $session->starts_at->copy()->utc()->format('Y-m-d H:i'),
        $result['created'],
    );
}

it('generates a Cairo teacher\'s Tuesday 17:00 at 17:00 Cairo before Egypt leaves daylight saving', function (): void {
    cairoTuesdayAtFive($this->teacher);
    CarbonImmutable::setTestNow('2026-10-20 08:00:00');

    // 2026-10-27 is a Tuesday on summer time: 17:00 Cairo = 14:00Z.
    expect(generatedStarts($this->teacher, $this->course, $this->owner, '2026-10-26', '2026-10-28'))
        ->toBe(['2026-10-27 14:00']);
});

it('generates it at 17:00 Cairo AFTER 2026-10-29 too — 15:00Z, not the stored 14:00Z', function (): void {
    cairoTuesdayAtFive($this->teacher);
    CarbonImmutable::setTestNow('2026-10-20 08:00:00');

    // 2026-11-03 is the first Tuesday on winter time: 17:00 Cairo = 15:00Z.
    expect(generatedStarts($this->teacher, $this->course, $this->owner, '2026-11-02', '2026-11-04'))
        ->toBe(['2026-11-03 15:00']);
});

it('keeps the local hour across the change inside one range', function (): void {
    cairoTuesdayAtFive($this->teacher);
    CarbonImmutable::setTestNow('2026-10-20 08:00:00');

    $starts = generatedStarts($this->teacher, $this->course, $this->owner, '2026-10-26', '2026-11-04');

    expect($starts)->toBe(['2026-10-27 14:00', '2026-11-03 15:00']);

    foreach ($starts as $start) {
        expect(CarbonImmutable::parse($start, 'UTC')->setTimezone('Africa/Cairo')->format('D H:i'))->toBe('Tue 17:00');
    }
});

it('leaves a Doha teacher\'s hours where they were — Qatar has no daylight saving', function (): void {
    AvailabilitySlot::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'day_of_week' => 2,
        'start_time' => '17:00:00',
        'end_time' => '18:00:00',
        'timezone' => 'Asia/Qatar',
    ]);
    CarbonImmutable::setTestNow('2026-10-20 08:00:00');

    expect(generatedStarts($this->teacher, $this->course, $this->owner, '2026-10-26', '2026-11-04'))
        ->toBe(['2026-10-27 14:00', '2026-11-03 14:00']);
});

it('asks a private-session request on the slot\'s own clock, on both sides of the change', function (): void {
    $slot = cairoTuesdayAtFive($this->teacher);

    $october = CarbonImmutable::parse('2026-10-27 14:00', 'UTC');
    $november = CarbonImmutable::parse('2026-11-03 15:00', 'UTC');

    expect($slot->containsSpan($october, $october->addHour()))->toBeTrue()
        ->and($slot->containsSpan($november, $november->addHour()))->toBeTrue()
        // The stored-UTC hour in winter is 16:00 on the teacher's clock — outside.
        ->and($slot->containsSpan(CarbonImmutable::parse('2026-11-03 14:00', 'UTC'), CarbonImmutable::parse('2026-11-03 15:00', 'UTC')))->toBeFalse();
});

it('says «available now» on the slot\'s own clock', function (): void {
    cairoTuesdayAtFive($this->teacher);

    $inside = CarbonImmutable::parse('2026-11-03 15:30', 'UTC');   // 17:30 Cairo
    $outside = CarbonImmutable::parse('2026-11-03 14:30', 'UTC');  // 16:30 Cairo

    expect(AvailabilitySlot::query()->covering($inside)->count())->toBe(1)
        ->and(AvailabilitySlot::query()->covering($outside)->count())->toBe(0);
});

it('stores what the teacher typed and the clock they typed it on', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [['day_of_week' => 2, 'start_time' => '17:00', 'end_time' => '18:00']],
        'timezone' => 'Africa/Cairo',
    ])->assertOk()
        ->assertJsonPath('availability.0.start_time', '17:00:00')
        ->assertJsonPath('availability.0.timezone', 'Africa/Cairo');

    $row = AvailabilitySlot::query()->sole();

    expect($row->start_time)->toBe('17:00:00')
        ->and($row->timezone)->toBe('Africa/Cairo');
});

it('refuses a save that does not name its clock — a stale tab would send UTC hours', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [['day_of_week' => 2, 'start_time' => '14:00', 'end_time' => '15:00']],
    ])->assertStatus(422)->assertJsonValidationErrors('timezone');

    expect(AvailabilitySlot::query()->count())->toBe(0);
});

it('refuses an unknown zone in the Action as well as at the door', function (): void {
    expect(fn () => app(SetAvailability::class)->handle(
        $this->teacher,
        [['day_of_week' => 2, 'start_time' => '17:00', 'end_time' => '18:00']],
        'Mars/Olympus',
    ))->toThrow(DomainException::class);
});

function wallClockMigration(): object
{
    return require base_path('app/Modules/Marketplace/Database/Migrations/2026_09_25_000200_store_availability_as_wall_clock.php');
}

function oldUtcRow(TeacherProfile $teacher, int $day, string $start, string $end): int
{
    $slot = AvailabilitySlot::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'day_of_week' => $day,
        'start_time' => $start,
        'end_time' => $end,
    ]);

    // The shape before 2026-09-25: UTC hours and no zone.
    DB::table('availability_slots')->where('id', $slot->getKey())->update(['timezone' => null]);

    return (int) $slot->getKey();
}

describe('the backfill of rows stored in UTC', function (): void {
    it('reproduces this week\'s instants on the teacher\'s own clock', function (): void {
        $this->owner->forceFill(['timezone' => 'Africa/Cairo'])->save();
        $id = oldUtcRow($this->teacher, 2, '14:00:00', '15:00:00');

        wallClockMigration()->backfill(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

        $row = DB::table('availability_slots')->where('id', $id)->first();

        expect([$row->day_of_week, $row->start_time, $row->end_time, $row->timezone])
            ->toBe([2, '17:00:00', '18:00:00', 'Africa/Cairo']);

        // And this week's occurrence is the instant the old row named.
        $slot = AvailabilitySlot::query()->findOrFail($id);
        expect($slot->occurrenceOn('2026-10-20')['starts_at']->format('Y-m-d H:i'))->toBe('2026-10-20 14:00');
    });

    it('stamps the platform zone on a teacher who never chose one', function (): void {
        $id = oldUtcRow($this->teacher, 0, '13:00:00', '17:00:00');

        wallClockMigration()->backfill(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

        $row = DB::table('availability_slots')->where('id', $id)->first();

        expect([$row->day_of_week, $row->start_time, $row->end_time, $row->timezone])
            ->toBe([0, '16:00:00', '20:00:00', 'Asia/Qatar']);
    });

    it('splits a window that crosses midnight on the teacher\'s clock', function (): void {
        // 20:00–22:00 UTC Sunday is 23:00 Sunday → 01:00 Monday in Doha.
        $id = oldUtcRow($this->teacher, 0, '20:00:00', '22:00:00');

        wallClockMigration()->backfill(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

        $rows = DB::table('availability_slots')
            ->where('teacher_profile_id', $this->teacher->getKey())
            ->orderBy('id')
            ->get(['id', 'day_of_week', 'start_time', 'end_time', 'timezone'])
            ->map(fn ($row): array => [(int) $row->day_of_week, $row->start_time, $row->end_time, $row->timezone])
            ->all();

        expect($rows)->toBe([
            [0, '23:00:00', '23:59:59', 'Asia/Qatar'],
            [1, '00:00:00', '01:00:00', 'Asia/Qatar'],
        ])->and((int) DB::table('availability_slots')->orderBy('id')->value('id'))->toBe($id);
    });

    it('converts the application\'s copy of the week too, and stamps its zone', function (): void {
        $this->owner->forceFill(['timezone' => 'Africa/Cairo'])->save();

        $applicationId = DB::table('teacher_applications')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->owner->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'status' => 'draft',
            'current_step' => 4,
            'step_data' => json_encode(['step_4' => [
                'hourly_rate' => '100.00',
                'availability' => [['day_of_week' => 2, 'start_time' => '14:00:00', 'end_time' => '15:00:00']],
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        wallClockMigration()->backfill(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

        $four = json_decode((string) DB::table('teacher_applications')->where('id', $applicationId)->value('step_data'), true)['step_4'];

        expect($four['timezone'])->toBe('Africa/Cairo')
            ->and($four['availability'])->toBe([['day_of_week' => 2, 'start_time' => '17:00:00', 'end_time' => '18:00:00']])
            ->and($four['hourly_rate'])->toBe('100.00');
    });
});
