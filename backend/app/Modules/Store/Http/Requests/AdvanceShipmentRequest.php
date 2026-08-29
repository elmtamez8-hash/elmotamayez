<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Enums\ShipmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The enum rule says the value exists; it does not say the move is legal.
 * Whether `shipped → pending` is allowed is a question about the row's CURRENT
 * state, which validation does not have — so `AdvanceShipment` asks
 * `ShipmentStatus::next()` and answers with a sentence naming both ends.
 */
class AdvanceShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ShipmentStatus::class)],
            'tracking_ref' => ['nullable', 'string', 'max:255'],
        ];
    }
}
