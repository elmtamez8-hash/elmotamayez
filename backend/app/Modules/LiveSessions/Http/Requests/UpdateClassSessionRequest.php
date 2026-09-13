<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            // Whether it MAY change is UpdateClassSession's call, not this one's:
            // the rule is about bookings, and validation cannot see them.
            'type' => ['sometimes', Rule::enum(ClassSessionType::class)],
            'starts_at' => ['sometimes', 'date', 'after:now'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'seats_total' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * ٠٣٥ · T075 · FR-029 — the same refusal, under the field it is about.
     *
     * `UpdateClassSession` is the door and raises this first; the controller turns
     * a `DomainException` into a 422 keyed on `type`, which puts «you cannot change
     * the time or the duration of a live lesson» under the session-type select. A
     * rule here is what lands it beside the input the teacher actually edited.
     *
     * It is a SECOND surfacing of one rule, not a second rule: the Action still
     * refuses, because a third caller reaches it with no FormRequest in front of
     * it at all (`DecideSessionRescheduleRequest`).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $session = $this->route('session');

            if (! $session instanceof ClassSession || $session->status !== ClassSessionStatus::Live) {
                return;
            }

            foreach (['starts_at', 'duration_minutes'] as $frozen) {
                if ($this->has($frozen)) {
                    $validator->errors()->add($frozen, 'لا يمكن تغيير موعد الحصة أو مدتها وهي جارية.');
                }
            }
        });
    }
}
