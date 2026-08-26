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

it('reports a null slug to a teacher whose application is still in review', function () {
    /*
     | ⚠️ THIS IS THE CASE THE NULL WAS WRITTEN FOR — and the only one.
     | `SubmitTeacherApplication` creates the row at submit with `pending` and no
     | slug, so a teacher waiting on review HAS a profile with nothing to rename,
     | and the card answers «لم يُنشر ملفك بعد» rather than offering a field that
     | saves into nothing.
     */
    /*
     | ⚠️ NULLED AT THE DATABASE, because the model will not hold it. A `saving`
     | hook backfills any null slug from `search_name`, which it syncs in the
     | same closure — so `->save()` would put a slug straight back and the test
     | would be asserting the opposite of its own name. It is also why this state
     | is rare in production rather than the ordinary pending case the card's
     | text implies; the branch is kept because a profile whose `search_name` is
     | null still reaches it, and a card offering a field that saves into nothing
     | is worse than a sentence.
     */
    $teacher = marketplaceTeacher($this->workspace);
    DB::table('teacher_profiles')->where('id', $teacher->getKey())
        ->update(['slug' => null, 'is_publicly_listed' => false]);

    Sanctum::actingAs($teacher->user);

    $this->getJson('/api/v1/teacher/profile')
        ->assertOk()
        ->assertJsonPath('slug', null)
        ->assertJsonPath('is_publicly_listed', false);
});

it('refuses an account with no teacher profile at all', function () {
    /*
     | ⚠️ AND THIS ONE ASSERTED THE 200 UNTIL 2026-08-27, WITH A COMMENT
     | EXPLAINING WHY — the comment was about the case above, and the fixture was
     | this one. Two callers reach that null: a pending teacher, who has a row,
     | and a student, who has none. Answering both with one body printed
     | «رابط ملفك العام — هذا هو العنوان الذي يصل منه الطلاب وأولياء الأمور إلى
     | صفحتك» on a STUDENT's settings page, and `PublicProfileUrlCard` says in
     | its own docblock that it renders nothing here — its `.catch` was waiting
     | for a 403 that was never sent. Reported from a real signed-in student.
     */
    $student = User::factory()->create(['platform_role' => 'student']);

    Sanctum::actingAs($student);
    $this->asGuest();

    $this->getJson('/api/v1/teacher/profile')->assertForbidden();
});
