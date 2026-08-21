<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Exceptions\LegalHoldInForce;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\LegalHold;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Modules\Compliance\Support\DataSubjectResolver;
use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Actions\Action;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Erase one person, module by module, resumably (FR-020 … FR-023 · SC-006).
 *
 * ⚠️ THE HOLD IS RE-READ AT THE HEAD OF EVERY BATCH, NOT ONCE AT THE TOP. A hold
 * has three doors — before the request starts, before the nightly sweep, and
 * WHILE AN ERASURE IS ALREADY WALKING — and the third is the one readers miss.
 * This walk runs for minutes, so a check made when the job started is stale for
 * the rest of it, and nothing here reverses. `PlaceLegalHold` covers the first two;
 * this covers the third, and neither half is sufficient alone.
 *
 * ⚠️ THE MODE IS READ FROM THE CATEGORY, NEVER DECIDED HERE OR BY THE MODULE.
 * Erasure is three grades because the law is three grades. A module choosing its
 * own would be a module deciding what the platform is obliged to keep, and this
 * Action choosing one would put that decision in code instead of in the catalogue
 * an operator can read. Disagreement inside one module, or a category nothing
 * declares, resolves to **Retain and a logged error** — there is no "stricter" of
 * Delete and Retain (one is strict about privacy, the other about the law, and
 * they have no order between them), so an unclear instruction fails toward keeping.
 *
 * ⚠️ AND EVERY `erase()` MUST SHRINK ITS OWN PREDICATE. The loop runs until a
 * module returns fewer rows than the limit, so an anonymising pass that still
 * matches the rows it just processed never terminates — inside a 900-second job
 * with `tries: 1` that is a timeout kill, a sweep revival, and the identical run
 * again, for ever, with nothing in the log to say so. The per-owner batch ceiling
 * below turns that from an invisible loop into a line naming the module.
 */
