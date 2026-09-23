<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;

/*
| A changed password ends every OTHER `/admin` session on its next request.
|
| That is `AuthenticateSession` in `AdminPanelProvider`'s middleware list: it
| keeps the password hash in the session and logs the session out when the
| account's hash no longer matches. The panel handles money, so a password
| changed because it leaked has to take the thief's open tab with it — and
| nothing else in the tree does that for a web session.
|
| ⚠️ The environment stays `testing` and the fixture is a super admin, as in
| `PanelAccessOutsideLocalTest`: the panel refuses everyone else outside `local`.
*/

function passwordChangeAdmin(): User
{
    $admin = User::factory()->create(['password' => 'old-password']);
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin;
}

it('keeps a panel session open while the password is unchanged', function (): void {
    $admin = passwordChangeAdmin();

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
    $this->get('/admin')->assertSuccessful();
});

it('signs a panel session out once the password changes elsewhere', function (): void {
    $admin = passwordChangeAdmin();

    // The first request stores the current hash in the session.
    $this->actingAs($admin)->get('/admin')->assertSuccessful();

    // Changed from another device — the API's own password change, a reset link.
    $admin->forceFill(['password' => 'new-password'])->save();

    $this->get('/admin')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->assertGuest('web');
});
