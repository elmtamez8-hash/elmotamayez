<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Models\BaseModel;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Store\Enums\StoreItemKind;
use App\Shared\Support\MinorUnits;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use App\Shared\Traits\IsPubliclyListed;
use Database\Factories\Modules\Store\StoreItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book or a set of notes the teacher sells (spec 011 · US1).
 *
 * Workspace-owned: the goods, the price and the stock belong to one teacher.
 *
 * ⚠️ `stock` IS NOT `$fillable`. It moves by the atomic conditional UPDATE in
 * `ClaimStock` — `WHERE id = ? AND stock >= :qty` — which is both the check and
 * the claim. Mass-assignable it becomes a second way to change the number from
 * outside the statement that owns it, and «count then insert» is the definition
 * of the race the claim exists to prevent. The teacher SETS the stock through
 * `SaveStoreItem`, which writes it explicitly for exactly that reason.
 *
 * @property StoreItemKind $kind
 * @property int $price_minor
 * @property int|null $stock
 * @property int|null $shipping_fee_minor
 * @property string|null $cover_path
 * @property bool $is_active
 */
class StoreItem extends BaseModel
{
    /** @use HasFactory<StoreItemFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid, IsPubliclyListed;

    protected $fillable = [
        'workspace_id',
        'course_id',
        'teacher_profile_id',
        'subject_id',
        'kind',
        'title',
        'description',
        'excerpt',
        'price_minor',
        'currency',
        'shipping_fee_minor',
        'media_asset_id',
        'cover_path',
        'is_active',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => StoreItemKind::class,
            'price_minor' => 'integer',
            'stock' => 'integer',
            'shipping_fee_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The price in major units («150.00») over `price_minor` — the money rule in
     * docs/gotchas/billing.md. Not fillable and not appended, like `Plan::price`.
     *
     * @return Attribute<string|null, mixed>
     */
    protected function price(): Attribute
    {
        return MinorUnits::attribute('price_minor');
    }

    /** @return Attribute<string|null, mixed> */
    protected function shippingFee(): Attribute
    {
        return MinorUnits::attribute('shipping_fee_minor');
    }

    /** The cover's public url, or null — served like `courses.cover_path`. */
    public function coverUrl(): ?string
    {
        return $this->cover_path === null ? null : asset('storage/'.$this->cover_path);
    }

    /**
     * The public store (`/marketplace/store/*`): on sale, and its teacher's
     * profile listed and approved — the same bar a course's public page meets.
     * `scopePubliclyListed()` adds the workspace's marketplace participation and
     * drops the workspace scope for the cross-tenant read.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function publicListingConstraints(Builder $query): Builder
    {
        return $query
            ->where('store_items.is_active', true)
            ->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('teacher_profiles')
                ->whereColumn('teacher_profiles.id', 'store_items.teacher_profile_id')
                ->whereNull('teacher_profiles.deleted_at')
                ->where('teacher_profiles.is_publicly_listed', true)
                ->where('teacher_profiles.approval_status', TeacherProfile::STATUS_APPROVED));
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
