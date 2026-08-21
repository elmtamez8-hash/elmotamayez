<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Resources;

use App\Modules\Certificates\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Certificate */
class CertificateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'certificate_number' => $this->certificate_number,
            'verification_code' => $this->verification_code,
            'issue_reason' => $this->issue_reason,
            'issued_at' => $this->issued_at,
            'course_title' => $this->course?->title,
            /*
            | ⚠️ THE FROZEN COLUMN, NEVER `$this->student?->name`. This resource is
            | what `GET /certificates/verify/{code}` returns — public, with no
            | authentication — so a live join means an anonymised account silently
            | rewrites a public statement of fact, and a severed one makes the
            | certificate verify as belonging to nobody. The name is what it was on
            | the day it was earned; see the migration that added the column.
            */
            'student_name' => $this->student_display_name,
        ];
    }
}
