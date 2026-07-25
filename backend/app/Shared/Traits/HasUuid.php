<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Adds a public ULID-based UUID column to a model, auto-generated on creation.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('uuid') === null) {
                $model->setAttribute('uuid', (string) Str::orderedUuid());
            }
        });
    }

    /**
     * Resolve route-model-binding by uuid first, then primary key.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if ($field === 'uuid' || $field === 'id') {
            return $query->where(function ($q) use ($value) {
                $q->where('uuid', $value)->orWhere('id', $value);
            });
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
