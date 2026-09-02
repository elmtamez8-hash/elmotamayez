<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One field, and the absence of the second is the requirement.
 *
 * ⚠️ THE DURATION IS NOT ACCEPTED HERE (FR-016أ). The teacher declares it on the
 * course and the student READS it; a `duration_minutes` in this body would let
 * the browser decide what a credit buys, what the teacher is paid for, and how
 * much of their calendar is taken — three things «حصة» is supposed to mean one
 * of. `RequestPrivateSession` copies it off the course, and the form test
 * asserts the payload carries ONE key.
 *
 * ⚠️ AND `authorize()` IS `true` BECAUSE THE ANSWER IS NOT A PERMISSION. The
 * student is a member of no workspace, so the spatie team id is null and every
 * `can()` is false for them; the real question is «did you buy this course»,
 * which only `EnrollmentDirectory` can answer and the Action asks.
 */
class RequestPrivateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // An absolute instant, `date` rather than `date_format`: the client
            // sends ISO-8601 with an offset and the Action parses it to UTC. A
            // wall-clock string would be an hour wrong twice a year.
            'starts_at' => ['required', 'date'],
        ];
    }
}
