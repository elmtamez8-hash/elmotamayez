<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Requests;

use App\Modules\LiveSessions\Enums\ClassSessionType;
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
}
