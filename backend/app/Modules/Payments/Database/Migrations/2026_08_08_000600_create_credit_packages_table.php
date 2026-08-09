<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package template — platform-owned reference data.
 *
 * Constitution v1.2.0 §I, kind (ب): no BelongsToWorkspace, and no individual
 * owner either, so write permission (BILLING_PACKAGES_MANAGE) is its ONLY guard.
 * v1.1.0 listed credit packages under workspace-owned; that line predated Q-1,
 * which put cost-plus pricing with the platform and forbade the teacher from
 * defining or seeing a sale price. The amendment is recorded in the constitution's
 * sync impact report.
 *
 * There is deliberately NO price column. The price is computed per course,
 * because its input is the approved rate of that course's teacher — a price
 * column here would mean one price for every teacher on the platform.
 *
 * `credits` is unsigned: a package that grants a negative number of credits is
 * not a package, and this is the one counter in the phase that can never be
 * negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');

            // Sizes are configuration, never constants (FR-017).
            $table->unsignedSmallInteger('credits');

            // Which session type a credit from this package is spent on.
            $table->string('session_type', 16);

            // Null = never expires, the launch default (Q-5).
            $table->unsignedSmallInteger('validity_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'session_type', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packages');
    }
};
