<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ EVERYTHING STEP TWO WRITES WAS WRITABLE ONCE AND NEVER AGAIN — reported by
| the user on 2026-09-06: «I cannot find anywhere the teacher edits their
| subject, qualifications, stage, description or languages.»
|
| The fields existed, the Action that writes them existed (`UpdateTeacherProfile`,
| with the white-list keeping `approval_status` out of a raw update), and its ONLY
| caller was the Filament panel. So a teacher who took on a new stage or wanted to
| fix a typo in their own description had to ask the platform to edit the row for
| them.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->workspace);

    // ⚠️ `firstOrCreate`: the taxonomy is REFERENCE DATA seeded before every
    // Feature test, so a bare `create()` collides on `subjects.slug` — and a
    // fixture that invents its own vocabulary would be testing a catalogue
    // production does not have.
    Subject::query()->firstOrCreate(['slug' => 'physics'], ['name_ar' => 'الفيزياء', 'is_active' => true]);
    GradeLevel::query()->firstOrCreate(['slug' => 'secondary'], ['name_ar' => 'الثانوية', 'is_active' => true]);
});

function editProfile(array $overrides = []): array
{
    return [
        'subjects' => ['physics'],
        'grade_levels' => ['secondary'],
        'years_experience' => 7,
        'qualifications' => ['ماجستير فيزياء'],
        'teaching_languages' => ['ar'],
        'headline' => 'مدرّس فيزياء للثانوية',
        'bio' => 'أشرح المفاهيم من الصفر.',
        ...$overrides,
    ];
}

it('lets an approved teacher rewrite their own listing, and publishes it at once', function (): void {
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile())
        ->assertOk()
        ->assertJsonPath('headline', 'مدرّس فيزياء للثانوية')
        ->assertJsonPath('subjects.0', 'physics')
        ->assertJsonPath('grade_levels.0', 'secondary');

    $fresh = $this->teacher->fresh();

    expect($fresh?->bio)->toBe('أشرح المفاهيم من الصفر.')
        ->and($fresh?->years_experience)->toBe(7)
        ->and($fresh?->qualifications)->toBe(['ماجستير فيزياء'])
        // ⚠️ APPROVAL DID NOT MOVE. `is_publicly_listed` is DERIVED from approval
        // and workspace participation, so an edit that reopened review would take
        // a working teacher off the marketplace as a side effect of a typo fix.
        ->and($fresh?->approval_status)->toBe('approved')
        ->and((bool) $fresh?->is_publicly_listed)->toBeTrue();
});

