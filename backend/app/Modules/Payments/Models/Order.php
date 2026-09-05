<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Models\BaseModel;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $status
 * @property string $provider
 * @property OrderKind $kind
 * @property ?array<string, mixed> $metadata cast to array; the column is json
 * @property-read Workspace $workspace workspace_id is NOT NULL
 * @property-read User $user user_id is NOT NULL
 * @property ?int $granted_by null means the buyer bought it themselves (024)
 * @property-read ?User $grantor
 */
class Order extends BaseModel implements HasMedia
{
    use BelongsToWorkspace, HasUuid, InteractsWithMedia;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'product_id',
        'course_id',
        'kind',
        'amount_minor',
        'currency',
        'provider',
        'provider_ref',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'metadata',
        /*
        | ⚠️ `granted_by` IS DELIBERATELY ABSENT, AND ADDING IT IS THE DEFECT.
        |
        | It records WHO created an order on somebody else's behalf — an audit
        | fact, written once by `PurchaseCredits` and never again. Mass-assignable
        | it becomes a second door to that fact from outside the Action that owns
        | it, exactly as `captured_order_id` would. `OrdersGrantedByIsNotFillable`
        | in `StaffCreditGrantTest` fails the build if it reappears here.
        */
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'kind' => OrderKind::class,
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * An order whose approval belongs to the platform, not to the seller.
     *
     * ⚠️ REPLACES `isCreditPurchase()`, WHICH WAS THE SAME QUESTION UNDER A
     * NAME THAT NAMED ONE ANSWER. The three policy methods that called it were
     * asking "is this the platform's to sign", and with spec 011's store sales
     * and subscriptions the honest answer stopped being "is it credits" — while
     * the method name would have kept reading as correct at every call site.
     */
    /**
     * The platform officer who created this order for somebody else (024).
     *
     * `null` for every order a buyer started themselves, which is every order
     * written before spec 024 — hence no backfill.
     *
     * @return BelongsTo<User, $this>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function requiresPlatformApproval(): bool
    {
        return $this->kind->requiresPlatformApproval();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'application/pdf'])
            ->useDisk('local');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The subscription this order activated, if activation got that far.
     *
     * ⚠️ ITS ABSENCE ON AN APPROVED ORDER IS THE SIGNAL (027 · FR-027). Activation
     * is queued and runs after the approval commits, so «approved with no
     * subscription» is exactly the incomplete work that must be readable on the
     * officer's screen rather than left in `failed_jobs`.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'under_review'], true);
    }

    /**
     * Whether a receipt may still be uploaded onto this order (027 · FR-032).
     *
     * ⚠️ WIDER THAN `isPending()`, AND ONLY BY `rejected`. A refusal the payer
     * cannot answer is a refusal they repeat — `ReceiptRejected`'s own docblock
     * says so: «the same transfer, re-uploaded, refused again». Making them start
     * a whole new order loses the reason, the amount they were quoted and the
     * thread the officer was reading.
     *
     * ⚠️ AND `approved` STAYS OUT. `UploadPaymentReceipt`'s docblock is about
     * that one: a second image onto an approved order replaces the document the
     * approver actually read, and the audit trail then shows an approval of a
     * file that arrived after it.
     */
    public function acceptsReceipt(): bool
    {
        return $this->isPending() || $this->status === 'rejected';
    }

    /**
     * The receipt as it stands NOW — the last one uploaded.
     *
     * ⚠️ `getMedia()->last()`, NEVER `getFirstMedia()`. The collection is not
     * `singleFile()`, so a re-upload APPENDS: with FR-032 letting a rejected
     * order carry a new image, every reader that took the first one would show
     * the officer the blurred photograph they already refused, and approve
     * against it. Keeping both is deliberate — the rejected one is the record of
     * what was rejected — so the fix is to read the latest, in one place.
     */
    public function latestReceipt(): ?Media
    {
        return $this->getMedia('receipt')->last();
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
