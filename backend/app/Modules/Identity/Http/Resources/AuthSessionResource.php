<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthSession */
class AuthSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'is_current' => $this->token_id !== null
                && $this->token_id === $request->user()?->currentAccessToken()?->getKey(),
            'device' => [
                'uuid' => $this->device->uuid,
                'label' => $this->device->label,
            ],
            'ended_reason' => $this->ended_reason?->value,
            'last_active_at' => $this->last_active_at,
            'ended_at' => $this->ended_at,
            'created_at' => $this->created_at,
        ];
    }
}
