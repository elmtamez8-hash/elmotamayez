<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| `teacher_profile_uuid` — «هل يستضيفُ هذا الحسابُ حصصاً؟» (٠٢٩ · `FR-007أ`).
|
| ⚠️ الحقلُ سؤالٌ عن المضيفِ لا عن الدَّور. اللوحةُ تقيِّدُ به قراءةَ الحصصِ على
| صاحبِها — `?teacher={uuid}` تحتَ «حصصي» — وتُفرِّقُ به بينَ **مدرّسٍ بلا حصصٍ
| هذا الأسبوع** و**مساعدٍ لا يستضيفُ شيئاً أصلاً**: جملتانِ مختلفتانِ على الشاشة،
| وبلا هذا الحقلِ تُرسَمُ الأولى مكانَ الثانيةِ — جدولٌ فارغٌ تحتَ «حصصي» لمن لا
| حصّةَ له إطلاقاً، وحصصُ زميلٍ تحتَه لو حُذِفَ المُرشِّح.
*/

it('answers with the uuid for a host and with null for everyone else', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $teacher);

    $profile = TeacherProfile::factory()->create(['user_id' => $teacher->getKey()]);

    $student = $this->addWorkspaceMember($workspace);
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    $this->setCurrentWorkspace($workspace, $teacher);
    Sanctum::actingAs($teacher);
    expect($this->getJson('/api/v1/auth/me')->json('teacher_profile_uuid'))->toBe($profile->uuid);

    /*
    | ⚠️ `null` **مُرسَلٌ لا غائب**. الغيابُ والفراغُ جوابانِ مختلفان: القارئةُ
    | تسألُ `!== null`، ومفتاحٌ لا يصلُ يقرأُ `undefined` — وهو نفسُ الفرعِ اليومَ
    | وفرعٌ آخرُ يومَ يُضافُ `??`.
    */
    Sanctum::actingAs($student);
    $payload = $this->getJson('/api/v1/auth/me')->json();
    expect($payload)->toHaveKey('teacher_profile_uuid')
        ->and($payload['teacher_profile_uuid'])->toBeNull();

    Sanctum::actingAs($guardian);
    expect($this->getJson('/api/v1/auth/me')->json('teacher_profile_uuid'))->toBeNull();
});

it('answers for the workspace the reader is in, and does not stay null after moving', function (): void {
    /*
    | ⚠️ شخصٌ واحدٌ في مساحتَين، والقراءةُ الأولى هي التي تُفسِدُ الثانية.
    |
    | `teacher_profiles` يحملُ `BelongsToWorkspace`، فالصفُّ يُحَلُّ تحتَ سياقِ
    | القارئ: يُدرِّسُ في «أ» ويتعلّمُ في «ب»، فالجوابُ في «ب» `null` صادقٌ — لا
    | يستضيفُ هناك شيئاً. والفخُّ أنّ Eloquent **يُخزِّنُ العلاقةَ المحمَّلةَ بما
    | فيها `null`**، فقراءةٌ في «ب» قبلَ قراءةٍ في «أ» تُبقي الجوابَ `null` في
    | مساحتِه هو — وهو بالضبطِ سببُ وجودِ `unsetRelation('roles')` في المَورِدِ
    | نفسِه، مكتوباً هناك عن الأدوارِ ومقيساً هنا عن الملفّ.
    */
    [$teaches, $person] = $this->createWorkspaceWithOwner();
    [$learns] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($teaches, $person);
    $profile = TeacherProfile::factory()->create(['user_id' => $person->getKey()]);

    $this->addWorkspaceMember($learns, Roles::STUDENT, $person);

    Sanctum::actingAs($person);

    $this->setCurrentWorkspace($learns, $person);
    expect($this->getJson('/api/v1/auth/me')->json('teacher_profile_uuid'))->toBeNull();

    /*
    | ⚠️ **حسابٌ طازجٌ للطلبِ الثاني، وهذا ليس تحايلاً على الاختبار.** الطلبُ
    | الحقيقيُّ يبني نموذجَ المستخدمِ من قاعدةِ البيانات، فلا علاقةَ محمَّلةً
    | يحملُها من طلبٍ سابق؛ بينما `Sanctum::actingAs()` يُثبِّتُ **كائناً واحداً**
    | على الطلباتِ الثلاثة. فإبقاؤُه هنا يقيسُ ذاكرةَ الحزامِ لا سلوكَ المنتَج —
    | وهو تماماً ما تصفُه القاعدةُ المكتوبةُ في هذا المستودع: تركيبةٌ تُعطي
    | القارئَ سياقاً لا تُعطيه له البيئةُ الحقيقيّةُ تقيسُ شخصاً غيرَ موجود.
    */
    $this->setCurrentWorkspace($teaches, $person);
    Sanctum::actingAs($person->fresh());
    expect($this->getJson('/api/v1/auth/me')->json('teacher_profile_uuid'))->toBe($profile->uuid);
});

it('keeps the profile in the workspace it was created in', function (): void {
    // حارسُ التركيبةِ لا حارسُ الميزة: `BelongsToWorkspace` يملأُ `workspace_id`
    // من السياقِ لحظةَ الإنشاء، فتركيبةٌ تُنشئُ الملفَّ تحتَ سياقٍ خاطئٍ تفشلُ
    // لسببٍ لا علاقةَ له بما تقيسُه — وتُقرأُ على أنّ الحقلَ معطوب.
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $teacher);

    $profile = TeacherProfile::factory()->create(['user_id' => $teacher->getKey()]);

    expect($profile->workspace_id)->toBe($workspace->getKey())
        ->and(app(WorkspaceContext::class)->id())->toBe($workspace->getKey());
});
