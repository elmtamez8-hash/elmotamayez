<?php

declare(strict_types=1);

namespace App\Modules\Media\Enums;

/**
 * Where an asset is in its life at the provider (FR-005).
 *
 * `Ready` is the only state that issues a playback grant. That single rule is
 * what keeps a failed or half-finished upload from leaving a lesson in an
 * unplayable state (FR-007).
 */
enum MediaAssetStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الرفع',
            self::Uploading => 'قيد الرفع',
            self::Processing => 'قيد التجهيز',
            self::Ready => 'جاهز',
            self::Failed => 'فشل',
        };
    }

    public function isPlayable(): bool
    {
        return $this === self::Ready;
    }

    /** Still moving: worth asking the provider about again. */
    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::Uploading || $this === self::Processing;
    }
}
