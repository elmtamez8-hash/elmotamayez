<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the retention sweep walks (spec 013 · T129).
 *
 * ⚠️ `attendances` ALREADY CARRIES `(student_user_id, created_at)` AND IT IS
 * USELESS HERE. The sweep's predicate names `created_at` and no student at all,
 * and a composite is only usable from its LEADING column — so the existing index
 * would be passed over and the nightly job would table-scan the fastest-growing
 * register in the product, every night, for ever.
 *
 * ⚠️ AND IT LIVES IN THIS MODULE'S MIGRATION, NOT IN `Compliance`. The predicate
 * belongs to `LiveSessionsPersonalData::expire()`, so the index belongs beside it:
 * one central migration declaring seven other modules' indexes is the coupling
 * `PersonalDataOwner` exists to avoid, written in DDL instead of PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
