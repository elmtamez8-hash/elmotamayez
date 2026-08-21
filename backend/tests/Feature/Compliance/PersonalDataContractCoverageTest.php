<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Contracts\PersonalDataOwner;

/**
 * Every module that stores personal data implements the contract (SC-004).
 *
 * ⚠️ THE SPEC SAYS "every module built after this phase MUST implement it in the
 * same PR", and a sentence like that with no test behind it is a good intention
 * with a three-month life. This is the test.
 *
 * ⚠️ AND IT DERIVES BOTH SIDES FROM THE MIGRATIONS, so it stays current on its own.
 * A hand-written list of modules goes stale the day someone adds one — silently,
 * which is the worst way for a guard to fail.
 *
 * ⚠️ AND IT INCLUDES THE ROOT `database/migrations/`, which the first design of
 * this check omitted. That directory is where `users` itself lives, along with
 * `activity_log`, `media` and `personal_access_tokens` — the most personal table
 * in the product was outside the derivation.
 */

/** Every directory holding migrations, module and root alike. */
function migrationDirectories(): array
{
    $directories = ['' => database_path('migrations')];

    foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
        $path = $module.'/Database/Migrations';

        if (is_dir($path)) {
            $directories[basename($module)] = $path;
        }
    }

    return $directories;
}

/** Whether any migration in this directory creates a personal column. */
function holdsPersonalColumns(string $directory): bool
{
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        $contents = (string) file_get_contents($file);

        /*
        | The two shapes a personal column takes in this tree: a foreign key to
        | `users`, or a bare contact detail with no account behind it. The second
        | is the one that gets forgotten — `invitations.email` holds an address for
        | someone who may never sign up, and no `constrained('users')` names it.
        */
        if (preg_match("/constrained\('users'\)|->string\('email'|->string\('phone'/", $contents) === 1) {
            return true;
        }
    }

    return false;
}

it('has a registered owner for every module that stores personal data', function (): void {
    /*
    | ⚠️ EXPLICIT EXCEPTIONS WITH A STATED REASON EACH. An unexplained entry here
    | is how this guard gets switched off one line at a time — the next person
    | adds theirs beside the others and nobody asks.
    |
    | - `Compliance` — its own tables ARE the rights machinery. A module that
    |   catalogued its own catalogue is a loop with no reader.
    | - `Gamification` — platform-owned progress rows whose ownership layers spec
    |   009 declared; they enter the catalogue when a retention decision is taken
    |   for them, which 013 does not take.
    | - `Analytics` — has no `Schema::create` of its own, so the derivation skips
    |   it without help. Named anyway, because "it happens to have no migrations"
    |   is a fact that could change.
    */
    $exempt = ['Compliance', 'Gamification', 'Analytics'];

    /** @var PersonalDataRegistry $registry */
    $registry = app(PersonalDataRegistry::class);
    $registered = $registry->moduleKeys();

    // Sanity, in the shape this repository uses everywhere: a registry that
    // resolved nothing would make every assertion below pass by finding nothing.
    expect($registered)->not->toBeEmpty()
        ->and($registered)->toContain('identity');

    $missing = [];

    foreach (migrationDirectories() as $module => $directory) {
        if ($module === '' || in_array($module, $exempt, true)) {
            continue;
        }

        if (holdsPersonalColumns($directory) && ! in_array(strtolower($module), $registered, true)) {
            $missing[] = $module;
        }
    }

    expect($missing)->toBe([]);
});

/*
 * ⚠️ AND THE ROOT DIRECTORY IS COVERED BY `Identity`, ASSERTED BY NAME.
 *
 * `users` lives in `database/migrations/`, which belongs to no module — so the
 * loop above skips it by construction and could never notice its absence. The
 * module that owns the account declares it, and this is what says so out loud.
 */
it('covers the root migrations, where users itself lives', function (): void {
    expect(holdsPersonalColumns(database_path('migrations')))->toBeTrue();

    $identity = collect(app(PersonalDataRegistry::class)->all())
        ->first(fn (PersonalDataOwner $owner): bool => $owner->moduleKey() === 'identity');

    expect($identity)->not->toBeNull()
        ->and($identity?->describe())->toContain('student_name')
        ->and($identity?->describe())->toContain('contact_phone');
});

/*
 * ⚠️ AND EVERY DECLARED CATEGORY RESOLVES TO EXACTLY ONE OWNER.
 *
 * Two modules claiming one category is a walk that exports it twice and an erasure
 * where each assumes the other did it; zero owners is a catalogue row the sweep
 * silently skips for ever. Neither shows up in any other test.
 */
it('resolves every catalogue category to exactly one owner', function (): void {
    $registry = app(PersonalDataRegistry::class);

    $claims = [];

    foreach ($registry->all() as $owner) {
        foreach ($owner->describe() as $category) {
            $claims[$category][] = $owner->moduleKey();
        }
    }

    $duplicated = array_keys(array_filter($claims, fn (array $owners): bool => count($owners) > 1));

    expect($duplicated)->toBe([]);

    $orphans = DataCategory::query()
        ->pluck('key')
        ->reject(fn (string $key): bool => $registry->forCategory($key) !== null)
        ->values()
        ->all();

    expect($orphans)->toBe([]);
});

/*
 * The registry's order is FIXED, and that is not cosmetic.
 *
 * The container returns tagged bindings in registration order, and module
 * providers are discovered by a directory scan — so the order can differ between
 * two machines or after any file is added. An archive whose files come out in a
 * different order each time makes comparing two exports pure noise.
 */
it('walks the owners in a stable order', function (): void {
    $keys = app(PersonalDataRegistry::class)->moduleKeys();
    $sorted = $keys;
    sort($sorted);

    expect($keys)->toBe($sorted);
});
