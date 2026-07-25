<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Events\CertificateRegenerated;
use App\Modules\Certificates\Models\Certificate;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

class RegenerateCertificate extends Action
{
    use LogsActivity;

    public function handle(Certificate $certificate): Certificate
    {
        // TODO: regenerate and re-attach the certificate PDF once a PDF
        // generation pipeline (dompdf/snappy) is implemented. Until then
        // clearing is skipped because the 'certificate_pdf' collection is
        // never populated, and this would be a silent no-op.

        $certificate->update([
            'issued_at' => now(),
        ]);

        event(new CertificateRegenerated($certificate));

        $this->logActivity('regenerated', $certificate, [
            'certificate_number' => $certificate->certificate_number,
        ]);

        return $certificate->fresh();
    }
}
