<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| `availability_slots` becomes WALL-CLOCK TIME + THE TEACHER'S IANA ZONE.
|
| ⛔ A WEEKLY WINDOW STORED IN UTC CANNOT EXPRESS A ZONE THAT OBSERVES DST. The
| product has teachers in Egypt (DST until 2026-10-29, then UTC+2) as well as in
| Qatar (UTC+3 all year). A Cairo teacher's «Tuesday 17:00» was stored as
| «Tuesday 14:00 UTC» with this week's offset, and `GenerateSessionsFromAvailability`
| turned it into 14:00 UTC every Tuesday — 17:00 in October and 16:00 from
| November. The row now says «Tuesday 17:00, Africa/Cairo» and every reader converts
| per DATE, so the lesson stays at 17:00 on the teacher's clock all year.
|
| ⚠️ THE BACKFILL REPRODUCES TODAY'S INSTANTS FOR THE CURRENT WEEK. Each UTC row is
| converted with the offset its zone has on this week's date for that weekday —
| exactly what the old client's `toLocalSlot()` showed the teacher today — so
| nothing a student or the generator sees this week moves.
|
| ⚠️ THE ZONE IS THE TEACHER'S `users.timezone`, ELSE THE PLATFORM'S. Most accounts
| hold null (the column was written only by the quiet-hours form), so most rows get
| `Asia/Qatar` — exact for a Qatari teacher, and for a Cairo teacher correct until
| 2026-10-29. Such a teacher's hours follow Egyptian DST from the next time they
| save their week, which stamps their browser's zone.
|
| ⚠️ A UTC WINDOW CAN CROSS MIDNIGHT ON THE TEACHER'S CLOCK (20:00–22:00 UTC is
| 23:00–01:00 in Doha), and a wall-clock row cannot: `SetAvailability` refuses an
| end at or before the start. Such a row is SPLIT at local midnight into two rows,
| the first ending 23:59:59. It is the same hours; what changes is that the
| generator makes two sessions of that night instead of one.
|
| ⚠️ `teacher_applications.step_data.step_4.availability` IS A SECOND COPY of the
| same rows (`SetAvailability::mirrorToOpenApplication`) that the wizard reads back
| and `SubmitTeacherApplication` rewrites the rows from, so it is converted the same
| way and stamped with the same zone.
|
| PHP, `chunkById`, never `CONVERT_TZ()` — that returns NULL on any MySQL without
| the zone tables loaded, and SQLite has none.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_slots', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('end_time');
        });

        $this->backfill(CarbonImmutable::now('UTC'));
    }

    /**
     * Every unstamped row, converted with the offsets of the week containing
     * `$now`. Public so `AvailabilityWallClockBackfillTest` can run it against
     * rows it wrote in the old shape — idempotent, because it only reads rows
     * whose `timezone` is still null.
     */
    public function backfill(CarbonImmutable $now): void
    {
        $platform = (string) config('sessions.timezone', 'Asia/Qatar');
        $valid = array_flip(timezone_identifiers_list());

        $zoneOf = static function (?string $zone) use ($platform, $valid): string {
            return is_string($zone) && isset($valid[$zone]) ? $zone : $platform;
        };

        DB::table('availability_slots')
            ->leftJoin('teacher_profiles', 'teacher_profiles.id', '=', 'availability_slots.teacher_profile_id')
            ->leftJoin('users', 'users.id', '=', 'teacher_profiles.user_id')
            ->whereNull('availability_slots.timezone')
            ->select([
                'availability_slots.id',
                'availability_slots.workspace_id',
                'availability_slots.teacher_profile_id',
                'availability_slots.day_of_week',
                'availability_slots.start_time',
                'availability_slots.end_time',
                'availability_slots.created_at',
                'users.timezone as user_timezone',
            ])
            ->chunkById(200, function ($rows) use ($zoneOf, $now): void {
                foreach ($rows as $row) {
                    $zone = $zoneOf($row->user_timezone);
                    $pieces = self::toWallClock(
                        (int) $row->day_of_week,
                        (string) $row->start_time,
                        (string) $row->end_time,
                        $zone,
                        $now,
                    );

                    $first = array_shift($pieces);

                    DB::table('availability_slots')->where('id', $row->id)->update([
                        ...$first,
                        'timezone' => $zone,
                    ]);

                    foreach ($pieces as $piece) {
                        DB::table('availability_slots')->insert([
                            ...$piece,
                            'uuid' => (string) Str::uuid(),
                            'workspace_id' => $row->workspace_id,
                            'teacher_profile_id' => $row->teacher_profile_id,
                            'timezone' => $zone,
                            'created_at' => $row->created_at,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }, 'availability_slots.id', 'id');

        DB::table('teacher_applications')
            ->leftJoin('users', 'users.id', '=', 'teacher_applications.user_id')
            ->whereNotNull('teacher_applications.step_data')
            ->select(['teacher_applications.id', 'teacher_applications.step_data', 'users.timezone as user_timezone'])
            ->chunkById(200, function ($rows) use ($zoneOf, $now): void {
                foreach ($rows as $row) {
                    $data = json_decode((string) $row->step_data, true);

                    if (! is_array($data) || ! is_array($data['step_4'] ?? null)) {
                        continue;
                    }

                    $four = $data['step_4'];

                    if (array_key_exists('timezone', $four) || ! is_array($four['availability'] ?? null)) {
                        continue;
                    }

                    $zone = $zoneOf($row->user_timezone);
                    $converted = [];

                    foreach ($four['availability'] as $slot) {
                        if (! is_array($slot) || ! isset($slot['day_of_week'], $slot['start_time'], $slot['end_time'])) {
                            continue;
                        }

                        foreach (self::toWallClock(
                            (int) $slot['day_of_week'],
                            (string) $slot['start_time'],
                            (string) $slot['end_time'],
                            $zone,
                            $now,
                        ) as $piece) {
                            $converted[] = $piece;
                        }
                    }

                    $data['step_4'] = [...$four, 'availability' => $converted, 'timezone' => $zone];

                    DB::table('teacher_applications')->where('id', $row->id)->update([
                        'step_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }, 'teacher_applications.id', 'id');
    }

    /**
     * One stored UTC window → one or two wall-clock windows in `$zone`, using the
     * offset `$zone` has on this week's date for that weekday.
     *
     * @return non-empty-list<array{day_of_week: int, start_time: string, end_time: string}>
     */
    private static function toWallClock(int $day, string $start, string $end, string $zone, CarbonImmutable $now): array
    {
        $date = $now->startOfWeek(CarbonImmutable::SUNDAY)->addDays($day)->toDateString();
        $startUtc = CarbonImmutable::parse($date.' '.$start, 'UTC');
        $endUtc = CarbonImmutable::parse($date.' '.$end, 'UTC');

        $localStart = $startUtc->setTimezone($zone);
        $localEnd = $endUtc->setTimezone($zone);

        if ($localStart->toDateString() === $localEnd->toDateString()) {
            return [[
                'day_of_week' => (int) $localStart->format('w'),
                'start_time' => $localStart->format('H:i:s'),
                'end_time' => $localEnd->format('H:i:s'),
            ]];
        }

        $pieces = [[
            'day_of_week' => (int) $localStart->format('w'),
            'start_time' => $localStart->format('H:i:s'),
            'end_time' => '23:59:59',
        ]];

        // Ending exactly at local midnight leaves nothing on the second day.
        if ($localEnd->format('H:i:s') !== '00:00:00') {
            $pieces[] = [
                'day_of_week' => (int) $localEnd->format('w'),
                'start_time' => '00:00:00',
                'end_time' => $localEnd->format('H:i:s'),
            ];
        }

        return $pieces;
    }

    /**
     * Back to UTC with this week's offset — the old model's own rule — so a
     * rollback leaves the old readers reading the hours the teacher sees today. A
     * row split at midnight on the way up stays two rows (touching, not
     * overlapping); a row that would now cross UTC midnight is clipped at it,
     * which the old model could not store either.
     */
    public function down(): void
    {
        $now = CarbonImmutable::now('UTC');
        $platform = (string) config('sessions.timezone', 'Asia/Qatar');

        DB::table('availability_slots')->orderBy('id')->chunkById(200, function ($rows) use ($now, $platform): void {
            foreach ($rows as $row) {
                $zone = is_string($row->timezone) && $row->timezone !== '' ? $row->timezone : $platform;
                $date = $now->setTimezone($zone)->startOfWeek(CarbonImmutable::SUNDAY)->addDays((int) $row->day_of_week)->toDateString();
                $start = CarbonImmutable::parse($date.' '.$row->start_time, $zone)->utc();
                $end = CarbonImmutable::parse($date.' '.$row->end_time, $zone)->utc();

                DB::table('availability_slots')->where('id', $row->id)->update([
                    'day_of_week' => (int) $start->format('w'),
                    'start_time' => $start->format('H:i:s'),
                    'end_time' => $start->toDateString() === $end->toDateString() ? $end->format('H:i:s') : '23:59:59',
                ]);
            }
        });

        Schema::table('availability_slots', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
