<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Requests;

use App\Modules\Certificates\Support\CertificateDesignLimits;
use App\Modules\Certificates\Support\CertificateTemplateRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Two ways to gain a design, and exactly one of them per request: adopt a shipped
 * template by key, or upload an image.
 *
 * ⚠️ THE KEY IS CHECKED AGAINST THE REGISTRY AND AGAIN IN THE ACTION. Not
 * belt-and-braces: `SelectCertificateDesign` is reached by a seeder and by the
 * panel with no request behind them (المبدأ الثاني), and an unknown key there
 * would write a row whose `imageUrl()` silently falls back to the default
 * template — a design that exists, is selected, and is not the one anybody chose.
 *
 * ⚠️ AND THE REFUSAL NAMES THE LIMIT AND THE FORMATS (`FR-043`). «الملف غير
 * صالح» sends a teacher to try the same file again; a sentence carrying the
 * ceiling and the accepted extensions is the difference between a refusal and a
 * dead end.
 */
class StoreDesignRequest extends FormRequest
{
    /** Authorisation is the policy, applied in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'system_key' => [
                'required_without:image',
                // ⚠️ Both at once is a request that means two different things.
                'prohibits:image',
                'nullable',
                'string',
                Rule::in(array_column(CertificateTemplateRegistry::all(), 'key')),
            ],
            'image' => [
                'required_without:system_key',
                'nullable',
                'file',
                'mimes:'.implode(',', CertificateDesignLimits::formats()),
                'max:'.CertificateDesignLimits::maxSizeKilobytes(),
            ],
            'name' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $megabytes = round(CertificateDesignLimits::maxSizeKilobytes() / 1024, 1);
        $formats = implode('، ', CertificateDesignLimits::formats());

        return [
            'image.mimes' => "الصيغ المقبولة لصورة التصميم هي: {$formats}.",
            'image.max' => "حجم صورة التصميم يجب ألّا يتجاوز {$megabytes} ميغابايت.",
            'image.file' => 'أرفق ملف صورة للتصميم.',
        ];
    }
}
