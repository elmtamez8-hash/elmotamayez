<?php

declare(strict_types=1);

namespace App\Shared\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Base immutable Data Transfer Object.
 *
 * DTOs carry typed data between layers (controllers ↔ actions ↔ resources).
 * They are constructed from validated request data and expose a typed, readonly shape.
 */
abstract class DataTransferObject implements Arrayable, JsonSerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return (array) get_object_vars($this);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
