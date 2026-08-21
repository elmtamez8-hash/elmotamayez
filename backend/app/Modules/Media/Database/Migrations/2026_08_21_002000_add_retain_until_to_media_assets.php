<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-asset retention that overrides the category's (spec 013 · FR-036).
 *
 * ⚠️ IT IS NOT A ROW IN `data_categories`, AND IT CANNOT BE. That table answers
 * one number and one behaviour PER CATEGORY, for everybody — while FR-036 asks
 * that a departing teacher's recordings "respect the rights of the students who
 * appear in them", which is a duration belonging to OTHER PEOPLE and different for
 * every asset. A single catalogue number cannot express "until the last person who
 * paid for a seat in this room loses their access".
 *
 * So the catalogue keeps saying 730 days for `class_recording` and this column is
 * the exception: when it is set, it is the date, and the age rule does not apply.
 * Null on every asset the platform has, which is why the sweep's normal path is
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->timestamp('retain_until')->nullable();

            // The sweep asks `retain_until < now` for the exception branch, next to
            // the `archived_at IS NULL` it already filters on.
            $table->index(['archived_at', 'retain_until']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['archived_at', 'retain_until']);
            $table->dropColumn('retain_until');
        });
    }
};
