<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Requests;

use App\Modules\Gamification\Enums\LeaderboardPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadLeaderboardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | Shape only. WHICH scope this reader may see is the Action's decision,
            | and it has to be — a validation rule that refused an unknown course
            | would answer differently from one that refuses a course belonging to
            | somebody else, and the pair is an oracle for what exists.
            */
            'scope' => ['required', 'string', 'max:120'],
            'period' => ['nullable', Rule::in(array_column(LeaderboardPeriod::cases(), 'value'))],
        ];
    }

    public function period(): LeaderboardPeriod
    {
        return LeaderboardPeriod::from((string) ($this->input('period') ?? LeaderboardPeriod::Week->value));
    }
}
