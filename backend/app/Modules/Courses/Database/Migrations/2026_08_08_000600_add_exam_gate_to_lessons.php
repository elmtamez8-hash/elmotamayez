<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an exam item asks before the course continues (FR-041).
 *
 * Nullable, because nine of the ten types have no gate: a column with a value on
 * an article would be a fact about a row that has no meaning, and the first
 * reader who forgets to check `type` would act on it.
 *
 * No default at the database level either. `ManageLessons` writes it explicitly
 * when the type is `exam` — a column default is invisible to a model built with
 * `new`, which is exactly how `status` and `kind` were silently wrong earlier in
 * this spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('exam_gate')->nullable()->after('reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('exam_gate');
        });
    }
};
