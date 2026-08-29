<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Courses\Models\Course;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Store\Data\StoreItemData;
use App\Modules\Store\Enums\StoreItemKind;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Create or edit a product in the teacher's store (spec 011 · US1 · FR-001).
 *
 * ⚠️ THE TYPE RULES ARE ENFORCED HERE AND NOT ONLY IN THE FORM REQUEST.
 * `SeedCommand` runs every seeder inside `Model::unguarded()`, and the panel and
 * the importer reach this Action with no form behind them — so a rule that lives
 * in validation alone is a rule three of its four callers walk around. The same
 * reasoning that put «exactly one correct option» into `SaveQuestion`.
 *
 * ⚠️ AND `media_asset_id` IS RESOLVED FROM A UUID, INSIDE THE WORKSPACE.
 * `media_assets` is workspace-partitioned and `exists:media_assets,id` is a raw
 * query that ignores the scope — so the obvious rule lets a teacher attach
 * another teacher's video to a product and sell it. The lookup below is scoped
 * and the failure is a sentence, not a 404.
 */
class SaveStoreItem extends Action
{
    public function handle(StoreItemData $data, int $workspaceId, ?StoreItem $item = null): StoreItem
    {
        /*
        | ⚠️ AN EDIT THAT SENDS NO UUID KEEPS THE FILE IT ALREADY HAS, and the
        | version without this line could not fix a typo in a title. The Resource
        | deliberately does not send `media_asset_uuid` — that identifier travels
        | to a BUYER on the same payload, and a raw asset id in a student's
        | response is the leak FR-011 forbids — so the form has nothing to
        | prefill, sends null, and the guard below refused every edit of every
        | digital product with «المنتج الرقمي يحتاج ملفاً مرفوعاً».
        |
        | Same family as the video screen that told a teacher «لا يوجد فيديو
        | لهذا الدرس بعد» on every visit because it never fetched the lesson.
        |
        | The guard therefore runs against the RESULTING state, not against the
        | payload: an edit that clears the file is still refused, an edit that
        | says nothing about it is not.
        */
        $assetId = $data->mediaAssetUuid === null
            ? $item?->media_asset_id
            : $this->resolveAssetId($data, $workspaceId);

        // Same rule for the two printed fields: what the payload does not
        // mention, the row keeps.
        $stock = $data->stock ?? $item?->stock;
        $shipping = $data->shippingFeeMinor ?? $item?->shipping_fee_minor;

        $this->guardTypeRules($data, $assetId, $stock, $shipping);

        $item ??= new StoreItem;

        $item->fill([
            'workspace_id' => $workspaceId,
            'course_id' => $this->resolveCourseId($data->courseUuid, $workspaceId),
            'kind' => $data->kind,
            'title' => $data->title,
            'description' => $data->description,
            'excerpt' => $data->excerpt,
            'price_minor' => $data->priceMinor,
            'currency' => $data->currency,
            'shipping_fee_minor' => $data->kind->isStocked() ? $shipping : null,
            'media_asset_id' => $data->kind->isStocked() ? null : $assetId,
            'is_active' => $data->isActive,
        ]);

        /*
        | ⚠️ `stock` IS NOT `$fillable` AND IS WRITTEN HERE EXPLICITLY. It moves by
        | the conditional UPDATE in `ClaimStock` everywhere else, and mass
        | assignment would be a second door into the number that claim is the only
        | correct writer of. `null` for a digital item is the value, not a missing
        | one: `stock >= :qty` against NULL is NULL, so a zero here would report
        | «نفد المخزون» about a file that cannot run out.
        */
        $item->stock = $data->kind->isStocked() ? $stock : null;

        $item->save();

        return $item;
    }

    /**
     * Judged on what the item WILL hold, never on the payload alone.
     *
     * @param  int|null  $assetId  the file it will have, an existing one included
     * @param  int|null  $stock  the shelf it will have
     * @param  int|null  $shipping  the postage it will charge
     */
    private function guardTypeRules(StoreItemData $data, ?int $assetId, ?int $stock, ?int $shipping): void
    {
        if ($data->priceMinor < 1) {
            throw new DomainException('سعر المنتج يجب أن يكون أكبر من صفر.');
        }

        if ($data->kind === StoreItemKind::Digital) {
            if ($assetId === null) {
                throw new DomainException('المنتج الرقمي يحتاج ملفاً مرفوعاً.');
            }

            return;
        }

        if ($stock === null || $stock < 0) {
            throw new DomainException('النسخة المطبوعة تحتاج مخزوناً لا يقلّ عن صفر.');
        }

        if ($shipping === null || $shipping < 0) {
            throw new DomainException('النسخة المطبوعة تحتاج رسم شحن.');
        }
    }

    private function resolveAssetId(StoreItemData $data, int $workspaceId): ?int
    {
        if ($data->mediaAssetUuid === null) {
            return null;
        }

        $asset = MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $data->mediaAssetUuid)
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($asset === null) {
            // Deliberately the same sentence whether the asset does not exist or
            // belongs to somebody else: a distinct answer for the second case is
            // an oracle telling a teacher which uuids are real.
            throw new DomainException('الملف المختار غير موجود في مساحتك.');
        }

        return (int) $asset->getKey();
    }

    private function resolveCourseId(?string $courseUuid, int $workspaceId): ?int
    {
        if ($courseUuid === null) {
            return null;
        }

        $course = Course::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $courseUuid)
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($course === null) {
            throw new DomainException('الكورس المختار غير موجود في مساحتك.');
        }

        return (int) $course->getKey();
    }
}
