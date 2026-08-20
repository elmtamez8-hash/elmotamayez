<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use Carbon\CarbonImmutable;

/**
 * What every module that holds a personal column must be able to answer
 * (spec 013 · NFR-004 · SC-004 · SC-006).
 *
 * ⚠️ THIS IS CONSTITUTION III APPLIED, NOT BROKEN. The obvious implementation of
 * "export everything about this person" is one Action in `Compliance` that names
 * thirteen schemas — which is the exact coupling the constitution forbids, on
 * columns with no guaranteed index. Instead `Compliance` names nobody: it walks a
 * tag. One line per module, the same shape as 003's `notification.channels`:
 *
 *     $this->app->tag([LearningPersonalData::class], 'compliance.personal_data');
 *
 * ⚠️ AND IT LIVES IN `Shared`, NOT IN `Compliance\Contracts`. Seven cross-module
 * interfaces already live here; the two under `{Module}/Contracts/` are consumers
 * reaching a provider adapter. Nothing in this tree has eleven modules
 * implementing an interface the twelfth owns.
 *
 * ⚠️ EVERY IMPLEMENTOR HAS FOUR OBLIGATIONS, and each comes from a defect that
 * has already happened in this repository:
 *
 *  1. `export()` COMPOSES the module's existing field allowlist rather than
 *     calling `->toArray()`. Six such lists ship today and four are owned by the
 *     modules themselves; a seventh central list would be a second answer that
 *     drifts. `->toArray()` exports every column added a year from now with no
 *     review at all.
 *  2. `export()` is a GENERATOR. Thirteen full arrays in memory, then a JSON
 *     encode of each, peaks at twice the serialised size — and the archive is
 *     measured at fifty thousand rows.
 *  3. `erase()` and `expire()` walk with `chunkById` (anonymise, because the row
 *     survives and needs a cursor) or a `->limit(n)->delete()` loop (delete).
 *     Never `chunk`: it paginates by OFFSET while the predicate shrinks underneath
 *     it, so every page after the first skips as many rows as the last one fixed —
 *     and reports success.
 *  4. Both return a COUNT and take a LIMIT, so the caller loops until the return
 *     is below the limit. That is what makes erasure resumable: a worker killed
 *     mid-walk resumes where it stopped instead of leaving a person half-erased,
 *     which is irreversible and is `SC-008` reached from behind.
 */
interface PersonalDataOwner
{
    /** The module's key, as written in `data_categories.owning_module`. */
    public function moduleKey(): string;

    /**
     * The category keys this module owns.
     *
     * @return list<string>
     */
    public function describe(): array;

    /**
     * Everything this module holds about the subject, category by category.
     *
     * ⚠️ `iterable`, NOT `array` — see obligation 2 above. Yield
     * `[$categoryKey => $rows]` so the caller can write each block to disk before
     * asking for the next one.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable;

    /**
     * Erase, anonymise or retain this subject's rows — at most `$limit` of them.
     *
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED. What the law obliges the platform
     * to keep is not a per-module decision.
     *
     * @return int rows processed. Below `$limit` means there is nothing left.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int;

    /**
     * Process rows of one category created before `$before`, for EVERYBODY.
     *
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP AT ALL. `erase()` takes a
     * person; retention takes an age and no person, so the largest thing this
     * phase delivers had no executable query until this existed. Its second
     * benefit is the larger one: the module owns the predicate, so the module
     * writes its index — which is why `(created_at)` indexes land in each
     * module's own migration rather than in one central file that knows everyone.
     *
     * @return int rows processed. Below `$limit` means there is nothing left.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int;
}
