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
        if ($this->status !== 'published') {
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
