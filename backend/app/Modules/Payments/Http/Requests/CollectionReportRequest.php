<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Data\CollectionFilter;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The period and the two narrowings — user input, validated as such.
 *
 * The report and the export share this class, which is half of what FR-034's
 * "the same data and the same restrictions" means: a second set of rules beside
 * the export is where one clause goes missing and the file leaves the building
 * carrying what the screen refuses.
 *
 * Both dates are required. A report with no period is a full scan of the fastest
 * growing table in the product, and a default of "this month" is a period the
 * reader did not choose and will not notice.
 */
class CollectionReportRequest extends FormRequest
{
    /**
     * ⚠️ THE PERMISSION IS CHECKED HERE, NOT IN THE CONTROLLER, AND THE ORDER IS
     * THE REASON. Laravel authorises before it validates; a check placed after
     * the rules would answer a refused teacher with a 422 naming every filter
     * this report accepts — a description of the platform's collection screen,
     * handed to the one person FR-033 exists to keep off it.
     *
     * A bare permission rather than a policy: there is no row to build one
     * around. The question is about the reader, not about a record.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can(Permissions::BILLING_COLLECTION_VIEW);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            // `after_or_equal` rather than `after`: a single day is a period, and
            // the query's upper bound is already the start of the day after.
            'to' => ['required', 'date', 'after_or_equal:from'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            // Bounded, because the page size is the one number a caller can use
            // to turn a paginated read back into the full scan pagination exists
            // to prevent. The export is the sanctioned way to take everything,
            // and it streams.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 50);
    }

    public function filter(): CollectionFilter
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CollectionFilter::fromArray($validated);
    }
}
