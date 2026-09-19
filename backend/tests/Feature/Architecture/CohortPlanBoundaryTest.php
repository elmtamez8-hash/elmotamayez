<?php

declare(strict_types=1);

/*
| ٠٣٦ · T100 — الحدُّ بينَ `Learning` و`Payments`، ولا أحدَ منهما يملكُه.
|
| ⛔ THE WHOLE SPEC IS A BRIDGE BETWEEN TWO MODULES THAT MAY NOT SEE EACH OTHER.
| A group is Learning's; a price is Payments'; «is this group on sale» is a
| question neither can answer alone. The answer travels through two contracts in
| `App\Shared\Contracts` — and the moment either side reaches for the other's
| table directly, the contracts become decoration and the next reader writes a
| join. That is why the guard is a scan over SOURCE rather than a behavioural
| test: behaviour is identical the day somebody shortcuts it.
|
| ⚠️ IT SITS IN NEITHER MODULE'S FOLDER, DELIBERATELY. `ContextIsolationTest`
| lives under `Settlement` because settlement is the context being protected;
| here both sides are, and filing it under one of them makes it that module's
| test — read, and edited, by whoever is changing that module.
|
| ⚠️ AND COMMENTS ARE STRIPPED BEFORE THE SCAN. Every file that obeys these
| rules says so in a docblock naming the very thing being forbidden —
| `CohortPlanReach`'s own header spells out «`cohorts` belongs to `Learning` and
| this module may not name it». A red build over an explanation teaches people to
| delete the explanation, which is the lesson `TrustScoreJobIsolationTest`
| already paid for.
*/

/**
 * Every file of a module, with its comments gone.
 *
 * `moduleFiles()` and `codeWithoutComments()` are in `tests/Pest.php`: a Pest
 * helper is a GLOBAL function, and two test files declaring one name is a fatal
 * «Cannot redeclare» the first time a worker loads both.
 *
 * @return array<string, string> path ⇒ source
 */
function cohortPlanSources(string $module): array
{
    $out = [];

    foreach (moduleFiles($module) as $file) {
        $out[$file->getRelativePathname()] = codeWithoutComments((string) $file->getContents());
    }

    return $out;
}

/**
 * Which of a module's files contain this pattern.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function cohortPlanMatches(array $sources, string $pattern): array
{
    $hits = [];

    foreach ($sources as $path => $source) {
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $path;
        }
    }

    sort($hits);

    return $hits;
}

it('scans a module that actually has files in it', function (): void {
    /*
    | ⛔ THE SANITY HALF, AND EVERY ARCHITECTURAL GUARD NEEDS ONE. A scan over
    | zero files passes all three rules below by finding nothing — a renamed
    | directory, a `Finder` that matched no `*.php`, and this file goes green for
    | ever while the boundary is whatever anybody types.
    */
    expect(cohortPlanSources('Learning'))->not->toBeEmpty()
        ->and(cohortPlanSources('Payments'))->not->toBeEmpty();
});

it('never imports Payments from inside Learning', function (): void {
    /*
    | The direction that would be easiest to write: the group list wants to know
    | whether a price reaches each row, and `Plan::query()` is one import away.
    | `CohortPricing` asks `SellableCohortDirectory` instead, and the whole reason
    | that contract is bulk by signature is that the alternative reads so well.
    */
    expect(cohortPlanMatches(cohortPlanSources('Learning'), '/use\s+App\\\\Modules\\\\Payments\\\\/'))->toBe([]);
});

it('never names the plans table from inside Learning', function (): void {
    /*
    | ⚠️ THE TABLE, NOT THE MODEL — because `DB::table('plans')` needs no import
    | at all and the rule above would not see it. A subquery on `plans` inside a
    | query scope is the exact shortcut `Cohort::scopeJoinable()`'s own docblock
    | refuses, and it is the one that looks most reasonable: it makes the gate a
    | condition instead of a stamp, at the price of the boundary.
    */
    expect(cohortPlanMatches(cohortPlanSources('Learning'), '/[\'"]plans[\'"]/'))->toBe([]);
});

it('never names the cohorts table from inside Payments', function (): void {
    /*
    | ⛔ THE OTHER DIRECTION, AND IT IS THE ONE THAT NEARLY SHIPPED TWICE.
    | `CohortPlanReach` matches groups to plans in PHP over four result sets
    | precisely because it cannot join — which is why its caller hands over
    | triples of `(id, uuid, course_id, workspace_id)` rather than ids — and
    | `SavePlan`'s FR-013 warning counts member-carrying groups through a
    | `CohortDirectory` method for the same reason.
    |
    | ⚠️ AN EXACT QUOTED LITERAL, SO A PAYLOAD KEY SPELLED `'cohorts'` TRIPS IT
    | TOO — AND THAT IS KEPT RATHER THAN ALLOWLISTED. `PlanController` sends
    | `hidden_cohorts`, which is the more accurate name anyway; an exemption for
    | «but this one is only a JSON key» is the first line of the exemption list
    | that makes a guard stop guarding. The property `$this->cohorts` — the
    | injected contract — is not a quoted string and is untouched.
    */
    expect(cohortPlanMatches(cohortPlanSources('Payments'), '/[\'"]cohorts[\'"]/'))->toBe([]);
});
