<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordPayoutRequest extends FormRequest
{
    /** Authorisation is the policy's job, on the route. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Optional in the first release: the transfer is made by hand and the
            // bank reference is sometimes only known the next day. It is what the
            // teacher matches against their statement, so the notification says
            // so plainly when it is missing rather than sending a blank.
            'reference' => ['nullable', 'string', 'max:191'],
            'method' => ['nullable', 'string', 'max:32'],
        ];
    }
}
