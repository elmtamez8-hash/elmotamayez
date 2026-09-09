<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Actions\RequestApplicationChanges;
use App\Modules\Marketplace\Actions\UpdateTeacherProfile;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherApplication;
use Laravel\Sanctum\Sanctum;

/*
| البابانِ كانا يختلفانِ على سؤالٍ واحد: هل يُعدَّلُ أثناءَ المراجعة؟
|
| المعالجُ يقولُ لا، وشاشتا «ملفّي» و«مواعيدي» كانتا تقولانِ نعم بلا حارس. ومن
| ذلكَ الخلافِ يُولَدُ الضياع: تعديلٌ يقعُ والطلبُ «مُرسَل» لا تنسخُه المزامنةُ،
| فإن طلبَ المراجِعُ تعديلاً بعدَها كتبَ الإرسالُ التالي الملفَّ من نسخةٍ لا
| تعرفُه. أي أنّ التجميدَ كانَ يؤجّلُ الضياعَ جولةً لا يمنعُه.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->workspace);

    $this->application = TeacherApplication::factory()->complete()->create([
        'user_id' => $this->teacher->user_id,
        'workspace_id' => $this->workspace->id,
        'teacher_profile_id' => $this->teacher->id,
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'submitted_at' => now(),
    ]);
});

/** @return array<string, mixed> */
function listingPayload(): array
{
    return [
        'headline' => 'عنوانٌ جديد',
        'bio' => null,
        'years_experience' => 9,
        'qualifications' => ['بكالوريوس'],
        'teaching_languages' => ['ar'],
        'subjects' => ['math'],
        'grade_levels' => ['secondary'],
        'faqs' => [],
    ];
}

it('refuses the applicant their own listing while the reviewer is looking at it, and writes nothing', function (): void {
    $before = $this->teacher->headline;

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', listingPayload())->assertStatus(422);

    expect($this->teacher->refresh()->headline)->toBe($before);
});

it('refuses the applicant their own week while the reviewer is looking at it, and writes nothing', function (): void {
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00']],
    ])->assertStatus(422);

    expect(AvailabilitySlot::query()->where('teacher_profile_id', $this->teacher->id)->count())->toBe(0);
});

it('does NOT refuse once the reviewer has handed the application back', function (): void {
    // ⚠️ الحالةُ التي تمنعُ الحارسَ من أن يبتلعَ الحلقةَ التي وُجِدَ لأجلِها:
    // «مطلوب تعديل» هو الوقتُ الذي يُصحّحُ فيه المدرّسُ، والمزامنةُ تحملُ تصحيحَه.
    $this->application->forceFill(['status' => TeacherApplication::STATUS_CHANGES_REQUESTED])->save();

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', listingPayload())->assertOk();

    expect($this->teacher->refresh()->headline)->toBe('عنوانٌ جديد')
        ->and($this->application->refresh()->step(2)['headline'])->toBe('عنوانٌ جديد');
});

it('does NOT refuse an approved teacher — the door exists for them', function (): void {
    $this->application->forceFill(['status' => TeacherApplication::STATUS_APPROVED])->save();

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', listingPayload())->assertOk();

    expect($this->teacher->refresh()->headline)->toBe('عنوانٌ جديد');
});

it('leaves the REVIEWER free to correct a submitted listing from the panel', function (): void {
    /*
    | ⚠️ ولهذا الحارسُ بابٌ لا فعل: {@see UpdateTeacherProfile} يخدمُ فاعلَين،
    | والمنعُ منعُ صاحبِ الطلبِ من تحريكِ ما يُنظَرُ فيه — لا منعُ المراجِعِ من
    | التصحيح، وهو الغرضُ من شاشتِه.
    */
    app(UpdateTeacherProfile::class)->handle($this->teacher, ['headline' => 'صحّحَه المراجِع']);

    expect($this->teacher->refresh()->headline)->toBe('صحّحَه المراجِع')
        // والطلبُ لا يتحرّك: «مُرسَل» ليس قابلاً للتعديل، فالمراجِعُ يقرأُ ما قرّرَ عليه.
        ->and($this->application->refresh()->step(2)['headline'])->toBe('مدرّس رياضيات');
});

it('refuses to re-open an application that was already decided', function (): void {
    /*
    | ⚠️ اللوحةُ تُخفي زرَّها لغيرِ المعلَّق، فيبدو الأمرُ محروساً — ومسارُ الـAPI
    | كانَ بلا شرطِ حالةٍ إطلاقاً. وهذا هو البابُ الوحيدُ الذي يُعيدُ طلباً قابلاً
    | للتعديل: طلبٌ معتمَدٌ يُعادُ فتحُه ثمّ يُرسَلُ يكتبُ الملفَّ من لقطةِ يومِ
    | التسجيل.
    */
    $this->application->forceFill(['status' => TeacherApplication::STATUS_APPROVED])->save();

    $reviewer = User::factory()->create();

    expect(fn () => app(RequestApplicationChanges::class)->handle($this->application, $reviewer, 'سبب'))
        ->toThrow(DomainException::class);

    expect($this->application->refresh()->status)->toBe(TeacherApplication::STATUS_APPROVED);
});

it('still re-opens one the reviewer is actually holding', function (): void {
    $reviewer = User::factory()->create();

    app(RequestApplicationChanges::class)->handle($this->application, $reviewer, 'سيرتُك ناقصة');

    expect($this->application->refresh()->status)->toBe(TeacherApplication::STATUS_CHANGES_REQUESTED);
});
