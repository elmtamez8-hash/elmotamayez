<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T118 — the dashboard's source (FR-043 · FR-044).
 *
 * ⚠️ NO `uuid` COLUMN, DELIBERATELY. The rollup writes with `upsert()`, which
 * never boots the model, so `HasUuid` never fires — and on MySQL the resulting
 * NOT NULL violation is downgraded to a warning with `''` stored, after which
 * every later row collides on `unique(uuid)`. Nothing addresses one of these rows
 * by identity anyway: the key IS the four columns below.
 *
 * ⚠️ A NUMERATOR AND A DENOMINATOR, NEVER A COMPUTED RATIO. `SC-012` asks for a
 * zero-difference match against the source, and a rounded percentage cannot
 * produce one. A count stores `denominator = 0` and the reader treats that as
 * «this is not a ratio» rather than dividing by it.
 *
 * ⚠️ BOTH ARE SIGNED. `dues.overdue_credits` is a debt and arrives negative from
 * the balances it sums; an unsigned column raises MySQL's ERROR 1690 on the
 * arithmetic and SQLite has no unsigned arithmetic to overflow, so no local test
 * could ever see it — the same trap `CreditLedger::applyToBalance()` documents.
 *
 * ⚠️ AND A SECOND INDEX. The unique key starts with the DATE because that is the
 * identity of a row; every screen reads by METRIC first («this number, over the
 * last thirty days»), which that index cannot serve — a range scan per metric and
 * per region without it.
 *
 * Two sentinels, both `0` and both NOT NULL, for the reason `feature_flags` and
 * `concept_stats` spell out: NULL never equals NULL, so a unique index carrying a
 * nullable column does not bite and `upsert()` inserts a new row every night.
 * `workspace_id = 0` is the platform total; `region_id = 0` is «all regions».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_metrics_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('metric_key', 48);
            $table->unsignedBigInteger('workspace_id')->default(0);
            $table->unsignedBigInteger('region_id')->default(0);
            $table->bigInteger('numerator')->default(0);
            $table->bigInteger('denominator')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['date', 'metric_key', 'workspace_id', 'region_id'], 'platform_metrics_daily_key');
            $table->index(['metric_key', 'workspace_id', 'region_id', 'date'], 'platform_metrics_daily_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_metrics_daily');
    }
};
