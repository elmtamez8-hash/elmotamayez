<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a lesson as a high-value asset (FR-041).
 *
 * The flag lives on the lesson because the teacher classifies their own content;
 * only the financial decision that follows from it — refuse access while the
 * balance for that course is negative — belongs to Payments.
 *
 * Default false: a switch that withholds content must be opted into, never
 * inherited by every lesson already published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->boolean('is_high_value')->default(false)->after('is_free');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('is_high_value');
        });
    }
};
