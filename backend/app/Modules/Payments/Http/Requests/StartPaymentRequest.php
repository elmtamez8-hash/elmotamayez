<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which provider should take this payment.
 *
 * Optional: with nothing named, the configured default answers. A student's
 * client has no business knowing the platform's provider roster, and a required
 * field here would put one in it.
 */
class StartPaymentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Validated as a string only. Whether it names a live provider is
            // the registry's answer, and its refusal is a 404 — the same one an
            // unknown route gives, which is all an id-guesser learns.
            'provider' => ['nullable', 'string', 'max:50'],
        ];
    }
}
