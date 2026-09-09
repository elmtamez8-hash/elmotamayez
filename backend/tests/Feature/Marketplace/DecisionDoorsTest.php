<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Actions\ApproveTeacherApplication;
use App\Modules\Marketplace\Actions\RejectTeacherApplication;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;

/*
| قرارُ المراجعةِ يُتَّخَذُ مرّةً واحدة.
|
| اللوحةُ تُخفي أزرارَها لغيرِ المعلَّق — **فيبدو الأمرُ محروساً** — بينما مسارات
| الـAPI الثلاثةُ بلا شرطِ حالةٍ إطلاقاً. أُغلِقَ «إعادةُ الفتح» في #26، وهذا
| يُغلقُ «اعتماد» و«رفض».
|
| ⚠️ والحارسانِ غيرُ متماثلَين، وذلك أصلُ هذا الملفّ: «اعتماد» يمرُّ به مسارُ
| إعادةِ تفعيلٍ حقيقيٌّ من طلبٍ مرفوض، و«رفض» لا يمرُّ به شيءٌ سوى ما هو معلَّق.
*/
beforeEach(function (): void {
    $this->workspace = marketplaceWorkspace('Academy');
    $this->teacher = marketplaceTeacher($this->workspace);
    $this->reviewer = User::factory()->create();

    $this->application = TeacherApplication::factory()->complete()->create([
        'user_id' => $this->teacher->user_id,
        'workspace_id' => $this->workspace->id,
        'teacher_profile_id' => $this->teacher->id,
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'submitted_at' => now(),
    ]);
});

it('refuses to approve an application that is already approved', function (): void {
    // مستمعُ `TeacherApproved` يُرسلُ إشعاراً، فقرارٌ ثانٍ رسالةٌ ثانيةٌ لمن قرأَ
    // الأولى — وختمُ مراجِعٍ وتاريخٍ فوقَ قرارٍ سابق.
    $this->application->forceFill([
        'status' => TeacherApplication::STATUS_APPROVED,
        'reviewed_by' => $this->reviewer->getKey(),
        'reviewed_at' => now()->subDay(),
    ])->save();

    $stamped = $this->application->reviewed_at;

    expect(fn () => app(ApproveTeacherApplication::class)->handle($this->application, $this->reviewer))
        ->toThrow(DomainException::class);

    expect($this->application->refresh()->reviewed_at?->toDateTimeString())
        ->toBe($stamped?->toDateTimeString());
});

it('STILL approves one that was rejected — that is the reinstatement path, not a hole', function (): void {
    /*
    | ⚠️ الحالةُ التي تمنعُ الحارسَ من قتلِ قدرةٍ قائمة: زرُّ الاعتمادِ في شاشةِ
    | الملفِّ يختارُ أيَّ طلبٍ حالتُه ليستْ معتمَدة — بما فيها المرفوضة. وحارسٌ
    | يشترطُ التعليقَ كانَ سيُغلقُ «غيّرنا رأيَنا» بلا أن يقولَ أحدٌ شيئاً.
    */
    $this->application->forceFill(['status' => TeacherApplication::STATUS_REJECTED])->save();

    app(ApproveTeacherApplication::class)->handle($this->application, $this->reviewer);

    expect($this->application->refresh()->status)->toBe(TeacherApplication::STATUS_APPROVED)
        ->and($this->teacher->refresh()->approval_status)->toBe(TeacherProfile::STATUS_APPROVED);
});

it('refuses to reject a teacher who is already approved — suspension is that door', function (): void {
    /*
    | ⚠️ عدمُ التماثلِ مع الاعتمادِ يعيشُ هنا: الرفضُ يهبطُ بالملفِّ إلى `rejected`
    | ويسحبُه من العرض، فمدرّسٌ قائمٌ يُشطَبُ دونَ المرورِ بـ`SuspendTeacher` —
    | حالةٌ أخرى وطريقٌ مسجَّل.
    */
    $this->application->forceFill(['status' => TeacherApplication::STATUS_APPROVED])->save();
    $this->teacher->forceFill([
        'approval_status' => TeacherProfile::STATUS_APPROVED,
        'is_publicly_listed' => true,
    ])->save();

    expect(fn () => app(RejectTeacherApplication::class)->handle($this->application, $this->reviewer, 'سبب'))
        ->toThrow(DomainException::class);

    // ولا يُمَسُّ الملفُّ ولا عرضُه: الرفضُ يكتبُ الاثنَينِ معاً، فبقاؤهما هو الدليل.
    $this->teacher->refresh();

    expect($this->teacher->approval_status)->toBe(TeacherProfile::STATUS_APPROVED)
        ->and($this->teacher->is_publicly_listed)->toBeTrue();
});

it('refuses to reject one that was already rejected', function (): void {
    $this->application->forceFill(['status' => TeacherApplication::STATUS_REJECTED])->save();

    expect(fn () => app(RejectTeacherApplication::class)->handle($this->application, $this->reviewer, 'سبب'))
        ->toThrow(DomainException::class);
});

it('still decides an application the reviewer is actually holding', function (): void {
    // الحالةُ العاديّةُ في البابَين، وبدونِها يمرُّ حارسٌ يرفضُ كلَّ شيء.
    app(RejectTeacherApplication::class)->handle($this->application, $this->reviewer, 'مؤهّلات غير كافية');

    expect($this->application->refresh()->status)->toBe(TeacherApplication::STATUS_REJECTED);

    $second = TeacherApplication::factory()->complete()->create([
        'user_id' => marketplaceTeacher($this->workspace)->user_id,
        'workspace_id' => $this->workspace->id,
        'teacher_profile_id' => marketplaceTeacher($this->workspace)->id,
        'status' => TeacherApplication::STATUS_CHANGES_REQUESTED,
    ]);

    app(ApproveTeacherApplication::class)->handle($second, $this->reviewer);

    expect($second->refresh()->status)->toBe(TeacherApplication::STATUS_APPROVED);
});
