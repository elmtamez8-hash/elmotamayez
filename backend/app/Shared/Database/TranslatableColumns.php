<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turning an `x_ar` column into a translatable `x` JSON column, and back.
 *
 * ⚠️ THREE STATEMENTS, NEVER `->change()`. Renaming and retyping in one
 * alteration is a TABLE REBUILD on SQLite — every test in this repository runs
 * on in-memory SQLite — and this file has refused that trade twice already
 * (`_000600_drop_exam_id_from_questions`, the `dropIndex` rule below it). Add ·
 * backfill · drop is engine-neutral and each step is separately reversible.
 *
 * ⚠️ AND THE BACKFILL IS PHP, NOT `JSON_OBJECT()`. MySQL has that function and
 * SQLite's `json_object()` exists only when the build carries JSON1 — so a SQL
 * backfill is a migration that passes locally and may not run where it matters,
 * which is the shape of every "SQLite hides it" defect this repository records.
 * `chunkById`, never `chunk`: the predicate shrinks under an OFFSET walk and
 * every page after the first skips as many rows as the previous page fixed.
 *
 * ⚠️ THE NEW COLUMN IS NULLABLE WHERE THE OLD ONE WAS NOT, DELIBERATELY. Making
 * it `NOT NULL` afterwards needs `->change()`, the rebuild above; and MySQL
 * before 8.0.13 accepts no DEFAULT on a JSON column, so it cannot be born
 * non-null with a value either. The guard moves to the model, where
 * `HasTranslations` is what writes the shape anyway.
 *
 * ⚠️ AND THE INDEX GOES FIRST, IN ITS OWN STATEMENT. MySQL discards a
 * single-column index with its column and never complains; SQLite's native
 * `DROP COLUMN` REFUSES an indexed one — and SQLite is what the suite runs on,
 * so the redundant-looking `dropIndex` is the only form that runs at all here.
 */
final class TranslatableColumns
{
    /**
     * @param  array<string, string>  $columns  old `x_ar` name => new `x` name
     * @param  list<string>  $indexed  old names that carry an index of their own
     */
    public static function toJson(string $table, array $columns, array $indexed = [], string $locale = 'ar'): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            foreach ($columns as $new) {
                $blueprint->json($new)->nullable();
            }
        });

        foreach ($columns as $old => $new) {
            self::backfill($table, $old, $new, $locale);
        }

        foreach ($indexed as $old) {
            Schema::table($table, function (Blueprint $blueprint) use ($old): void {
                $blueprint->dropIndex([$old]);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->dropColumn(array_keys($columns));
        });
    }

    /**
     * The way back: the chosen locale's string becomes the column again.
     *
     * ⚠️ A ROW WHOSE ONLY TRANSLATION IS ANOTHER LOCALE LOSES IT HERE, and that
     * is written down rather than guarded: a `varchar` cannot hold two languages,
     * which is the whole reason for the change this undoes. Rolling back after
     * an English translation has been written is a decision, not an accident.
     *
     * ⚠️ AND THE RESTORED COLUMN IS A NULLABLE `text`, NOT WHATEVER IT WAS. The
     * originals were a mix of `string(200)` and `text`, some NOT NULL; putting a
     * width or a constraint back needs `->change()`, the table rebuild this file
     * refuses at the top. A rollback therefore restores the DATA and a looser
     * shape — which is the same trade `_000600_drop_exam_id_from_questions`
     * records, and it is stated here rather than discovered.
     */
    /** @param  array<string, string>  $columns  old `x_ar` name => new `x` name */
    public static function toStrings(string $table, array $columns, string $locale = 'ar'): void
    {
        $flipped = array_flip($columns);

        Schema::table($table, function (Blueprint $blueprint) use ($flipped): void {
            foreach ($flipped as $old) {
                $blueprint->text($old)->nullable();
            }
        });

        foreach ($flipped as $new => $old) {
            self::unbackfill($table, $new, $old, $locale);
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->dropColumn(array_values($columns));
        });
    }

    /**
     * Has this table's translatable column landed yet?
     *
     * ⚠️ A RUNTIME CATALOGUE SEEDER IS ALSO CALLED FROM A MIGRATION, and the
     * backfill migrations that call it are DATED BEFORE the conversion. So on a
     * fresh database — every `RefreshDatabase` run in the suite — the seeder
     * runs while the column is still `x_ar` and writes `x`: «no such column»,
     * and the whole suite dies in `migrate:fresh`. The seeder returns early
     * instead, and each conversion migration calls it again at the end, so a
     * database part-way through the sequence still ends up with every row.
     *
     * Inert once the conversion has run, which on production it already has.
     * Same shape as `TaxonomySeeder`'s existing `Schema::hasTable` guard: a
     * backfill's own place in the sequence is a fact it has to check.
     */
    public static function converted(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * The column that exists on this table RIGHT NOW, carrying $text.
     *
     * ⚠️ FOR A MIGRATION THAT MAY RUN ON EITHER SIDE OF THE CONVERSION. A
     * backfill dated before it writes `x_ar` on a fresh database and `x` on one
     * where 055 has already landed — and `ReferralCatalogueTest` runs exactly
     * that second case, deleting the row and calling the migration's own `up()`
     * against a fully migrated database. A migration speaks the schema of its own
     * date, and this is how it asks what that schema is.
     *
     * The encoding matches what `HasTranslations::setTranslation()` writes —
     * unescaped unicode and unescaped slashes — so a row written here and a row
     * written by the model are byte-identical, which is what keeps a `LIKE`
     * search over the document finding both.
     *
     * @return array<string, string>
     */
    public static function forWrite(string $table, string $old, string $new, string $text, ?string $locale = null): array
    {
        if (! self::converted($table, $new)) {
            return [$old => $text];
        }

        return [$new => (string) json_encode(
            [$locale ?? app()->getLocale() => $text],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )];
    }

    private static function backfill(string $table, string $old, string $new, string $locale): void
    {
        DB::table($table)
            ->select(['id', $old])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $old, $new, $locale): void {
                foreach ($rows as $row) {
                    $value = $row->{$old};

                    DB::table($table)->where('id', $row->id)->update([
                        // `JSON_UNESCAPED_UNICODE`, or every Arabic string in the
                        // database becomes a `أ…` escape three times its
                        // length — readable by the app and by nobody else.
                        $new => $value === null ? null : json_encode([$locale => $value], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
    }

    private static function unbackfill(string $table, string $new, string $old, string $locale): void
    {
        DB::table($table)
            ->select(['id', $new])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $new, $old, $locale): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->{$new}, true);

                    DB::table($table)->where('id', $row->id)->update([
                        $old => is_array($decoded) ? ($decoded[$locale] ?? null) : null,
                    ]);
                }
            });
    }
}
