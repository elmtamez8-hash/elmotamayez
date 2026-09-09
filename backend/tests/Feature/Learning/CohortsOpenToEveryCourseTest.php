<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| «المجموعات متاحة لكورسات المجموعة فقط» — رفضٌ عن تصنيفٍ لم يتّخذْه أحد.
|
| ⚠️ `courses.course_type` لم يكتُبْه شيءٌ قطّ. قابلٌ للإسنادِ، افتراضُه في هجرتِه
| `recorded`، ولا طلبٌ ولا نموذجٌ ولا فِعلٌ ولا بذرةٌ يُسنِدُه: ٩١ من ٩٦ صفّاً
| `recorded` (مقيسٌ ٢٠٢٦-٠٩-٠٩) — من بينِها كورسٌ يحملُ سبعَ عشرةَ حصّةً حيّةً
| قيلَ لصاحبِه إنّ المجموعاتِ «لكورساتِ المجموعةِ فقط».
|
| فالقاعدةُ كانت تفرضُ قراراً لم يُتَّخَذْ، وتُغلِقُ البابَ الوحيدَ الذي يفتحُ
| زرَّ الإسنادِ في شاشةِ «حصص محجوبة» — الشاشةُ تشترطُ مجموعةً مفتوحةً واحدةً على
| الأقلّ لتعرضَ الزرّ.
*/
it('lets a teacher open a group on a course nobody ever classified', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
        // The migration's own default, which is what 91 of 96 rows carry.
        'course_type' => Course::TYPE_RECORDED,
    ]));

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/manage/courses/{$course->uuid}/cohorts", ['name' => 'مجموعة السبت'])
        ->assertCreated()
        ->assertJsonPath('name', 'مجموعة السبت')
        ->assertJsonPath('status', Cohort::OPEN);

    expect(Cohort::query()->withoutWorkspaceScope()->where('course_id', $course->getKey())->count())->toBe(1);
});
