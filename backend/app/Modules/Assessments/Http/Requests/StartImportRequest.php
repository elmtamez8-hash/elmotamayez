<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Requests;

use App\Modules\Assessments\Enums\DuplicatePolicy;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The file and the duplicate policy, together, in one request.
 *
 * ⚠️ THE POLICY IS REQUIRED HERE BECAUSE IT CANNOT BE ASKED FOR LATER (Q7). The
 * import runs in a queued job and by the time it meets its first duplicate the
 * teacher has closed the tab — a job that stops to ask a question is a job that
 * hangs for ever. Defaulting it silently is worse: the teacher who wanted the
 * copies gets one row, or the one who wanted none gets nine hundred.
 *
 * CSV only, and the screen says so in words rather than refusing an .xlsx with
 * no reason. XLSX is a zip of XML and needs a library nobody has installed;
 * pretending to accept it and failing in the job would be a report the teacher
 * waits five minutes for.
 */
class StartImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::QUESTIONS_MANAGE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `mimes:csv,txt` rather than a client-supplied content type: browsers
            // send text/csv, application/vnd.ms-excel and text/plain for the same
            // file depending on what is installed on the machine.
            // ⚠️ 10 MB HARDCODED, AND THAT IS A KNOWN DEVIATION. Operational
            // numbers belong in `platform_settings` where an operator can tune
            // them — spec 004's upload limits and grant TTL live there. This one
            // does not, because Assessments has no settings reader yet and adding
            // one for a single number is a table for nobody. It moves the day the
            // module needs its second tunable.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'duplicate_policy' => ['required', Rule::enum(DuplicatePolicy::class)],
        ];
    }
}
