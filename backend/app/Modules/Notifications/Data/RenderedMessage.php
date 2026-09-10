<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Data;

use App\Shared\Data\DataTransferObject;

final class RenderedMessage extends DataTransferObject
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly int $templateId,
    ) {}
}
