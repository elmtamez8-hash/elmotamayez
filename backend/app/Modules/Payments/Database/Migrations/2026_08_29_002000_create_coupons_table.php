<?php

declare(strict_types=1);

use App\Modules\Payments\Support\DiscountResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T060 — the platform's discount codes (FR-010 · FR-012).
 *
 * ⚠️ `workspace_id` IS A SCOPE HERE, NOT A TENANT KEY, AND THE MODEL CARRIES NO
 * `BelongsToWorkspace`. FR-010 moved authorship of a coupon to the PLATFORM, so
 * this became platform reference data — the same road `credit_packages` took in
 * 006. The violation is recorded in `plan.md › Complexity Tracking` rather than
 * argued away here, because the two obvious alternatives are both worse: the
 * trait would add `workspace_id = X` to every read and make every PLATFORM
 * coupon (`workspace_id IS NULL`) vanish from every workspace in existence,
 * silently; and a `0` sentinel would mean «workspace number zero» in a column
 * that points at `workspaces`, which is a lying foreign key.
 *
 * The guard is therefore an explicit, GROUPED clause in the Action — see
 * {@see DiscountResolver}.
 *
 * ⚠️ `starts_at` AND `ends_at` ARE TIMESTAMPS, NEVER DATES. A `date` column
 * compared with `<= ends_on` binds midnight and kills the coupon on the morning
 * of its own last day. That boundary has already cost this repository three
 * fixes — `FreezePeriod::covering()`, the settlement close, and the coming-of-age
 * sweep — and every one of them was invisible on MySQL or invisible on SQLite,
 * never on both.
 *
 * ⚠️ `code` IS NORMALISED IN PHP, AT WRITE AND AT READ, AND NEVER WITH `UPPER()`
 * IN SQL. A function around the column throws away the index on the one path
 * whose rate limit exists *because* it is guessed at. And the two engines
 * disagree about it: MySQL's default collation matches case-insensitively while
 * SQLite does not, so an unnormalised code passes locally and proves the
 * opposite of what it claims about production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Unique across the PLATFORM, not within a workspace: a buyer types
            // a code with no idea whose it is, so two teachers owning «SUMMER»
            // would make the resolver's answer depend on what is being bought.
            $table->string('code', 32)->unique();

            // NULLABLE ON PURPOSE — see the class docblock. Null is a platform
            // coupon and is the ordinary case; a value narrows it to one teacher.
            $table->unsignedBigInteger('workspace_id')->nullable();

            // `null` | `course` | `store_item` | `credit_package`. The uuid, never
            // a raw id: the id of a row in another module is not this table's to
            // hold, and a uuid is what the purchase paths already carry.
            $table->string('scope_type', 24)->nullable();
            $table->uuid('scope_uuid')->nullable();

            // `percent` (1..100) | `fixed_minor`.
            $table->string('value_kind', 16);

            // SIGNED, because `fixed_minor` is money and every money column in
            // this tree is a signed integer in minor units. A percent lives here
            // too rather than in a second column: two columns of which exactly
            // one is ever populated is a shape every reader gets wrong once.
            $table->bigInteger('value');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // `null` means no ceiling. The COUNTER is the guard, claimed by a
            // conditional UPDATE — never `count()` then `insert()`, which is the
            // definition of the race, and never `lockForUpdate()`, a no-op on
            // SQLite.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
