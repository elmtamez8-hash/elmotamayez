<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Shared\Data\DataTransferObject;

final class RenderedMessage extends DataTransferObject
{
    public function __construct(
        public readonly string $titleAr,
        public readonly string $bodyAr,
        public readonly int $templateId,
    ) {}
}
