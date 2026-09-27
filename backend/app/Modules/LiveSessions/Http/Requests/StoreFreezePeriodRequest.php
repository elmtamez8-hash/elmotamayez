<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Shared\Support\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `student_uuid` absent means the whole workspace — every student of this
 * teacher (FR-039). It is checked against `users` and then against an actual
 * booking inside the Action, because a uuid that exists is a different question
 * from a uuid this teacher may freeze.
 */
class StoreFreezePeriodRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⛔ NOT IN THE PAST (audit 2026-09-27). A period dated back into last
            | month counted against LAST month's ceiling (the month of
            | `starts_on`), so it walked past «two a month», and it extended
            | every subscription by days the student had already used.
            | «Today» is the PLATFORM's day — Laravel's bare `today` is the app
            | zone (UTC), which at 01:00 in Doha is still yesterday. Asked again
            | in `CreateFreezePeriod`, the door the panel and seeders share.
            */
            'starts_on' => [
                'required',
                'date',
                'after_or_equal:'.CarbonImmutable::now(UserClock::platformZone())->toDateString(),
            ],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'student_uuid' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
