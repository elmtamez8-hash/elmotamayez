<?php

declare(strict_types=1);

namespace App\Shared\Traits;

/**
 * Provides a consistent isPublished() check for models that have a `status` column
 * and optionally a `published_at` timestamp.
 *
 * A resource is considered published when:
 * - status === 'published'
 * - published_at is null OR published_at <= now()
 */
trait IsPublishable
{
    public function isPublished(): bool
    {
        // The RAW attribute, never the cast one: a model may cast `status` to a
        // backed enum (`Article` does), and an enum never equals the string. The
        // raw value is the column's own spelling for every model using this.
        if (($this->getAttributes()['status'] ?? null) !== 'published') {
            return false;
        }

        // If the model has a published_at column set, require it to be in the past.
        $publishedAt = $this->getAttribute('published_at');

        if ($publishedAt !== null) {
            return $publishedAt->isPast();
        }

        return true;
    }
}
