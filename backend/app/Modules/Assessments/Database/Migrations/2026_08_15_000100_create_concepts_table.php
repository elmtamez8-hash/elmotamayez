<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 1 of the ordered chain in `data-model.md` §ط.
|
| Concepts come first because the tag they provide has to exist before the tag
| becomes mandatory. Every later step depends on a row being available to point
| at — including the backfill in step 3, which needs a real concept id for every
| question authored before this spec.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concepts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('name');
            // Nullable because the very first rows are written by a migration,
            // which has no user to credit. Everything a teacher creates carries
            // an author.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Two concepts with the same name in one teacher's bank is a typo,
            // not a taxonomy. Neither column is nullable, so this constraint
            // actually bites — unlike a unique index over a nullable column,
            // where NULL never collides with NULL.
            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concepts');
    }
};
