<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Actions\CreateStudyRoom;
use App\Modules\Assessments\Enums\Difficulty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a student is asking for when they open a room (FR-013).
 *
 * ⚠️ NEITHER UUID CARRIES AN `exists` RULE, deliberately — `StartAdaptiveRequest`'s
 * reason exactly. Laravel's `exists` is a raw query with no tenant condition, so
 * it answers «does this concept exist anywhere on the platform», which is a
 * yes/no oracle over every other teacher's taxonomy for anybody who can loop. The
 * Action resolves both inside the workspace the student is actually enrolled at.
 *
 * ⚠️ AND THE CEILINGS ARE REPEATED IN THE ACTION RATHER THAN LIVING ONLY HERE.
 * A form shapes one request and not the next: the seeder, a console command and
 * a future import all reach `CreateStudyRoom` with no form behind them, and
 * `study_room_participants.score` is an `unsignedSmallInteger` that strict MySQL
 * refuses to overflow while SQLite truncates in silence.
 */
class CreateStudyRoomRequest extends FormRequest
{
    /**
     * ⚠️ NO PERMISSION CHECK, AND THAT IS THE FIX RATHER THAN THE OMISSION.
     * spatie runs in team mode and a student is a member of no workspace, so
     * their team id is null and EVERY `can()` for them is false. The
     * authorisation is the active enrolment, which the Action reads to draw the
     * paper.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Required, because the answer decides which per-workspace feature
            // switch is read — and a guess there is a guess about a 403.
            'teacher' => ['required', 'uuid'],
            // Null means «the whole pool», which widens FR-013 rather than
            // narrowing it.
            'concept' => ['nullable', 'uuid'],
            'difficulty' => ['nullable', Rule::in(array_column(Difficulty::cases(), 'value'))],
            'question_count' => ['required', 'integer', 'min:1', 'max:'.CreateStudyRoom::MAX_QUESTIONS],
            'max_participants' => ['required', 'integer', 'min:1', 'max:'.CreateStudyRoom::MAX_PARTICIPANTS],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            // Zero is «start now», which is the ordinary case: the delay exists
            // so friends can be invited before the clock runs.
            'starts_in_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
        ];
    }
}