it('refuses to let the listing be emptied', function (): void {
    // A profile with no subject matches no filter the marketplace can offer: the
    // teacher is listed and unreachable, with nothing failing anywhere.
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile(['subjects' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('subjects');
});

it('refuses a subject that is not in the catalogue', function (): void {
    // ⚠️ `Rule::in`, never `string|max:100`. Free text here is a teacher listed
    // under a subject nobody can search for.
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile(['subjects' => ['astrology']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('subjects.0');
});

it('refuses an account with no listing, with a sentence', function (): void {
    // A student, or a teacher still in review. 403 rather than 404: the account
    // exists and is signed in, it simply has no listing to edit.
    Sanctum::actingAs(User::factory()->create(['last_workspace_id' => null]));

    $this->putJson('/api/v1/teacher/profile', editProfile())
        ->assertStatus(403)
        ->assertJsonPath('message', 'لا يوجد ملف مدرّس لهذا الحساب.');
});

it('cannot promote itself past the review team', function (): void {
    /*
    | ⚠️ `approval_status` AND `is_publicly_listed` ARE BOTH `$fillable`, so a
    | door that passed the request array raw into `update()` would write them in
    | silence — skipping the derivation, the participation stamp and the
    | notification, and looking perfectly correct in the table. The white-list in
    | `UpdateTeacherProfile` is what stops it, and this asks the question through
    | the HTTP door where a future maintainer would open the hole.
    */
    $pending = marketplaceTeacher($this->workspace);
    $pending->forceFill(['approval_status' => 'pending', 'is_publicly_listed' => false])->save();

    Sanctum::actingAs($pending->user);

    $this->putJson('/api/v1/teacher/profile', editProfile([
        'approval_status' => 'approved',
        'is_publicly_listed' => true,
        'is_verified' => true,
        'hourly_rate' => 1,
    ]))->assertOk();

    $fresh = $pending->fresh();

    expect($fresh?->approval_status)->toBe('pending')
        ->and((bool) $fresh?->is_publicly_listed)->toBeFalse()
        ->and((bool) $fresh?->is_verified)->toBeFalse();
});

/*
| ⚠️ `faqs` WAS `[]` WRITTEN LITERALLY INTO `PublicTeacherDetailResource`, with
| the key in the public allow-list since 002 and `FaqAccordion` rendered on the
| page behind `length > 0`. So the accordion could never appear, nothing failed,
| and every existing assertion about the payload passed — the key was present and
| correct, and empty for ever. A column with a reader and no writer, inverted.
|
| The two assertions below are one requirement each and must stay separate: that
| the teacher can WRITE them, and that a VISITOR can read them. The write alone
| passes against a resource still returning a literal empty array.
*/
it('publishes the FAQs and intro video a teacher writes on their own listing', function (): void {
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile([
        'faqs' => [
            ['question' => 'كم مدة الحصة؟', 'answer' => 'ستون دقيقة.'],
            ['question' => 'هل توجد حصة تجريبية؟', 'answer' => 'نعم، الأولى مجانية.'],
        ],
        'intro_video_url' => 'https://www.youtube.com/watch?v=abc12345678',
    ]))
        ->assertOk()
        /*
        | ⚠️ **ردُّ الحفظِ هو `show()` — وهذه هي التوكيدةُ الوحيدةُ التي تراهُ.**
        | التحقّقُ من العمودِ وحدَه أخضرُ فوقَ خادمٍ لا يُرسِلُ المفتاحَ أصلاً، ونموذجُ
        | التحريرِ يملأُ نفسَه من هذا الردِّ لا من الجدول: `undefined` هناك يعني
        | `form.faqs.length` ينفجرُ في وجهِ المدرّسِ أوّلَ ما يفتحُ «ملفّي».
        */
        ->assertJsonPath('faqs.0.question', 'كم مدة الحصة؟')
        ->assertJsonPath('intro_video_url', 'https://www.youtube.com/watch?v=abc12345678');

    expect($this->teacher->fresh()?->faqs)->toBe([
        ['question' => 'كم مدة الحصة؟', 'answer' => 'ستون دقيقة.'],
        ['question' => 'هل توجد حصة تجريبية؟', 'answer' => 'نعم، الأولى مجانية.'],
    ]);

    // The visitor's half — as a GUEST, which is who the accordion is for.
    $uuid = $this->teacher->fresh()?->uuid;

    $this->asGuest();

    $this->getJson("/api/v1/marketplace/teachers/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.faqs.0.question', 'كم مدة الحصة؟')
        ->assertJsonPath('data.faqs.1.answer', 'نعم، الأولى مجانية.')
        ->assertJsonPath('data.intro_video_url', 'https://www.youtube.com/watch?v=abc12345678');
});

it('stores only question and answer, whatever else a client sends', function (): void {
    // The column is JSON, so an extra key would be stored verbatim and then
    // published — and `PublicFieldAllowlist::FAQ` knows exactly two keys.
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile([
        'faqs' => [[
            'question' => 'سؤال',
            'answer' => 'جواب',
            'is_pinned' => true,
            'internal_note' => 'لا ينبغي أن يصل هذا إلى أحد',
        ]],
    ]))->assertOk();

    expect($this->teacher->fresh()?->faqs)->toBe([['question' => 'سؤال', 'answer' => 'جواب']]);
});

it('refuses an intro video from any host but YouTube or Vimeo', function (): void {
    // The url lands in an `<iframe>` on a public page. The client's own parser is
    // the real guard; this door says no earlier, and in Arabic.
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile([
        'intro_video_url' => 'https://evil.example.com/embed/x',
    ]))->assertJsonValidationErrors('intro_video_url');

    expect($this->teacher->fresh()?->intro_video_url)->toBeNull();
});

it('refuses a FAQ row with a question and no answer', function (): void {
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', editProfile([
        'faqs' => [['question' => 'سؤال بلا إجابة', 'answer' => '']],
    ]))->assertJsonValidationErrors('faqs.0.answer');
});
