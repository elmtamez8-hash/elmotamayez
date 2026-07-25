<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Events;

use App\Modules\Certificates\Models\Certificate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificateRegenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
    ) {}
}
