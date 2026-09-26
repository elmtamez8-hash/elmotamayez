<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WHO set `users.timezone` — the person (`manual`) or their browser (`browser`).
|
| Owner decision 2026-09-26: an explicit choice in account settings is the
| source of truth. The sign-in stamp and the quiet-hours form both write the
| browser's zone, and without this column neither can tell a zone somebody
| chose from one a laptop reported — so the next sign-in from a laptop still on
| last holiday's zone would quietly undo the choice.
|
| Existing rows stay NULL: every zone written so far came from a browser (the
| quiet-hours form or the sign-in stamp), and NULL reads as «not chosen».
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone_source', 16)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone_source');
        });
    }
};
