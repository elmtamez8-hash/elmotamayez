<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Resources;

use App\Modules\Certificates\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an unauthenticated stranger holding a verification code may see.
 *
 * ⚠️ A SEPARATE CLASS FROM `CertificateResource`, FOR TWO MEASURED REASONS.
 *
 * 1. `design` costs a query, and a Resource runs ONCE PER ROW — putting it on the
 *    shared resource makes `GET /certificates` an N+1 by construction, fifteen
 *    extra reads per page on a screen that has no use for artwork.
 * 2. The shared resource emits `uuid`, which two authenticated screens use as a
 *    row key and the regenerate route resolves by. The public payload is an
 *    ALLOWLIST (FR-032) and has no business carrying an internal handle, so the
 *    two shapes genuinely differ rather than one being a subset by accident.
 *
 * Every field here is either frozen at issue or the live PRESENTATION. Nothing
 * else may be added: `PublicExposureTest`'s question, asked of this file.
 */
/** @mixin Certificate */
class PublicCertificateResource extends JsonResource
{
    /** @param array{image_url: string, boxes: array<string, array<string, mixed>>} $design */
    public function __construct(Certificate $certificate, private readonly array $design)
    {
        parent::__construct($certificate);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'certificate_number' => $this->certificate_number,
            'verification_code' => $this->verification_code,
            'issue_reason' => $this->issue_reason,
            'issued_at' => $this->issued_at,
            'course_title' => $this->course?->title,
            // The three frozen columns, never a live join — see IssueCertificate.
            'student_name' => $this->student_display_name,
            'teacher_name' => $this->teacher_display_name,
            'subject_name' => $this->subject_display_name,
            'design' => $this->design,
        ];
    }
}
