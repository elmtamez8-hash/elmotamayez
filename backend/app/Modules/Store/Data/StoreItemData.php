<?php

declare(strict_types=1);

namespace App\Modules\Store\Data;

use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Store\Enums\StoreItemKind;
use App\Shared\Data\DataTransferObject;
use App\Shared\Support\MinorUnits;

/**
 * What a teacher submits to create or edit a product.
 *
 * ⚠️ `mediaAssetUuid`, NEVER AN ID. A raw id in a payload is an id a teacher can
 * change to their neighbour's — and `media_assets` is workspace-partitioned, so
 * a bare `exists:media_assets,id` would let one teacher attach another teacher's
 * video to a product and sell it. The uuid is resolved inside the Action, where
 * the workspace can be checked (NFR-007).
 */
final class StoreItemData extends DataTransferObject
{
    public function __construct(
        public readonly StoreItemKind $kind,
        public readonly string $title,
        public readonly int $priceMinor,
        public readonly string $currency,
        public readonly ?string $description = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $mediaAssetUuid = null,
        public readonly ?int $stock = null,
        public readonly ?int $shippingFeeMinor = null,
        public readonly ?string $courseUuid = null,
        public readonly ?string $subjectSlug = null,
        public readonly bool $isActive = true,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: StoreItemKind::from((string) $data['kind']),
            title: (string) $data['title'],
            priceMinor: (int) MinorUnits::fromMajorOrFail((string) $data['price']),
            // The platform currency, read where every reader reads it — never a
            // literal here (owner decision 2026-09-27: one source).
            currency: (string) ($data['currency'] ?? app(BillingSettings::class)->currency()),
            description: isset($data['description']) ? (string) $data['description'] : null,
            excerpt: isset($data['excerpt']) ? (string) $data['excerpt'] : null,
            mediaAssetUuid: isset($data['media_asset_uuid']) ? (string) $data['media_asset_uuid'] : null,
            stock: isset($data['stock']) ? (int) $data['stock'] : null,
            shippingFeeMinor: MinorUnits::fromMajorOrFail(isset($data['shipping_fee']) ? (string) $data['shipping_fee'] : null),
            courseUuid: isset($data['course_uuid']) ? (string) $data['course_uuid'] : null,
            subjectSlug: isset($data['subject_slug']) && $data['subject_slug'] !== '' ? (string) $data['subject_slug'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }
}
