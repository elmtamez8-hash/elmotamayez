<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateQuietHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
        ];
    }

    /**
     * Half a window is not a window. Accepting one end would leave QuietHours
     * silently doing nothing, which reads to the user as the setting being
     * ignored.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('quiet_hours_start');
            $end = $this->input('quiet_hours_end');

            if (($start === null) !== ($end === null)) {
                $validator->errors()->add('quiet_hours_end', 'حدّد بداية فترة الهدوء ونهايتها معاً.');
            }
        });
    }
}
