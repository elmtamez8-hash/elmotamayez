<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ٠٣٦ · T116 — a SESSION PLAN sells credits too, and the sale row refused it.
 *
 * ⛔ FOUR COLUMNS WERE NOT NULL AND A PLAN CANNOT FILL ONE OF THEM HONESTLY.
 * `credit_package_id` names a row that does not exist for a plan;
 * `teacher_rate_minor`, `operating_fee_minor` and `gateway_fee_minor` are the
 * three components `CostPlusPricing` computes for a PACKAGE — a plan's price is
 * a number a human types, with no formula behind it and no way to take it apart.
 * Writing zeros there would not be «unknown»: spec 015's books and the money
 * dashboard read those columns as facts, so three zeros are a teacher who earns
 * nothing and a platform that charges nothing, in a report nobody can tell apart
 * from a real one.
 *
 * ⚠️ `total_minor` STAYS REQUIRED. It is the one component a plan does know —
 * the order's own amount — and it is what «how much credit was sold» is summed
 * from. Made nullable with the rest, the finance screen would lose the only
 * number it actually needs.
 *
 * ⚠️ AND «UNSIGNED» IS RESTATED ON `credit_package_id`. MySQL re-declares a
 * column from what the blueprint says and nothing else, so an omitted
 * `unsigned` silently turns it signed — and **no test here can see it**, because
 * every suite runs on SQLite, which has no unsigned integers at all. The three
 * fee columns are `bigInteger` (signed) by design and stay so.
 *
 * ⚠️ NO `down()` THAT NARROWS AGAIN. Once one plan-shaped row exists, the four
 * columns cannot be made NOT NULL without inventing values for it — which is
 * the fabrication this migration exists to avoid. Precedent, in as many words:
 * `_000600_drop_exam_id_from_questions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('credit_package_id')->nullable()->change();
            $table->bigInteger('teacher_rate_minor')->nullable()->change();
            $table->bigInteger('operating_fee_minor')->nullable()->change();
            $table->bigInteger('gateway_fee_minor')->nullable()->change();

            /*
            | The other half of the same fact: a sale row now says WHICH of the
            | two things was sold. Nullable because every row written before
            | today came from a package, and a sentinel would be a second answer
            | to a question `credit_package_id` already answers.
            |
            | No foreign key, matching `credit_package_id` beside it: a plan that
            | is deleted must not take the record of a completed sale with it.
            */
            $table->unsignedBigInteger('plan_id')->nullable()->after('credit_package_id');
        });
    }

    /**
     * Deliberately empty, and this says so out loud.
     *
     * Re-imposing NOT NULL fails on the first plan-shaped row — which is every
     * row this change exists to allow — and `plan_id` cannot be dropped without
     * losing which sales were subscriptions. A rollback that cannot restore what
     * was there must SAY it rather than pretend.
     */
    public function down(): void {}
};
