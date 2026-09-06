<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\StudentProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ TWO COLUMNS WITH FOUR READERS EACH AND NO WRITER ANYWHERE.
|
| `teacher_profiles.photo_path` and `student_profiles.avatar_path` are read by
| the attendance roster, the cohort roster, the home page and the course card —
| and nothing in the tree ever wrote either. So the initial-in-a-circle every
| avatar falls back to was not a fallback: it was the only state the product
| could reach. Reported by the user on 2026-09-06 as «no place to change the
| account photo»; the measurement is what showed there was no place to SET one.
*/
beforeEach(function (): void {
    Storage::fake('public');

    $this->student = User::factory()->create(['last_workspace_id' => null]);
    StudentProfile::query()->create(['user_id' => $this->student->getKey()]);
});

it('stores a student avatar and answers with its public url', function (): void {
    Sanctum::actingAs($this->student);

    $url = $this->postJson('/api/v1/me/photo', [
        'photo' => UploadedFile::fake()->image('me.jpg'),
    ])->assertOk()->json('photo_url');

    $path = $this->student->studentProfile->refresh()->avatar_path;

    expect($path)->not->toBeNull()
        ->and($url)->toContain($path)
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('DELETES the previous file when a new one replaces it', function (): void {
    /*
    | The user asked for this in as many words. Deliberately UNLIKE a payment
    | receipt, which is kept: a receipt is the record of what an officer approved
    | or refused, and something was decided on it. Nothing is ever decided on an
    | avatar — so an orphan there is a face nobody can reach, kept for ever, after
    | its owner replaced it precisely to stop showing it.
    */
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('first.jpg')])->assertOk();

    $first = $this->student->studentProfile->refresh()->avatar_path;

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('second.png')])->assertOk();

    $second = $this->student->studentProfile->refresh()->avatar_path;

    // ⚠️ THE NAMES MUST DIFFER OR THE WHOLE QUESTION IS UNASKABLE. A path derived
    // from the uuid alone is the same path twice: the file is overwritten, this
    // assertion passes vacuously, and every browser and CDN keeps serving the
    // old picture after a change that looks like it did nothing.
    expect($second)->not->toBe($first)
        ->and(Storage::disk('public')->exists($second))->toBeTrue()
        ->and(Storage::disk('public')->exists($first))->toBeFalse();
});

it('removes the photo and its file together', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])->assertOk();

    $path = $this->student->studentProfile->refresh()->avatar_path;

    $this->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('photo_url', null);

    expect($this->student->studentProfile->refresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('writes the TEACHER column for a teacher, not the student one', function (): void {
    // One door for both roles, and the column it writes follows the profile. Two
    // doors would mean two screens and then one limit drifting from the other.
    $workspace = marketplaceWorkspace('Academy');
    $teacher = marketplaceTeacher($workspace);

    Sanctum::actingAs($teacher->user);

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])->assertOk();

    expect($teacher->fresh()?->photo_path)->not->toBeNull();
});

it('refuses an account with neither profile, and a file that is not an image', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/me/photo', [
        'photo' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('photo');

    // A guardian or a member of staff has neither profile: a sentence, not a
    // silent 500 from a null property access.
    Sanctum::actingAs(User::factory()->create(['last_workspace_id' => null]));

    $this->postJson('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يوجد ملف شخصي لهذا الحساب يحمل صورة.');
});

/*
| ⚠️ الحدُّ على الخادمِ لا في المتصفّح — طلبُ ٢٠٢٦-٠٩-٠٦: «تقليل حجمها وقصّها
| ووضعُ الوجهِ في الإطارِ الدائريّ».
|
| المقصُّ في الواجهةِ هو التجربة، وهو على الجانبِ الذي لا يُوثَقُ به: مَن يتخطّى
| الشاشةَ يرسلُ ما شاء. فالاختباراتُ التاليةُ تقيسُ ما **يُخزَّنُ** لا ما يُرسَل.
*/
it('re-encodes every upload to one square size, whatever arrives', function (): void {
    Sanctum::actingAs($this->student);

    // ٢٠٠٠×١٠٠٠ — مستطيلٌ عريض. بلا إعادةِ الترميزِ يُخزَّنُ كما هو، فتقصُّه
    // `object-cover` في المتصفّحِ من المنتصفِ لا من حيثُ وضعَ صاحبُه وجهَه.
    $this->postJson('/api/v1/me/photo', [
        'photo' => UploadedFile::fake()->image('wide.jpg', 2000, 1000),
    ])->assertOk();

    $path = $this->student->studentProfile->refresh()->avatar_path;

    [$width, $height] = getimagesize(Storage::disk('public')->path($path));

    expect([$width, $height])->toBe([512, 512]);
});

it('stores JPEG under a .jpg name however the upload was named', function (): void {
    // اسمٌ ينتهي بـ`.png` فوقَ بايتاتِ JPEG هو النوعُ الذي يخمّنُه كلُّ خادمٍ
    // ثابتٍ من الاسم — والقرصُ العامُّ يُخدَمُ مباشرةً من `/storage`.
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/me/photo', [
        'photo' => UploadedFile::fake()->image('me.png', 300, 300),
    ])->assertOk();

    $path = $this->student->studentProfile->refresh()->avatar_path;

    expect($path)->toEndWith('.jpg')
        ->and(getimagesize(Storage::disk('public')->path($path))[2])->toBe(IMAGETYPE_JPEG);
});
