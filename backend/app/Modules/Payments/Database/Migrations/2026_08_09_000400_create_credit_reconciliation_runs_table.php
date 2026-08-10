<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the nightly reconciliation writes what it found.
 *
 * ⚠️ A TABLE BECAUSE RECONCILIATION IS A JOB, NOT A PAGE. Computed on GET it is
 * a `GROUP BY` over the two fastest-growing tables of this phase, with no tenant
 * filter and no pagination — an admin refreshing the screen twice would do it
 * twice, on production, at whatever hour they happened to open it.
 *
 * ⚠️ AND A RUN IS RECORDED EVEN WHEN IT FINDS NOTHING. That is what makes "last
 * run" answerable: derived from the findings, a clean platform and a sweep that
 * has not run in a month look identical, and the second is the one worth an
 * alarm.
 *
 * Platform-owned: it spans every workspace by construction, so there is no
 * `workspace_id` and the read is behind a platform permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->timestamp('ran_at')->index();

            // What was looked at, so a run that found nothing can be told apart
            // from a run that looked at nothing.
            $table->unsignedInteger('balances_checked')->default(0);
            $table->unsignedInteger('sessions_checked')->default(0);

            // The total, and the sample. `findings` is capped so one bad deploy
            // cannot write a hundred-megabyte row; `findings_count` is the honest
            // number either way, and the job logs when it truncated.
            $table->unsignedInteger('findings_count')->default(0);
            $table->json('findings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reconciliation_runs');
    }
};
