<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ⚠️ NO `WorkspaceRules::exists()` ON `item_uuid`, AND THAT IS NOT AN OVERSIGHT.
 * The buyer is a student, who belongs to no workspace — so the rule would
 * resolve `WorkspaceContext::id()` to null, add no constraint, and be exactly
 * the raw `exists` it exists to replace. Worse, it would read as a guard that is
 * present. The product is resolved inside the Action, where `is_active` is the
 * predicate that actually decides.
 *
 * ⚠️ AND THE ADDRESS IS `required_if` ON NOTHING HERE. Whether a product needs
 * one is a property of the PRODUCT, not of the payload, so the refusal lives in
 * the Action beside the row that knows — a rule written here would need the item
 * loaded during validation to be correct at all.
 */
class PurchaseStoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_uuid' => ['required', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
