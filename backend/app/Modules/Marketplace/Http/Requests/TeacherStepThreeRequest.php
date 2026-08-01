<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 3 — the documents step, which accepts no documents.
 *
 * FR-071 makes this UI-only: the applicant acknowledges what they will be asked
 * for, and nothing is uploaded. Real identity verification needs its own security
 * specification (retention, access control, deletion), and shipping a file field
 * ahead of that would collect passport scans into a system with no answer for any
 * of it.
 *
 * The endpoint therefore rejects a file payload rather than ignoring it. Silently
 * dropping an upload would let a client believe the document was stored.
 */
class TeacherStepThreeRequest extends FormRequest
{
    private const ALLOWED_KEYS = ['documents_acknowledged'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'documents_acknowledged' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Any key beyond the acknowledgement — an uploaded file, a base64 blob,
            // a url someone hoped we would fetch. Rejecting the whole payload is
            // narrower than blocklisting field names one at a time.
            $unexpected = array_diff(array_keys($this->all()), self::ALLOWED_KEYS);

            if ($unexpected !== [] || $this->allFiles() !== []) {
                $validator->errors()->add(
                    'documents',
                    'رفع المستندات غير متاح في هذه الخطوة؛ يُطلب التحقق لاحقاً عبر قناة آمنة.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'documents_acknowledged.accepted' => 'يجب الإقرار بالمستندات المطلوبة.',
        ];
    }
}
