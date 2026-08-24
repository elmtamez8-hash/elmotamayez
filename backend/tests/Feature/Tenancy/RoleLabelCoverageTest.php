<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\RoleLabels;
use App\Modules\Tenancy\Support\Roles;

/*
| Every seeded role has Arabic, and the fallback is what hid it.
|
| ⚠️ THIS EXISTS BECAUSE A FALLBACK THAT RETURNS SOMETHING READABLE HIDES ITS OWN
| FAILURE. `RoleLabels::for()` answers with the role NAME when it has no entry —
| which is the right answer for a role an owner created and named themselves, and
| a silent defect for a seeded one. `tenant-owner` sat in a badge on `/members`
| looking like a deliberate choice; so did four others.
|
| ⚠️ AND IT ASSERTS THE LABEL IS NOT THE NAME, not merely that a key exists. An
| entry mapping a role to its own slug would satisfy `array_key_exists` and render
| exactly the English the screen is not allowed to show.
*/

it('has Arabic for every seeded role', function (): void {
    foreach (Roles::all() as $role) {
        $label = RoleLabels::for($role);

        expect($label)->not->toBe($role, "الدور «{$role}» بلا ترجمة عربيّة في RoleLabels.");
        expect($label)->not->toMatch('/[A-Za-z]/', "ترجمة الدور «{$role}» ما زالت تحمل حروفاً لاتينيّة.");
    }
});

it('returns an unknown role unchanged rather than blank', function (): void {
    // A role an owner created from `/admin`. They named it, in their own words,
    // for their own workspace — echoing it back is correct, and an empty badge
    // would be the alternative.
    expect(RoleLabels::for('مساعد التصحيح'))->toBe('مساعد التصحيح')
        ->and(RoleLabels::for(null))->toBeNull();
});
