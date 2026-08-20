<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Requests;

use App\Modules\Gamification\Support\GamificationSettings;
use Illuminate\Foundation\Http\FormRequest;

class StartFocusRequest extends FormRequest
{
    /**
     * ⚠️ BOUNDED HERE *AND* IN THE ACTION.
     *
     * Not belt and braces: this class is only the door the browser uses, and the
     * Action is the one every other caller reaches. Unbounded, `minutes` of
     * 100000 mutes every optional notification for eleven weeks.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $settings = app(GamificationSettings::class);

        return [
            'minutes' => [
                'required',
                'integer',
                'min:'.$settings->focusMinMinutes(),
                'max:'.$settings->focusMaxMinutes(),
            ],
        ];
    }
}
