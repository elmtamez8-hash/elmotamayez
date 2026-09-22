<?php

declare(strict_types=1);

/*
| ⛔ FOUR SQL FORMS THAT PASS EVERY SHARD OF CI AND BREAK PRODUCTION ONLY.
|
| This repository runs its entire suite on in-memory SQLite and deploys to MySQL.
| Spec 038 walked into four divergences where the WRONG form is the one everybody
| reaches for, and no amount of running the tests can see any of them:
|
|   `||`            string concatenation here, LOGICAL OR there — so
|                   `'anonymised:' || id` stores `'1'` in every row, collides on
|                   `unique(user_id, fingerprint_hash)` at a user's second device,
|                   rolls back the whole erasure transaction, and never converges.
|   `CONCAT()`      SQLite 3.44+; the CI runner's version is not ours to assume.
|   `NOT IN (SELECT … FROM <the same table>)`
|                   MySQL ERROR 1093; SQLite rewrites it to `rowid in (…)` and it
|                   passes green for ever.
|   `groupBy` with no explicit select
|                   compiles to `select * … group by`, which is ERROR 1055 under
|                   `ONLY_FULL_GROUP_BY` — and Laravel puts that in the sql_mode
|                   unconditionally for `'strict' => true`, which
|                   `config/database.php` sets with no `modes` override.
|
| ⚠️ A SOURCE SCAN IS THE ONLY INSTRUMENT THAT REACHES THEM, and this repository
| already owns the shape: `TrustScoreJobIsolationTest` and `ContextIsolationTest`.
|
| ⚠️ AND COMMENTS ARE STRIPPED FIRST. Every one of these rules is written down
| BESIDE the code that obeys it — a raw scan fails on its own explanation, and a
| red build over an explanation teaches the next person to delete the explanation.
| That is the fix `TrustScoreJobIsolationTest` had to make for exactly this reason.
*/

/** @return list<string> */
function retentionSourceFiles(): array
{
    return [
        app_path('Modules/Identity/Support/AuthSessionRetention.php'),
        app_path('Modules/Identity/Support/IdentityPersonalData.php'),
        app_path('Modules/Identity/Jobs/EnforceAuthSessionCapJob.php'),
    ];
}

function retentionSourceWithoutComments(string $php): string
{
    $kept = '';

    foreach (token_get_all($php) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $kept .= is_array($token) ? $token[1] : $token;
    }

    return $kept;
}

it('builds no anonymised value in SQL', function (): void {
    foreach (retentionSourceFiles() as $file) {
        $code = retentionSourceWithoutComments((string) file_get_contents($file));

        expect($code)->not->toContain('CONCAT(', basename($file).' must build the value in PHP.');

        // `||` inside a string literal handed to the database. The PHP operator is
        // spelled the same, so the scan looks for it next to a quote.
        expect(preg_match('/[\'"][^\'"]*\|\|[^\'"]*[\'"]/', $code))
            ->toBe(0, basename($file).' passes `||` into SQL; it is logical OR on MySQL.');
    }
});

it('never subqueries the table it is deleting from', function (): void {
    foreach (retentionSourceFiles() as $file) {
        $code = retentionSourceWithoutComments((string) file_get_contents($file));

        expect($code)->not->toContain('NOT IN (SELECT', basename($file).' risks MySQL ERROR 1093.');
        expect($code)->not->toContain('not in (select', basename($file).' risks MySQL ERROR 1093.');
    }
});

it('always names its select list before grouping', function (): void {
    $checked = 0;

    foreach (retentionSourceFiles() as $file) {
        $code = retentionSourceWithoutComments((string) file_get_contents($file));

        /*
        | ⚠️ PER STATEMENT, AND ONLY WHERE A QUERY IS BEING BUILT. `->groupBy()`
        | on a Collection is an ordinary, correct thing to write — the first draft
        | of this guard fired on exactly that in `AuthSessionRetention`, and a
        | guard that reddens correct code is a guard the next person deletes.
        | A grouping that reaches the DATABASE always has `::query()` or `query()`
        | in the same statement.
        */
        foreach (explode(';', $code) as $statement) {
            if (! str_contains($statement, '->groupBy(')) {
                continue;
            }

            /*
            | ⚠️ `query()` ALONE WAS NOT THE DISCRIMINATOR, and the counter below
            | is what said so: the cap job's grouping starts from a private helper
            | that calls `AuthSession::query()` in a DIFFERENT statement, so the
            | scan matched nothing and reported success. `->having()` travels with
            | the grouping itself.
            */
            if (! str_contains($statement, '->having(') && ! str_contains($statement, 'query()')) {
                continue;
            }

            $checked++;

            expect(str_contains($statement, '->select(') || str_contains($statement, '->selectRaw('))
                ->toBeTrue(basename($file).' groups a QUERY without an explicit select; that is `select *` (ERROR 1055).');
        }
    }

    /*
    | ⛔ AND THE GUARD PROVES IT LOOKED. A scan whose filter stops matching — a
    | renamed method, a chain split across statements — reports success having
    | examined nothing, which is the vacuous shape this whole feature was reviewed
    | for. If the cap job's discovery query moves, this line is what says so.
    */
    expect($checked)->toBeGreaterThan(0, 'The scan matched no grouped query at all — the filter has drifted.');
});