class ExecuteDataErasure extends Action
{
    /**
     * The most batches one module may take before this gives up on it.
     *
     * At the shipped batch sizes that is a million rows for a delete pass — far
     * beyond any real person — so reaching it means a predicate that does not
     * shrink, not a large account. The `recording_attempts` idiom: a budget makes
     * an undetectable loop a diagnosable failure.
     */
    private const MAX_BATCHES_PER_OWNER = 1_000;

    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly DataSubjectResolver $resolver,
    ) {}

    /**
     * @return array<string, int> rows processed, by module key
     */
    public function handle(DataRequest $request): array
    {
        /*
        | `forRequest`, not `forUser($request->subject)`: the relation is nullable in
        | type and the resolver already loads the row by id. The granted scope it
        | carries is inert here — `erase()` never consults `mayReceive()`, because a
        | guardian authorised to ASK for an erasure is asking for all of it.
        */
        $subject = $this->resolver->forRequest($request);

        $this->assertNotHeld($subject);

        $processed = [];

        /*
        | ⚠️ IDENTITY RUNS LAST, AND THE REGISTRY'S ALPHABETICAL ORDER PUTS IT
        | FIFTH. `TenancyPersonalData` finds invitation rows by `where('email',
        | $email)` — the only handle on somebody who was invited and never signed
        | up — so anonymising `users.email` first leaves those rows holding the real
        | address with nothing left to match them by. It survives a single pass only
        | because the in-memory `$subject->user` still carries the old value; a
        | resumed erasure re-resolves the subject from the anonymised row and the
        | addresses survive, which is FR-020 and FR-023 broken by an ordering
        | nobody chose.
        */
        $owners = $this->registry->all();
        $identity = array_values(array_filter($owners, fn (PersonalDataOwner $o): bool => $o->moduleKey() === 'identity'));
        $others = array_values(array_filter($owners, fn (PersonalDataOwner $o): bool => $o->moduleKey() !== 'identity'));

        foreach ([...$others, ...$identity] as $owner) {
            $processed[$owner->moduleKey()] = $this->eraseOwner($owner, $subject);
        }

        /*
        | ⚠️ AND THE PERSON'S OWN EXPORT ARCHIVES GO WITH THEM. A completed export
        | stays downloadable for `export_ttl_hours`, and it is by construction a
        | complete pre-erasure copy of everything this walk just destroyed, sitting
        | on our disk behind a link that still works. "Irreversible" does not
        | survive leaving it there.
        */
        $processed['exports'] = $this->purgeExports($subject);

        return $processed;
    }

    private function eraseOwner(PersonalDataOwner $owner, DataSubject $subject): int
    {
        $mode = $this->modeFor($owner);

        if ($mode === ErasureMode::Retain) {
            return 0;
        }

        $limit = ComplianceSettings::batchSize(
            $mode === ErasureMode::Delete ? 'delete' : 'anonymise',
        );

        $total = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES_PER_OWNER; $batch++) {
            // The third door. Read before EVERY batch, not once at the top.
            $this->assertNotHeld($subject);

            /*
            | ⚠️ ONE TRANSACTION PER BATCH, NOT ONE FOR THE WHOLE PERSON AND NOT ONE
            | PER ROW. A single transaction over minutes holds locks across every
            | table a person touches; none at all leaves a half-anonymised row — a
            | name cleared and a phone kept — which is a re-identification hole that
            | does not reverse. The batch is the unit that is both bounded and
            | complete.
            */
            $done = DB::transaction(fn (): int => $owner->erase($subject, $mode, $limit));

            $total += $done;

            if ($done < $limit) {
                return $total;
            }
        }

        /*
        | The budget is spent. Every real account finishes in a handful of batches,
        | so this is a predicate that does not shrink — the module is returning the
        | same rows it already processed. Logged with the module named, and the walk
        | carries on: one broken implementor must not stop the other twelve from
        | erasing the person who asked.
        */
        Log::error('compliance.erasure.budget_exhausted', [
            'module' => $owner->moduleKey(),
            'subject_user_id' => $subject->user->getKey(),
            'rows' => $total,
        ]);

        return $total;
    }

    /**
     * The grade this module's categories declare.
     *
     * They are read together rather than one at a time because `erase()` receives
     * ONE mode for the module — the contract's shape, and the reason a module whose
     * categories disagree is a modelling error rather than a case to handle.
     */
    private function modeFor(PersonalDataOwner $owner): ErasureMode
    {
        $modes = DataCategory::query()
            ->whereIn('key', $owner->describe())
            ->pluck('erasure_mode')
            ->map(fn (mixed $mode): string => $mode instanceof ErasureMode ? $mode->value : (string) $mode)
            ->unique()
            ->values()
            ->all();

        if (count($modes) === 1) {
            return ErasureMode::from($modes[0]);
        }

        Log::error('compliance.erasure.unclear_mode', [
            'module' => $owner->moduleKey(),
            'categories' => $owner->describe(),
            'modes' => $modes,
        ]);

        return ErasureMode::Retain;
    }

    /** @return int archives removed */
    private function purgeExports(DataSubject $subject): int
    {
        $disk = Storage::disk(ComplianceSettings::exportDisk());
        $removed = 0;

        DataRequest::query()
            ->where('subject_user_id', $subject->user->getKey())
            ->whereNotNull('export_path')
            ->each(function (DataRequest $request) use ($disk, &$removed): void {
                $path = (string) $request->export_path;

                if ($path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }

                // The file goes before the pointer to it: the other order loses the
                // only handle on the archive if the delete fails halfway, leaving an
                // orphan nothing will ever look for again.
                $request->forceFill(['export_path' => null, 'export_expires_at' => null])->save();

                $removed++;
            });

        return $removed;
    }

    private function assertNotHeld(DataSubject $subject): void
    {
        if (LegalHold::heldFor((int) $subject->user->getKey())) {
            throw new LegalHoldInForce('محوُ بيانات هذا الحساب موقوفٌ بتعليقٍ قانونيّ سارٍ.');
        }
    }
}
