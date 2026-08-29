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
        $this->guardTypeRules($data);

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
            'shipping_fee_minor' => $data->kind->isStocked() ? $data->shippingFeeMinor : null,
            'media_asset_id' => $this->resolveAssetId($data, $workspaceId),
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
        $item->stock = $data->kind->isStocked() ? $data->stock : null;

        $item->save();

        return $item;
    }

    private function guardTypeRules(StoreItemData $data): void
    {
        if ($data->priceMinor < 1) {
            throw new DomainException('سعر المنتج يجب أن يكون أكبر من صفر.');
        }

        if ($data->kind === StoreItemKind::Digital) {
            if ($data->mediaAssetUuid === null) {
                throw new DomainException('المنتج الرقمي يحتاج ملفاً مرفوعاً.');
            }

            return;
        }

        if ($data->stock === null || $data->stock < 0) {
            throw new DomainException('النسخة المطبوعة تحتاج مخزوناً لا يقلّ عن صفر.');
        }

        if ($data->shippingFeeMinor === null || $data->shippingFeeMinor < 0) {
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
