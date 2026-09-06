<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `/courses/{slug}` replaces `/courses/{uuid}` — so the slug becomes a
 * PLATFORM-wide address (026 · قرارُ صاحبِ المنتَجِ ٢٠٢٦-٠٩-٠٥).
 *
 * ⚠️ THE INDEX WAS `unique(workspace_id, slug)`, WHICH IS THE WRONG KEY FOR A
 * PUBLIC URL — the identical mistake `add_slug_to_teacher_profiles` refused in
 * writing on 2026-08-12: two workspaces claim one address, and the public route
 * then resolves to whichever row the database happened to return first. Two
 * teachers naming a course «الرياضيات للثانوية العامة» is not an edge case, it
 * is Tuesday.
 *
 * ⚠️ AND THE SLUGS ARE REGENERATED, NOT MERELY DE-DUPLICATED. Measured on the
 * development database (2026-09-06): the stored slugs were SHIFTED — «النحو
 * العربي المبسّط» carried `alkymyaaa-alaadoy-mn-alsfr`, which belongs to «الكيمياء
 * العضوية من الصفر», and four rows in a row were wrong the same way. A URL naming
 * a different course than the page it opens is worse than the uuid it replaces,
 * and de-duplication alone would have preserved every one of them.
 *
 * This breaks any slug URL shared before today. Nothing public ever emitted one
 * — every link in the tree, the canonical included, was the uuid — so the break
 * is theoretical, and the uuid keeps working as a permanent redirect.
 *
 * ⚠️ AND THE GENERATION RULE IS COPIED HERE RATHER THAN CALLED. `CourseSlug::for`
 * is application code: a migration that calls it runs whatever that class says a
 * year from now against a schema from today. This method describes what the
 * column held on the day the index changed, and must keep working if the rule
 * changes tomorrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->regenerate();

        /*
        | ⚠️ DROPPING AND ADDING IN TWO SEPARATE CLOSURES, and the drop first.
        | SQLite rebuilds the table for a multi-alteration, and every test in
        | this repository runs on in-memory SQLite — while adding a unique index
        | before the composite one is gone is a second index over the same column
        | for no reason.
        */
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'slug']);
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->unique('slug');
        });
    }

    /**
     * Give every course a slug derived from its CURRENT title, unique platform-wide.
     *
     * chunkById, not chunk: this walk rewrites the column it orders nothing by,
     * but the paging rule is the same one the uuid backfill was fixed for — an
     * OFFSET page over a moving set skips as many rows as the previous page
     * touched, and reports success.
     */
    private function regenerate(): void
    {
        $taken = [];

        // ICU, matching CourseSlug. Built once, not once per row. The fallback
        // is for a host without intl, where a different-looking slug beats a
        // fatal error in a deploy.
        $transliterator = class_exists(Transliterator::class)
            ? Transliterator::create('Any-Latin; Latin-ASCII; Lower()')
            : null;

        DB::table('courses')
            ->select(['id', 'title'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$taken, $transliterator): void {
                foreach ($rows as $row) {
                    $title = (string) $row->title;

                    $latin = $transliterator?->transliterate($title);
                    $base = Str::slug(is_string($latin) ? $latin : Str::transliterate($title));

                    if ($base === '') {
                        $base = 'course';
                    }

                    $slug = $base;
                    $suffix = 1;

                    // In memory as well as in the table: rows written inside
                    // this same chunk are not visible to the walk's own query,
                    // and two courses with one title is the ordinary case.
                    while (isset($taken[$slug]) || DB::table('courses')->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                        $suffix++;
                        $slug = $base.'-'.$suffix;
                    }

                    $taken[$slug] = true;

                    DB::table('courses')->where('id', $row->id)->update(['slug' => $slug]);
                }
            });
    }

    /**
     * ⚠️ THE SLUGS DO NOT COME BACK. `up()` overwrote every one of them, and no
     * record of the previous values is kept — the old ones named the wrong
     * courses, which is why they were overwritten. This restores the INDEX
     * shape and nothing else, exactly as `_000600_drop_exam_id_from_questions`
     * says of the column it cannot refill.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'slug']);
        });
    }
};
