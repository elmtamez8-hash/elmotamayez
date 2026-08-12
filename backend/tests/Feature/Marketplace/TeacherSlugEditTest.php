<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->workspace = marketplaceWorkspace('Academy');
});

it('lets a teacher rename their public url', function () {
    $teacher = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($teacher->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => 'ahmed-almansouri'])
        ->assertOk()
        ->assertJsonPath('slug', 'ahmed-almansouri');

    expect($teacher->fresh()?->slug)->toBe('ahmed-almansouri');

    $this->asGuest();
    $this->getJson('/api/v1/marketplace/teachers/ahmed-almansouri')->assertOk();
});

it('lowercases and trims before validating', function () {
    // A teacher pasting a value with a capital in it should be told it is
    // available, not told about its casing — and the value checked for
    // uniqueness has to be the value stored.
    $teacher = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($teacher->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => '  Ahmed-Almansouri '])
        ->assertOk()
        ->assertJsonPath('slug', 'ahmed-almansouri');
});

it('refuses a slug shaped like a uuid', function () {
    // ⚠️ Not a formatting nit. ShowPublicTeacher resolves `slug OR uuid` so old
    // links keep working; a teacher whose slug IS another teacher's uuid makes
    // that lookup match two rows, and the victim's URL starts serving the
    // attacker's profile whenever the database returns theirs first.
    $victim = marketplaceTeacher($this->workspace);
    $attacker = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($attacker->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => $victim->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('refuses a slug another teacher already holds', function () {
    $first = marketplaceTeacher($this->workspace);
    $second = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($second->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => (string) $first->slug])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('refuses a slug held in another workspace', function () {
    // The URL is one platform-wide namespace. A uniqueness check that respected
    // the workspace scope would pass here and let the unique index reject the
    // write instead — a 500 where a 422 belongs.
    $other = marketplaceWorkspace('Other Academy');
    $theirs = marketplaceTeacher($other);
    $mine = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($mine->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => (string) $theirs->slug])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('accepts the slug the teacher already has', function () {
    // Saving an unchanged form must not fail on "this slug is taken" by the very
    // row being saved.
    $teacher = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($teacher->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => (string) $teacher->slug])
        ->assertOk();
});

it('rejects characters that do not belong in a url', function (string $slug) {
    $teacher = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($teacher->user);

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => $slug])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with([
    'arabic' => 'أحمد-المنصوري',
    'spaces' => 'ahmed almansouri',
    'slash' => 'ahmed/almansouri',
    'leading hyphen' => '-ahmed',
    'double hyphen' => 'ahmed--almansouri',
    'too short' => 'ab',
]);

it('refuses an account with no teacher profile', function () {
    // A student, a guardian, an admin — signed in, but with no public page to
    // rename. 403 rather than 404: the account exists.
    $student = User::factory()->create(['platform_role' => 'student']);

    Sanctum::actingAs($student);
    $this->asGuest();

    $this->putJson('/api/v1/teacher/profile/slug', ['slug' => 'someone-else'])
        ->assertForbidden();
});

it('reports the current slug to the settings card', function () {
    $teacher = marketplaceTeacher($this->workspace);

    Sanctum::actingAs($teacher->user);

    $this->getJson('/api/v1/teacher/profile')
        ->assertOk()
        ->assertJsonPath('slug', $teacher->slug)
        ->assertJsonPath('is_publicly_listed', true);
});

it('reports a null slug for an account with no profile', function () {
    // The card renders an explanation instead of a field, so this has to be a
    // 200 with a null — not a 403 the client would have to guess the meaning of.
    $student = User::factory()->create(['platform_role' => 'student']);

    Sanctum::actingAs($student);
    $this->asGuest();

    $this->getJson('/api/v1/teacher/profile')
        ->assertOk()
        ->assertJsonPath('slug', null);
});
