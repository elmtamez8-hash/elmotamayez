<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

enum CaptionSource: string
{
    case Manual = 'manual';

    /** Provider-generated. No provider offers it yet; the column is ready for one. */
    case Auto = 'auto';
}
