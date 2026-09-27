<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Shared\Actions\RecordAccountTimezone;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **الاسمُ القديمُ للمنطقةِ مقبول، ويُخزَّنُ باسمِها الجديد** (قرارُ المالك
| ٢٠٢٦-٠٩-٢٧).
|
| Chrome وNode ما زالا يقولان `Asia/Calcutta` و`Europe/Kiev` (١٩ اسماً)، وقاعدةُ
| `timezone` في Laravel لا تعرفُ إلّا `Asia/Kolkata` و`Europe/Kyiv` — فقارئٌ في
| الهند اختارَ «الهند — كولكاتا» فرُفِض، وختمُ الدخولِ من متصفّحه رُفِضَ بصمت.
| الآن كلُّ بابٍ يكتبُ منطقةً يقبلُ الاسمَ القديمَ ويخزّنُ الجديد.
|
| ⚠️ ولا يمسُّ هذا قاعدةَ «اختيارُ الشخصِ يغلبُ المتصفّح»: الختمُ بالاسمِ القديمِ
| على اختيارٍ يدويٍّ مرفوضٌ كما كان.
*/

describe('PUT /me/timezone', function (): void {
    it('accepts an old spelling as a choice and stores the new one', function (): void {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Asia/Calcutta', 'source' => 'manual'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Kolkata')
            ->assertJsonPath('timezone_source', 'manual');
    });

    it('accepts an old spelling from the sign-in stamp and stores the new one', function (): void {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Europe/Kiev', 'source' => 'browser'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Europe/Kyiv')
            ->assertJsonPath('timezone_source', 'browser');
    });

    it('still never lets an old-spelling stamp overwrite a zone the person chose', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Africa/Cairo', 'source' => 'manual'])->assertOk();
        $this->putJson('/api/v1/me/timezone', ['timezone' => 'Asia/Calcutta', 'source' => 'browser'])
            ->assertOk()
            ->assertJsonPath('timezone', 'Africa/Cairo')
            ->assertJsonPath('timezone_source', 'manual');
    });
});

it('stores the new spelling when the quiet-hours form reports an old one', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/notifications/quiet-hours', [
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'America/Buenos_Aires',
    ])->assertOk();

    expect($user->fresh()?->timezone)->toBe('America/Argentina/Buenos_Aires');
});

it('stores the new spelling on a teacher\'s week sent with an old one', function (): void {
    $workspace = marketplaceWorkspace('Academy');
    $teacher = marketplaceTeacher($workspace);
    Sanctum::actingAs($teacher->user);

    $this->putJson('/api/v1/teacher/availability', [
        'timezone' => 'Asia/Saigon',
        'availability' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00'],
        ],
    ])->assertOk();

    expect(AvailabilitySlot::query()->where('teacher_profile_id', $teacher->id)->pluck('timezone')->all())
        ->toBe(['Asia/Ho_Chi_Minh']);
});

it('folds the old spelling in the Action too, for callers that skip the request', function (): void {
    $user = app(RecordAccountTimezone::class)->handle(User::factory()->create(), 'Asia/Rangoon');

    expect($user->timezone)->toBe('Asia/Yangon');
});
