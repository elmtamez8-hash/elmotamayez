<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T076 — who invited whom (FR-019 · FR-022 · FR-023).
 *
 * ⚠️ NEITHER TABLE CARRIES `BelongsToWorkspace`, AND ADDING IT WOULD DUPLICATE
 * ONE PERSON PER TEACHER. A referral code belongs to a HUMAN, not to a
 * classroom: one person, one code, for life. This is the mirror-image bug
 * `PlatformOwnershipTest` exists to catch in both directions, and the same
 * reasoning that keeps `student_credit_accounts` and `notification_preferences`
 * unscoped.
 *
 * The consequence is that no global scope guards either table, so every read is
 * explicitly filtered by the owner — `where('referrer_user_id', …)`, written out
 * in the Action. A student is a member of no workspace, so `WorkspaceScope`
 * would add no condition even if the trait were there: route-model binding on
 * these is unsafe by construction.
 *
 * ⚠️ AND THERE IS NO `award_entry_id`, WHICH IS A DELIBERATE DEPARTURE FROM
 * `data-model.md §١٠`. That column assumed Identity would call Gamification's
 * `AwardPoints` itself — which Constitution III forbids and which nothing in
 * this tree does: every award in the product is made by a Gamification listener
 * subscribed to another module's event. So Identity fires `ReferralCompleted`
 * and Gamification decides what it is worth.
 *
 * The column would also have been wrong on its own terms: BOTH parties are
 * rewarded («للطرفَين»), and one column holds one entry — a reversal driven
 * through it would return the referrer's points and silently leave the invited
 * student's. The reversal finds both by the idempotency triplet instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // One code per person, for life. The unique index is also what makes
            // `IssueReferralCode` safe to call from a plain GET: two concurrent
            // reads both find nothing and both insert, and this is what turns the
            // loser into a retry instead of a duplicate.
            $table->unsignedBigInteger('user_id')->unique();

            $table->string('code', 12)->unique();

            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('referrer_user_id');

            // ⚠️ UNIQUE: a person is referred ONCE, ever. Without it a second
            // signup flow, a merged account or a redelivered attach writes a
            // second row and the same purchase completes two referrals. It is
            // also what makes the referral id a sound idempotency source for the
            // award.
            $table->unsignedBigInteger('referred_user_id')->unique();

            // `pending` | `completed` | `flagged` | `reversed`.
            //
            // `flagged` is FR-022's second half — «the suspicious pattern is
            // marked for review WITHOUT paying out». A row that is simply
            // discarded tells nobody anything; a status that pays nothing and
            // stays visible is what «للمراجعة» means.
            $table->string('status', 16)->default('pending');

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('flagged_reason')->nullable();

            $table->timestamps();

            // The cap is counted on this pair, and the referrals page reads it.
            $table->index(['referrer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
