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

        /*
        | ⚠️ `issued_at` IS NOT TOUCHED, AND IT USED TO BE. Regenerating moved the
        | grant date to now(). That was inert while the date was invisible — no
        | screen printed it — and it became UNINTENTIONAL FORGERY the moment the
        | date started being drawn on the certificate: a teacher pressing
        | "regenerate" would silently restamp a credential earned last year with
        | today's date, and the public verify page would confirm the new one.
        | Regenerating rebuilds an artefact; it does not re-award anything.
        |
        | ⚠️ Anything regenerated BEFORE this change already lost its original
        | date, irrecoverably: the old value was overwritten in place and no
        | column, log line or activity record kept it.
        */

        event(new CertificateRegenerated($certificate));

        $this->logActivity('regenerated', $certificate, [
            'certificate_number' => $certificate->certificate_number,
        ]);

        $certificate->refresh();

        return $certificate;
    }
}
