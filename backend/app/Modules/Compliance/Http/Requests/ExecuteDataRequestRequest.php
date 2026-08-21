<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteDataRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The gate is the policy in the controller, which needs the resolved
        // request row. A `false` here would answer 403 for a missing reason too,
        // which is a different fact about a different problem.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⚠️ REQUIRED ON A REFUSAL AND IGNORED ON AN EXECUTION, WHICH IS WHY IT
            | IS `nullable` HERE AND CHECKED BY THE ROUTE THAT NEEDS IT. Two request
            | classes for two verbs that share one shape would be two places to add
            | the next field; one class with a rule that lies about which verb it
            | serves would be worse. The refusal route reads {@see self::reason()},
            | which refuses an empty one.
            */
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The stated reason, refused when blank.
     *
     * FR-026 wants who answered and why. A refusal with an empty reason is a legal
     * answer nobody can defend, and it leaves the person told no with no way to
     * know whether to challenge it.
     */
    public function reason(): string
    {
        $reason = trim((string) $this->input('reason'));

        abort_if($reason === '', 422, 'الرفض يحتاج سبباً مكتوباً.');

        return $reason;
    }
}
