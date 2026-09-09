<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherApplication;
use Laravel\Sanctum\Sanctum;

/*
| أسبوعُ المدرّسِ كانَ مكتوباً في مكانَين.
|
| `availability_slots` هي ما يقرؤُه المنتَج — مولّدُ الحصص، وحارسُ الحصّةِ
| الخاصّة، والصفحةُ العامّة. و`step_data['step_4']['availability']` نسخةٌ ثانيةٌ
| منها يقرؤُها المعالجُ عندَ العودةِ والمراجِعُ عندَ القرار. وبابانِ يكتبان:
| الإرسالُ يكتبُ الأولى من الثانية، وشاشةُ «مواعيدي» تكتبُ الأولى وحدَها.
|
| فالمدرّسُ في «مطلوب تعديل» الذي صحّحَ أسبوعَه من الشاشةِ ثمّ أعادَ الإرسال،
| كانَ تصحيحُه يُدهَسُ بالقيمِ الأقدم. ثلاثةُ ضحايا لنسخةٍ واحدة، وهذا الملفُّ
| يقيسُ الثلاثة.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->workspace);
});

function openApplication(string $status = TeacherApplication::STATUS_CHANGES_REQUESTED): TeacherApplication
{
    return TeacherApplication::factory()->complete()->create([
        'user_id' => test()->teacher->user_id,
        'workspace_id' => test()->workspace->id,
        'teacher_profile_id' => test()->teacher->id,
        'status' => $status,
    ]);
}

/** @return array<int, array<string, mixed>> */
function tuesdayNine(): array
{
    return [['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00']];
}

it('does not revert the week the teacher just set when the application is re-submitted', function (): void {
    /*
    | ⚠️ الحالةُ الحاملة، ومن طرفٍ إلى طرف. الأسبوعُ في المصنعِ يومُ الأحد
    | ١٦:٠٠–١٨:٠٠؛ المدرّسُ يُصحّحُه إلى الثلاثاءِ ٠٩:٠٠ ثمّ يُعيدُ الإرسال.
    | قبلَ الإصلاحِ كانَ يعودُ إلى الأحدِ بلا كلمةٍ واحدة.
    */
    $application = openApplication();

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', ['availability' => tuesdayNine()])
        ->assertOk();

    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    $slots = AvailabilitySlot::query()->where('teacher_profile_id', $this->teacher->id)->get();

    expect($slots)->toHaveCount(1)
        ->and((int) $slots->first()?->day_of_week)->toBe(2)
        ->and($slots->first()?->start_time)->toBe('09:00:00');

    $step = $application->refresh()->step(4);

    expect($step['availability'])->toBe([
        ['day_of_week' => 2, 'start_time' => '09:00:00', 'end_time' => '11:00:00'],
    ])
        // وبقيّةُ الخطوةِ لا تُمَسّ: السعرُ بابُه طلبُ تعديلِ سعرٍ في ٠١٤، لا هذه الشاشة.
        ->and($step['hourly_rate'])->toBe('120.00')
        ->and($step['currency'])->toBe('QAR')
        // ولا يتحرّكُ موضعُ المعالجِ الذي وصلَ إليه صاحبُه.
        ->and($application->current_step)->toBe(TeacherApplication::LAST_STEP);
});

it('leaves a decided application exactly as the reviewer left it', function (): void {
    /*
    | ⚠️ الحالةُ السالبةُ هي التي تمنعُ الإصلاحَ من أن يصيرَ عطباً أكبرَ ممّا
    | أصلح: طلبٌ اعتُمِدَ هو سجلُّ ما قرّرَ عليه المراجِع، وتحريكُه من شاشةِ
    | المدرّسِ إعادةُ كتابةٍ لتاريخِ مراجعةٍ انتهت.
    */
    $application = openApplication(TeacherApplication::STATUS_APPROVED);
    $before = $application->step_data;

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', ['availability' => tuesdayNine()])
        ->assertOk();

    // الصفوفُ تتحرّك — الشاشةُ بابٌ مشروعٌ بعدَ الاعتماد — والطلبُ لا.
    expect(AvailabilitySlot::query()->where('teacher_profile_id', $this->teacher->id)->count())->toBe(1)
        ->and($application->refresh()->step_data)->toBe($before);
});

it('normalises at the COLUMN, so a writer that never passes through the Action is covered too', function (): void {
    /*
    | ⚠️ أربعةُ كتّابٍ لهذا الجدول: الفعلُ، وبذرتانِ ومصنع. والبذرةُ
    | `DemoDataSeeder` تكتبُ `16:00` حرفيّاً — وصفوفُها موجودةٌ أصلاً لتكونَ ما
    | يقعُ طلبُ الحصّةِ الخاصّةِ داخلَه، وهي المقارنةُ التي تنكسرُ عندَ الحافّة
    | بالضبط. فالحارسُ على العمودِ لا عندَ كلِّ كاتب.
    */
    $slot = AvailabilitySlot::query()->create([
        'workspace_id' => $this->workspace->id,
        'teacher_profile_id' => $this->teacher->id,
        'day_of_week' => 5,
        'start_time' => '16:00',
        'end_time' => '19:00',
    ]);

    expect($slot->refresh()->start_time)->toBe('16:00:00')
        ->and($slot->end_time)->toBe('19:00:00');

    /*
    | ⚠️ والمقارنةُ عينُها التي يُجريها {@see RequestPrivateSession}: الطرفُ
    | الأيمنُ `H:i:s` دائماً. فصفٌّ مكتوبٌ `19:00` أقصرُ نصّاً من `19:00:00`
    | ومن ثمّ أصغر — فحصّةٌ تنتهي عندَ الحافّةِ بالضبطِ تُرفَضُ عندَه وتُقبَلُ
    | عندَ جارِه. هذا هو السطرُ الذي يسقطُ بلا الحارس.
    */
    expect(
        AvailabilitySlot::query()
            ->whereKey($slot->getKey())
            ->where('end_time', '>=', '19:00:00')
            ->exists(),
    )->toBeTrue();
});

it('stores one time whichever door wrote it', function (): void {
    /*
    | ⚠️ القاعدةُ تقبلُ `H:i` و`H:i:s`، والمعالجُ كانَ يُسوّي وحدَه. فالساعةُ
    | نفسُها كانتْ تُخزَّنُ بشكلَينِ حسبَ البابِ الذي جاءتْ منه — يُخفيه MySQL
    | بتسويةِ عمودِ `time`، ويكشفُه SQLite وحدَه، وهو ما تعملُ عليه هذه الحزمة.
    */
    openApplication();

    Sanctum::actingAs($this->teacher->user);

    $this->putJson('/api/v1/teacher/availability', [
        'availability' => [['day_of_week' => 4, 'start_time' => '07:30', 'end_time' => '09:00']],
    ])->assertOk();

    $slot = AvailabilitySlot::query()->where('teacher_profile_id', $this->teacher->id)->first();

    expect($slot?->start_time)->toBe('07:30:00')
        ->and($slot?->end_time)->toBe('09:00:00');
});
