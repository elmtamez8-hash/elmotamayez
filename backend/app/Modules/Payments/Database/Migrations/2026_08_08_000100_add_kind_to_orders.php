<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order buys: a course, or credits.
 *
 * Without this column the two are indistinguishable. CreateEnrollmentFromOrder
 * branches on `course_id === null` alone, and a credit order carries a course by
 * its nature — Q-7 made the course the pricing context, because a session with
 * no course is a session with no price. So a student buying credits would be
 * enrolled in the whole course for free, on the same approval.
 *
 * It is also the discriminator OrderPolicy::approve reads to keep credit
 * purchases out of the teacher's hands: PAYMENTS_APPROVE sits in the teacher
 * array and its workspace check is one the teacher satisfies by definition, so
 * without this a teacher could mark a transfer that never happened as approved,
 * mint credits, and be paid out of them by spec 014.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Defaulted rather than backfilled: every existing order is a course
            // purchase, and a default states that in one line instead of a
            // migration that walks the table to write the same value everywhere.
            $table->string('kind', 16)->default('course')->after('course_id');

            // Reading orders of one kind for a workspace is the reconciliation
            // screen's query and the credit-purchase history's.
            $table->index(['workspace_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'kind', 'status']);
            $table->dropColumn('kind');
        });
    }
};
