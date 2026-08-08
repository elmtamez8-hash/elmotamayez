<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives sections and chapters a public identifier.
 *
 * They never had one. `courses` and `lessons` carry `HasUuid`; these two do not,
 * so `StoreLessonRequest` took `section_id` and `chapter_id` as serial integers
 * — a payload exposing the autoincrement key, which the constitution forbids
 * outright. It survived because nothing in the product ever called those
 * endpoints. An authoring surface calls them on every rename and every reorder,
 * so the violation is fixed before it is multiplied.
 *
 * Nullable rather than NOT NULL: making an existing column non-nullable rebuilds
 * the table on SQLite, and the guarantee already holds one level up —
 * `HasUuid::bootHasUuid()` fills the column on create and StructureMigrationTest
 * asserts zero nulls remain. A rebuilt table that quietly loses an index is a
 * worse trade than a constraint the model already enforces.
 *
 * Table names are written out rather than looped: Larastan reads these files
 * statically to type model properties, and it cannot follow `Schema::table($var)`
 * — the columns would come back "undefined property" on every model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
        });

        $this->backfill('course_sections');

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->unique('uuid');
        });

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
        });

        $this->backfill('course_chapters');

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropUnique('course_sections_uuid_unique');
            $table->dropColumn('uuid');
        });

        Schema::table('course_chapters', function (Blueprint $table): void {
            $table->dropUnique('course_chapters_uuid_unique');
            $table->dropColumn('uuid');
        });
    }

    /**
     * One UPDATE per row, deliberately.
     *
     * No portable single statement generates a distinct value per row, and these
     * tables hold tens of rows per course rather than millions. Chunked so a
     * large instance does not load the whole table to do it.
     */
    private function backfill(string $table): void
    {
        DB::table($table)->select('id')->whereNull('uuid')->orderBy('id')
            ->chunk(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::orderedUuid()]);
                }
            });
    }
};
