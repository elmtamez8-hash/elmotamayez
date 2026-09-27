<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Shared\Support\TimezoneName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * For a Form Request with a `timezone` field: an old spelling the browser
 * reports (`Asia/Calcutta`) becomes the one PHP lists (`Asia/Kolkata`) BEFORE
 * the `timezone` rule reads it — so the rule accepts it and `validated()` hands
 * the controller the name that is stored. See {@see TimezoneName}.
 *
 * @mixin FormRequest
 */
trait CanonicalisesTimezoneInput
{
    protected function prepareForValidation(): void
    {
        $zone = $this->input('timezone');

        if (is_string($zone)) {
            $this->merge(['timezone' => TimezoneName::canonical($zone)]);
        }
    }
}
