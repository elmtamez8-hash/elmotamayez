<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The public URL segment, replacing the uuid on `/teachers/{…}`.
     *
     * ⚠️ Unique WITHOUT a workspace in the key, unlike almost everything else in
     * this module. `/teachers/{slug}` is one platform-wide namespace read by
     * guests, and `unique(workspace_id, slug)` would let two workspaces claim
     * the same URL — after which the public route resolves to whichever row the
     * database happened to return first.
     *
     * Nullable so the column can be added before it is filled: the backfill runs
     * in the same migration, but a NOT NULL column on a populated table has to
     * invent a value for every existing row before the backfill can compute one.
     */
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('uuid');
        });

        $this->backfill();
    }

    /**
     * Give every existing profile a slug.
     *
     * Deliberately NOT TeacherSlug::for(): a migration that calls application
     * code runs whatever that code says a year from now, against a schema from
     * today. The generation rule is copied here on purpose, and the duplicate is
     * the point — this method describes what the column held on the day it was
     * added, and must keep working if the rule changes tomorrow.
     *
     * chunkById, not chunk: the predicate (`slug IS NULL`) shrinks as the walk
     * fills it, and OFFSET paging would skip as many rows per page as the
     * previous page fixed, then report success.
     */
    private function backfill(): void
    {
        $taken = [];

        // ICU, matching TeacherSlug: Str::transliterate turns «فاطمة الهاشمي»
        // into `ftm-at-lhshmy`, dropping the alif of «ال». The fallback is for a
        // host without intl. Built once, not once per row.
        $transliterator = class_exists(Transliterator::class)
            ? Transliterator::create('Any-Latin; Latin-ASCII; Lower()')
            : null;

        DB::table('teacher_profiles')
            ->join('users', 'users.id', '=', 'teacher_profiles.user_id')
            ->whereNull('teacher_profiles.slug')
            ->select([
                'teacher_profiles.id',
                'users.first_name',
                'users.last_name',
            ])
            ->orderBy('teacher_profiles.id')
            ->chunkById(200, function ($rows) use (&$taken, $transliterator): void {
                foreach ($rows as $row) {
                    $name = trim($row->first_name.' '.($row->last_name ?? ''));

                    $latin = $transliterator?->transliterate($name);
                    $base = Str::slug(is_string($latin) ? $latin : Str::transliterate($name));

                    if ($base === '') {
                        $base = 'teacher';
                    }

                    $slug = $base;
                    $suffix = 1;

                    // In-memory as well as in-table: rows written inside this same
                    // chunk are not visible to the walk's own query.
                    while (isset($taken[$slug]) || DB::table('teacher_profiles')->where('slug', $slug)->exists()) {
                        $suffix++;
                        $slug = $base.'-'.$suffix;
                    }

                    $taken[$slug] = true;

                    DB::table('teacher_profiles')->where('id', $row->id)->update(['slug' => $slug]);
                }
            }, 'teacher_profiles.id', 'id');
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
