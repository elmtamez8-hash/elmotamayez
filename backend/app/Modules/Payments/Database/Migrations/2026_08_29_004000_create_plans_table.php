<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Support\SubscriptionEligibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T089 — the third pricing shape (data-model §٧ · FR-025).
 *
 * ⚠️ THIS TABLE IS TIME, NOT SESSIONS, AND THAT IS THE WHOLE REASON IT EXISTS.
 * The product sells three shapes and only one of them is new here:
 *
 *   · «بالحصّة»            ⇒ `CreditPackage(credits: 1)`, priced per course.
 *   · «بعددٍ من الحصص»     ⇒ `CreditPackage(credits: N)`, priced per course.
 *   · «بالشهر»             ⇒ THIS TABLE.
 *
 * A `session_count` column here would be a second credit engine standing beside
 * the first — two vocabularies for one fact, and the day they disagree is the
 * day a student is charged twice for one seat. Worse, FR-028 forbids a plan
 * session from moving a balance at all, so the pack would have to reimplement
 * lots, expiry, the floor and the reconciliation invariants rather than reuse
 * them. Sessions are bought as credits; time is bought here.
 *
 * ⚠️ AND THERE IS A PRICE COLUMN, WHICH `credit_packages` DELIBERATELY LACKS.
 * That is not a regression of 006's rule, it is the boundary of it: a credit
 * package is priced by a FORMULA (the teacher's approved rate + the platform's
 * fees), so storing a number would be one price for every teacher alive.
 * «وصولٌ غير محدود لشهر» has no such formula — nothing derives what unlimited
 * access is worth — so a human writes the number. Which human is FR-025 (Q4):
 * the teacher writes the duration and the coverage, the PLATFORM writes the
 * price, and {@see SavePlan} refuses the field from
 * anyone without the platform permission.
 *
 * ⚠️ `price_minor` IS NULLABLE, AND THE NULL IS A STATE RATHER THAN A GAP. Two
 * actors write this row at two different moments, so between them the plan
 * exists and has no price — and an unpriced plan is never listed for sale. Same
 * shape as `CostPlusPricing` returning null for a teacher with no approved rate:
 * a default price is a number nobody approved, charged to a student and owed to
 * a teacher who never agreed to it.
 *
 * ⚠️ `session_type` IS A COVERAGE PREDICATE, NOT A LABEL. A teacher's price
 * changes with subject, with year, and with how many students are in the room —
 * the first two come from the course, the third comes from nowhere else. Without
 * this column a monthly plan priced for a group of eight covers one-to-one
 * sessions at zero credits, which is the same money leak the whole cost-plus
 * formula exists to prevent. It is read by
 * {@see SubscriptionEligibility} and by the charge
 * branch, not printed on a card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A real tenant key, unlike `coupons.workspace_id`: the teacher owns
            // the plan, so the model carries BelongsToWorkspace and the student's
            // read path resolves the uuid inside the Action instead (a student is
            // a member of no workspace, so the scope is inert on their path).
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('title');

            // Days, not months: «شهر» is a marketing word and 28/30/31 is a
            // support ticket. `duration_days = 30` is unambiguous on both engines
            // and adds without a calendar library.
            $table->unsignedInteger('duration_days');

            // `individual` | `group` — the same two strings LiveSessions and the
            // operating fee are keyed by. See the class docblock.
            $table->string('session_type', 16);

            // `workspace` (everything this teacher publishes) | `course` (one).
            $table->string('coverage_type', 16);

            // The uuid, never a raw id — the id of a row in another module is not
            // this table's to hold, and the purchase paths already carry uuids.
            $table->uuid('coverage_uuid')->nullable();

            // NULL until a platform officer prices it. Signed `bigInteger` in
            // minor units, matching every other money column added by 011.
            $table->bigInteger('price_minor')->nullable();
            $table->string('currency', 3)->default('QAR');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The student's catalogue read and the teacher's own list are the
            // same question, asked from two directions.
            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
