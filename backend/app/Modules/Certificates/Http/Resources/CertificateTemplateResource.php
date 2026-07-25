<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Http\Resources;

use App\Modules\Certificates\Models\CertificateTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CertificateTemplate */
class CertificateTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'html_template' => $this->html_template,
            'defaults' => $this->defaults,
            'created_at' => $this->created_at,
        ];
    }
}
