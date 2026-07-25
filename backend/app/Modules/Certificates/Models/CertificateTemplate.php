<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Models;

use App\Models\BaseModel;
use App\Shared\Traits\BelongsToWorkspace;

class CertificateTemplate extends BaseModel
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'name',
        'html_template',
        'defaults',
    ];

    protected function casts(): array
    {
        return [
            'defaults' => 'array',
        ];
    }
}
