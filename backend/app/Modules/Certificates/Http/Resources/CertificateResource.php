<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Resources;

use App\Modules\Certificates\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Certificate */
class CertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'certificate_number' => $this->certificate_number,
            'verification_code' => $this->verification_code,
            'issue_reason' => $this->issue_reason,
            'issued_at' => $this->issued_at,
            'course_title' => $this->course?->title,
            'student_name' => $this->student?->name,
        ];
    }
}
