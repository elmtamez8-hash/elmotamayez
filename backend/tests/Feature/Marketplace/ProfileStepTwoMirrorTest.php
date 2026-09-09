<?php

declare(strict_types=1);

use App\Modules\Marketplace\Actions\UpdateTeacherProfile;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use Laravel\Sanctum\Sanctum;

/*
| توأمُ عطبِ المواعيد، على سبعةِ حقولٍ بدلَ حقلٍ واحد.
|
| حقولُ {@see UpdateTeacherProfile} هي حقولُ الخطوةِ الثانيةِ بعينِها — تقولُه
| وثيقتُها — و{@see SubmitTeacherApplication} يُعيدُ كتابةَ الملفِّ منها عندَ كلِّ
| إرسال. فالمدرّسُ في «مطلوب تعديل» الذي صحّحَ سيرتَه من صفحةِ ملفِّه — وهو
| المكانُ الطبيعيُّ لتصحيحِ سيرة — كانَ تصحيحُه يعودُ إلى ما قبلَه عندَ الإرسال،
| والمراجِعُ يُفتَحُ له النصُّ الذي طلبَ تعديلَه بعينِه.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->workspace);

    $this->application = TeacherApplication::factory()->complete()->create([
        'user_id' => $this->teacher->user_id,
        'workspace_id' => $this->workspace->id,
        'teacher_profile_id' => $this->teacher->id,
        'status' => TeacherApplication::STATUS_CHANGES_REQUESTED,
    ]);
});

/** @return array<string, mixed> */
function correctedListing(): array
{
    return [
        'headline' => 'مدرّس رياضيات وفيزياء',
        'bio' => 'سيرةٌ صحّحتُها بعدَ ملاحظةِ المراجِع.',
        'years_experience' => 12,
        'qualifications' => ['ماجستير رياضيات تطبيقيّة'],
        'teaching_languages' => ['ar', 'en'],
        'subjects' => ['math', 'physics'],
        'grade_levels' => ['secondary'],
        'faqs' => [],
    ];
}

it('does not revert the listing the teacher just corrected when the application is re-submitted', function (): void {
    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', correctedListing())->assertOk();

    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    $profile = $this->teacher->refresh();

    expect($profile->headline)->toBe('مدرّس رياضيات وفيزياء')
        ->and($profile->bio)->toBe('سيرةٌ صحّحتُها بعدَ ملاحظةِ المراجِع.')
        ->and($profile->years_experience)->toBe(12)
        ->and($profile->qualifications)->toBe(['ماجستير رياضيات تطبيقيّة'])
        ->and($profile->teaching_languages)->toBe(['ar', 'en'])
        // ⚠️ والمادّةُ الثانيةُ هي الشاهد: `complete()` يبدأُ بـ`math` وحدَها،
        // فرجوعُها إليها هو العطبُ عينُه.
        ->and($profile->subjects()->pluck('slug')->sort()->values()->all())->toBe(['math', 'physics']);

    // والنسخةُ التي يقرؤُها المراجِعُ صارتْ هي نفسَها — بالأسماءِ لا بالمعرِّفات.
    $step = $this->application->refresh()->step(2);

    expect($step['headline'])->toBe('مدرّس رياضيات وفيزياء')
        ->and($step['subjects'])->toBe(['math', 'physics'])
        ->and($step['grade_levels'])->toBe(['secondary'])
        ->and($step['years_experience'])->toBe(12);
});

it('leaves a decided application exactly as the reviewer left it', function (): void {
    /*
    | ⚠️ الحالةُ السالبةُ التي تمنعُ الإصلاحَ من أن يصيرَ إعادةَ كتابةٍ لتاريخِ
    | مراجعةٍ انتهت — وهي حالةُ اللوحةِ الغالبة: تصحيحُ ملفِّ مدرّسٍ معتمَد.
    */
    $this->application->update(['status' => TeacherApplication::STATUS_APPROVED]);
    $before = $this->application->refresh()->step_data;

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/profile', correctedListing())->assertOk();

    expect($this->teacher->refresh()->headline)->toBe('مدرّس رياضيات وفيزياء')
        ->and($this->application->refresh()->step_data)->toBe($before);
});

it('covers the panel too, because the guard is in the Action and not at the door', function (): void {
    /*
    | ⚠️ بابانِ يستدعيانِ هذا الفعل: `PUT /teacher/profile`، ولوحةُ
    | `EditTeacherProfile` التي يُصحّحُ منها فريقُ المراجعة. فحارسٌ في المتحكّمِ
    | يترك الثانيَ مفتوحاً — والاستدعاءُ المباشرُ هنا هو ما يُثبتُ موضعَه.
    */
    app(UpdateTeacherProfile::class)->handle(
        $this->teacher,
        ['headline' => 'صحّحَه فريقُ المراجعة', 'years_experience' => 15],
        Subject::query()->whereIn('slug', ['physics'])->pluck('id')->all(),
        GradeLevel::query()->whereIn('slug', ['secondary'])->pluck('id')->all(),
    );

    $step = $this->application->refresh()->step(2);

    expect($step['headline'])->toBe('صحّحَه فريقُ المراجعة')
        ->and($step['years_experience'])->toBe(15)
        ->and($step['subjects'])->toBe(['physics'])
        // وما لم يُرسَلْ يبقى على قيمتِه: القراءةُ من الملفِّ المحفوظِ لا من الوارد.
        ->and($step['bio'])->toBe($this->teacher->refresh()->bio);
});
