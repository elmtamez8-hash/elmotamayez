<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Schema;

/**
 * The declared catalogue is compared against the LIVE SCHEMA (SC-002).
 *
 * ⚠️ AND THIS IS WHY `table_name` AND `column_name` ARE NOT NULLABLE. An earlier
 * design had them as nullable `*_hint` columns — which made the instrument of the
 * criterion optional, so a category could describe nothing and never fail.
 *
 * ⚠️ THE CHECK RUNS IN BOTH DIRECTIONS, AND ONE OF THEM IS THE ONE THAT DECAYS. A
 * catalogue entry pointing at a column that no longer exists is a privacy notice
 * describing data we do not hold; a personal table with no entry is data we hold
 * and never told anyone about. Only the first is caught by reading the catalogue.
 */
it('names a real table and a real column for every declared category', function (): void {
    $categories = DataCategory::query()->get();

    // Sanity, the shape this repository already uses: a scan that found nothing
    // would pass by finding nothing to check.
    expect($categories)->not->toBeEmpty();

    $missing = [];

    foreach ($categories as $category) {
        if (! Schema::hasTable($category->table_name)) {
            $missing[] = $category->key.' → table '.$category->table_name;

            continue;
        }

        if (! Schema::hasColumn($category->table_name, $category->column_name)) {
            $missing[] = $category->key.' → column '.$category->table_name.'.'.$category->column_name;
        }
    }

    expect($missing)->toBe([]);
});

/*
 * ⚠️ AND EVERY MODULE HOLDING PERSONAL ROWS DECLARES AT LEAST ONE CATEGORY.
 *
 * This is the direction that decays silently: a module added in 2027 with a
 * `student_user_id` column and no catalogue entry collects data nobody was told
 * about, and no other test in the suite would notice. Derived from the migrations
 * rather than listed, so it stays current on its own — and the exception list is
 * EXPLICIT with a reason for each entry, because an unexplained exception is how
 * this guard is eventually turned off one line at a time.
 */
it('has a declared category for every module that stores a personal column', function (): void {
    /*
    | Modules with no personal data at all, each for a stated reason:
    |
    | - `Analytics`   — has no `Schema::create` of its own. Its screens read other
    |                   modules' tables through their own models.
    | - `Compliance`  — its own tables ARE the record of the rights machinery.
    |                   Cataloguing the catalogue is a loop with no reader.
    | - `Gamification`— platform-owned progress rows, and 009 declared their own
    |                   ownership layers; they enter the catalogue when a
    |                   retention decision is taken for them, which 013 does not
    |                   take.
    */
    $exempt = ['Analytics', 'Compliance', 'Gamification'];

    $declared = DataCategory::query()->pluck('owning_module')->map(strtolower(...))->unique()->all();

    $undeclared = [];

    foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $directory) {
        $module = basename($directory);

        if (in_array($module, $exempt, true)) {
            continue;
        }

        $holdsPersonalRows = false;

        foreach (glob($directory.'/Database/Migrations/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            // The three shapes a personal column takes in this tree: a foreign
            // key to `users` declared EITHER WAY, or a bare contact detail with
            // no account behind it. See the note in
            // `PersonalDataContractCoverageTest` — the `unsignedBigInteger` half
            // was missing and hid the whole Community module for six phases.
            if (preg_match('/constrained\(\'users\'\)|unsignedBigInteger\(\'[a-z_]*user_id\'\)|->string\(\'email\'|->string\(\'phone\'/', $contents) === 1) {
                $holdsPersonalRows = true;

                break;
            }
        }

        if ($holdsPersonalRows && ! in_array(strtolower($module), $declared, true)) {
            $undeclared[] = $module;
        }
    }

    expect($undeclared)->toBe([]);
});

/*
 * ⚠️ `class_recording` IS REQUIRED, AND THAT ONE ROW IS ALL OF DECISION Q4
 * (SC-003).
 *
 * There is no separate recording-consent entity in this product — appearing in a
 * class recording, voice and image, is a REQUIRED category inside the single
 * consent. Making it optional produces a class a teacher may not record because
 * one seat withdrew, which is a different product.
 */
it('declares appearing in a class recording as required, with no second consent entity', function (): void {
    $recording = DataCategory::query()->where('key', 'class_recording')->first();

    expect($recording)->not->toBeNull()
        ->and($recording?->is_required)->toBeTrue()
        // Said in words a parent can read, which is what FR-004 asks for — and
        // what a screen shows verbatim.
        ->and($recording?->label_ar)->toContain('صوتاً وصورةً');

    // And no second consent table crept in beside `terms_consents`.
    foreach (['recording_consents', 'processing_consents', 'consent_records'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});

/*
 * The catalogue is the PLATFORM's — the highest tenant role fails every write.
 *
 * ⚠️ AND THE CLASSIFICATION IS DERIVED BY ABSENCE, which is exactly the property
 * that can be perfectly true of a permission nothing calls. `taxonomy.manage`
 * spent a whole phase in that state.
 */
it('keeps the registry permissions out of every workspace role', function (): void {
    $platform = RolePermissionMatrix::platformPermissions();
    $owner = RolePermissionMatrix::map()[Roles::TENANT_OWNER];

    foreach ([
        Permissions::COMPLIANCE_REGISTRY_MANAGE,
        Permissions::COMPLIANCE_REQUESTS_EXECUTE,
        Permissions::COMPLIANCE_HOLDS_MANAGE,
        Permissions::COMPLIANCE_OFFBOARDING_EXECUTE,
        Permissions::COMPLIANCE_BREACHES_MANAGE,
    ] as $permission) {
        expect($platform)->toContain($permission)
            ->and($owner)->not->toContain($permission);
    }
});

/*
 * ⚠️ AND EVERY CONSTANT IS IN `all()`, WHICH IS HAND-WRITTEN.
 *
 * A name missing from that list is never seeded — so every check against it fails
 * for EVERYBODY including the platform administrator, silently, and the symptom
 * appears a long way from the omission.
 */
it('lists every declared permission constant in all()', function (): void {
    $reflection = new ReflectionClass(Permissions::class);
    $declared = array_values($reflection->getConstants());

    $missing = array_values(array_diff(
        array_filter($declared, is_string(...)),
        Permissions::all(),
    ));

    expect($missing)->toBe([]);
});
