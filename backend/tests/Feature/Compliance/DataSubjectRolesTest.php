<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| عن مَن كلُّ فئةٍ من فئاتِ البيانات.
|
| ⛔ بلاغُ مستخدِمٍ ٢٠٢٦-٠٩-١٦: شاشةُ «خصوصيّتي» تعرضُ الكتالوجَ كاملاً لكلِّ
| حساب، فقرأَ مدرّسٌ عن «تقدّمك في الدروس» و«محاولاتك في الاختبارات». الجدولُ
| يحملُ `audience` منذُ ٠١٣ — وهو جوابٌ عن السؤالِ الآخر: مَن **يطّلعُ** عليها،
| نصّاً حرّاً بالعربيّة. فلم يكنْ في الجدولِ ما يقولُ لمن الفئةُ نفسُها.
*/

it('says who each category is about, in the payload', function (): void {
    $body = $this->getJson('/api/v1/privacy/categories')->json('data');

    $byKey = collect($body)->keyBy('key');

    expect($byKey['exam_answer']['subject_roles'])->toBe(['student'])
        ->and($byKey['teacher_earnings']['subject_roles'])->toBe(['teacher'])
        // التسجيلُ يحملُ صوتَ المدرّسِ وصورتَه كما يحملُ صوتَ الطالبِ وصورتَه.
        ->and($byKey['class_recording']['subject_roles'])->toBe(['student', 'teacher']);
});

/*
| ⚠️ **البابُ يبقى عامّاً، وذلك شرطٌ لا أثرٌ جانبيّ.** مَن يُوازِنُ تسجيلَ ابنِه
| يقرأُ ما سيُجمَعُ **قبلَ** أن يكونَ له حساب، فترشيحٌ على الخادمِ كانَ سيُخفي
| عن زائرٍ نصفَ السياسة. الترشيحُ في شاشةِ «بياناتي أنا» وحدَها.
*/
it('publishes the whole catalogue to a visitor with no account', function (): void {
    $body = $this->getJson('/api/v1/privacy/categories');

    $body->assertOk();

    expect(count($body->json('data')))->toBe(DataCategory::query()->count());
});

it('calls a teaching account a teacher, from the pivot rather than the column', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $teacher);

    /*
    | ⚠️ العمودُ فارغٌ عمداً: قِيسَ على قاعدةٍ حقيقيّةٍ أنّه فارغٌ عندَ سبعةٍ
    | وثلاثينَ حساباً منها ثمانيةٌ تحملُ دوراً تدريسيّاً، فقراءةٌ منه وحدَها
    | تُخطئُ في ثمانيةٍ بلا أثرٍ في أيِّ سِجِلّ.
    */
    $teacher->forceFill(['platform_role' => null])->save();

    Sanctum::actingAs($teacher);

    expect($this->getJson('/api/v1/auth/me')->json('data_subject_roles'))->toBe(['teacher']);
});

it('gives both sides to a teacher who also studies', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $teacher);

    $teacher->forceFill(['platform_role' => PlatformRole::Student->value])->save();

    Sanctum::actingAs($teacher);

    expect($this->getJson('/api/v1/auth/me')->json('data_subject_roles'))
        ->toBe(['teacher', 'student']);
});

it('calls a workspace member with the student role a student, never a teacher', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // محورُ `workspace_members` يحملُ صفوفَ طلّابٍ فعلاً — قِيسَ: ستّةٌ منها.
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $student->forceFill(['platform_role' => PlatformRole::Student->value])->save();

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/auth/me')->json('data_subject_roles'))->toBe(['student']);
});

/*
| ⚠️ **الاتّجاهُ الآمنُ قرارٌ لا سهو.** حسابٌ لا يُدرِّسُ ولا أعلنَ دورَه لا
| نعرفُ عنه شيئاً — وإخفاءُ فئةٍ بياناتُه فيها **شاشةُ موافقةٍ تكذِب**، بينما
| عرضُ فئةٍ لا تخصُّه ضجيجٌ يُقرَأُ ويُتجاوَز.
*/
it('falls back to every role when it cannot tell, rather than to none', function (): void {
    $unknown = User::factory()->create(['platform_role' => null]);

    Sanctum::actingAs($unknown);

    expect($this->getJson('/api/v1/auth/me')->json('data_subject_roles'))
        ->toBe(['student', 'teacher', 'parent']);
});

it('leaves no category without a subject', function (): void {
    $orphans = DataCategory::query()->get()
        ->filter(fn (DataCategory $category): bool => $category->subject_roles === [])
        ->pluck('key')
        ->all();

    expect($orphans)->toBe([]);
});
