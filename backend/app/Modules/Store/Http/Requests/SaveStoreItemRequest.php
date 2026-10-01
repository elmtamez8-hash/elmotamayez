<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Requests;

use App\Modules\Store\Enums\StoreItemKind;
use App\Shared\Support\WorkspaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ⚠️ UUIDS ON THE WIRE, AND `WorkspaceRules::exists()` RATHER THAN `exists:`.
 * Laravel's `exists` rule is a raw query that ignores the global scope, so the
 * plain form lets one teacher attach another teacher's video to a product and
 * sell it. The Action re-checks anyway — validation is one of four entrances,
 * and the seeder runs inside `Model::unguarded()`.
 */
class SaveStoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(StoreItemKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'excerpt' => ['nullable', 'string', 'max:200'],
            // MAJOR units, as typed («150» or «150.50») — the money rule in
            // docs/gotchas/billing.md. `StoreItemData` converts through `MinorUnits`.
            'price' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'currency' => ['sometimes', 'string', 'size:3'],
            // Platform reference data (no workspace), and only an ACTIVE subject —
            // the picker reads `/signup/subjects`, and the door asks what it shows.
            'subject_slug' => ['nullable', 'string', Rule::exists('subjects', 'slug')->where('is_active', true)],
            'course_uuid' => ['nullable', 'uuid', WorkspaceRules::exists('courses', 'uuid')],
            'media_asset_uuid' => ['nullable', 'uuid', WorkspaceRules::exists('media_assets', 'uuid')],
            // Bounded on both sides: `stock` is a SIGNED integer column, and an
            // unbounded one is a number that overflows the column MySQL will
            // reject in strict mode while SQLite stores it happily.
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'shipping_fee' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
