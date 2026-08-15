<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Step 2. Columns only, every one of them nullable for now.
|
| The constraints arrive in step 4, after step 3 has filled these in. Adding a
| NOT NULL column to a populated table and backfilling afterwards is the order
| that fails on live data; this is the order that does not.
|
| ⚠️ `uuid` is added because a question stopped being an internal row of one exam
| and became an entity with its own browse screen, its own search and its own
| URL. Without it the only handle the API could expose is the autoincrement id,
| which every route in this product refuses on principle.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('workspace_id');
            $table->unsignedBigInteger('concept_id')->nullable()->after('exam_id');
            // Stays nullable for good: a question can legitimately belong to a
            // concept without belonging to one particular lesson.
            $table->unsignedBigInteger('lesson_id')->nullable()->after('concept_id');
            $table->string('bloom_level')->nullable()->after('difficulty');
            // Import de-duplication (Q7). A hash rather than a comparison over
            // `content`, which is longText and therefore cannot carry an index —
            // and without an index, "skip if it exists" is a full scan per row
            // and a race between two browser tabs.
            $table->string('content_hash', 64)->nullable()->after('content');
            $table->boolean('is_active')->default(true)->after('explanation');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'concept_id', 'lesson_id', 'bloom_level', 'content_hash', 'is_active']);
        });
    }
};
