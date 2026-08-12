<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3 of 3: NOT NULL and unique, now that every row has a value.
 *
 * ⚠️ `->change()` in Laravel 13 REDECLARES the column — every attribute left out
 * of the new definition is dropped, not preserved. So the type is written out
 * in full here even though only nullability is changing; `$table->uuid('uuid')`
 * alone would silently be the whole new definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->uuid('uuid')->nullable()->change();
        });
    }
};
