<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Shared\Contracts\PersonalDataOwner;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How every implementor of {@see PersonalDataOwner} walks its own rows (spec 013).
 *
 * ⚠️ ONE HELPER RATHER THAN FIFTEEN COPIES OF A SEVEN-LINE LOOP, and the reason is
 * not brevity. The loop encodes two rules that are each a defect when got wrong,
 * and a rule copied fifteen times is a rule that holds in fourteen places:
 *
 *  1. `lazyById`, NEVER `chunk`. `chunk` paginates by OFFSET, so a walk whose
 *     predicate shrinks underneath it skips as many rows as the previous page
 *     fixed — and reports success. The same trap cost 016's uuid backfill a fix.
 *  2. Pages are yielded as they fill. An implementor that built one array per
 *     category and returned it would defeat the generator contract at the last
 *     line, and `SC-014` measures fifty thousand rows against a worker with a
 *     memory ceiling and `tries: 1` — an out-of-memory kill is not retried.
 *
 * It lives in `Shared` because thirteen modules use it and none of them owns it;
 * putting it in `Compliance` would make every module import from the one module
 * that is forbidden to know they exist.
 */
final class ExportWalk
{
    /**
     * Pages of rows for one category, ready to `yield from`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $row
     * @param  string|null  $column  the cursor column, QUALIFIED whenever the query
     *                               joins: a bare `id` is ambiguous in SQL and the
     *                               error arrives at run time, inside a queued job
     * @return iterable<string, list<array<string, mixed>>>
     */
    public static function keyed(
        string $category,
        Builder $query,
        callable $row,
        int $size = 500,
        ?string $column = null,
    ): iterable {
        $page = [];

        /*
        | ⚠️ THE ALIAS IS THE UNQUALIFIED HALF, AND OMITTING IT IS A LATENT BUG THAT
        | ONLY APPEARS PAST THE FIRST PAGE. `lazyById` reads the cursor off the last
        | model as `$alias ?? $column`, so a qualified `lesson_progress.id` looks for
        | an ATTRIBUTE of that name — which no model has — and aborts with "the
        | column is not present in the query result". It never asks until it needs a
        | second page, so every fixture under the page size passes and the failure
        | arrives on the first person with real data.
        */
        $dot = $column === null ? false : strrpos($column, '.');
        $alias = $dot === false ? null : substr((string) $column, $dot + 1);

        foreach ($query->lazyById($size, $column, $alias) as $model) {
            $page[] = $row($model);

            if (count($page) === $size) {
                yield $category => $page;

                $page = [];
            }
        }

        /*
        | ⚠️ THE LAST PAGE IS YIELDED EVEN WHEN IT IS EMPTY, which is what makes an
        | empty `payment_record.json` appear in the archive. "We hold nothing of
        | this kind about you" is an ANSWER; a missing file is silence, and the
        | difference is the whole point of a rights request.
        */
        yield $category => $page;
    }

    /**
     * Empty pages for categories this walk has nothing to say about.
     *
     * ⚠️ AN EARLY `return` IS NOT THE SAME AS YIELDING NOTHING, and the difference
     * is a file that does not exist. A module that bails out — no teacher profile,
     * no workspace, a guardian not entitled to this category — produces no key at
     * all, so `ExecuteDataExport` never opens the file, and the archive is silently
     * missing a section. "We hold nothing of this kind about you" is an ANSWER a
     * person asking is entitled to; silence is not.
     *
     * ⚠️ AND IT MAKES "NOTHING" AND "NOT YOURS" LOOK IDENTICAL, which is the right
     * way round: an empty `exam_attempt.json` tells an attendance-only guardian
     * nothing about whether their child sat any exams.
     *
     * @return iterable<string, list<array<string, mixed>>>
     */
    public static function none(string ...$categories): iterable
    {
        foreach ($categories as $category) {
            yield $category => [];
        }
    }

    /**
     * A timestamp as ISO 8601, whatever shape it arrived in.
     *
     * ⚠️ IT TAKES `mixed` ON PURPOSE. Some of these columns are cast to a date on
     * their model and some are not, and a value pulled through a JOIN alias
     * (`class_sessions.starts_at as session_starts_at`) is always a raw string
     * however the owning model casts it. Formatting at each call site would put two
     * date shapes in one archive, and the reader has no way to know which file uses
     * which.
     */
    public static function at(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = date_create_immutable($value);

        return $parsed === false ? $value : $parsed->format(DateTimeInterface::ATOM);
    }
}
