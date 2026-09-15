<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\CourseProgress;
use App\Shared\Support\WorkspaceContext;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ⛔ ٠٢٦ · FR-012أ · FR-013أ — **المقامُ واحدٌ لكلِّ كورسٍ مهما اختلفَ القارئ.**
|
| وهذه هي عائلةُ أسوأِ عطلٍ يسجّلُه هذا المستودعُ من ستّةِ أبواب: **عنصرٌ يدخلُ
| المقامَ ولا يمكنُ إتمامُه يُبقي الطالبَ تحتَ ١٠٠٪ للأبد، فلا يقعُ حدثُ إتمامِ
| الكورسِ ولا تصدرُ شهادةٌ أبداً.** والتضييقُ بابٌ سابعٌ إليه.
|
| ⚠️ **ومقامٌ يختلفُ باختلافِ القارئِ عطلٌ آخرُ بجوارِه**: طالبانِ في الكورسِ
| نفسِه يريانِ رقمَينِ مختلفَينِ عن العملِ نفسِه، وشهادةٌ تصدرُ لأحدِهما ولا
| تصدرُ للآخر.
*/
beforeEach(function (): void {
    $this->tree = $this->scopedTree();
});

function countableCount(Enrollment $enrollment): int
{
    return CourseProgress::total($enrollment);
}

it('leaves a narrowed item out of the denominator for everybody, not just for whoever cannot see it', function (): void {
    // ثلاثةُ عناصرَ في الشجرة، واحدٌ منها مقصور.
    expect(countableCount($this->tree['enrollment']))->toBe(2);
});

/*
| ⛔ **والحالةُ التي تفصلُ `whereNotIn` عن `whereDoesntHave`.**
|
| `whereDoesntHave` تُجري استعلامَ العلاقةِ تحتَ `WorkspaceScope`، و`id()` يرجعُ
| إلى `users.last_workspace_id` — وهو مطبوعٌ على كلِّ طالبٍ أُضيفَ يوماً إلى
| مساحةِ عمل. فالمقصورُ يدخلُ مقامَ ذلكَ الطالبِ وحدَه، ولا يستطيعُ إتمامَه
| أبداً.
|
| **كيفَ يمسك**: بدِّلِ الشرطَ بـ`whereDoesntHave('cohortScopes')` ⇒ تسقطُ هذه
| الحالةُ وحدَها، وتبقى التي فوقَها خضراءَ لأنّ سياقَها لا يختلف.
*/
it('gives a student stamped with another workspace the same denominator', function (): void {
    [$elsewhere] = $this->createWorkspaceWithOwner();

    $stamped = User::factory()->create();
    // ⚠️ `forceFill`: العمودُ في `User::$guarded`، فمصفوفةُ الإنشاءِ تُسقِطُه
    // بصمت — وتُبنى تجهيزةٌ لطالبٍ سياقُه فارغٌ، وهي الحالةُ التي لا ترى العطل.
    $stamped->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();

    $enrollment = Enrollment::create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $stamped->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    $this->actingAs($stamped);
    app()->forgetInstance(WorkspaceContext::class);

    expect(countableCount($enrollment->fresh()))->toBe(2);
});

/*
| ⛔ **والحالةُ الحاسمة: كلاهما يبلغُ ١٠٠٪.** الطالبُ الذي لا يرى المقصورَ
| والطالبُ الذي يراه، كلاهما يُتِمُّ المشترَكَينِ ويصلُ المئة — فالمقصورُ خارجُ
| المقامِ **بخاصّيّةِ العنصرِ** لا بحالِ القارئ.
|
| **كيفَ يمسك**: احذفْ `whereNotIn` من `progressEligible()` ⇒ يسقطُ بـ«٦٦.٦٧ ≠
| ١٠٠» لكلَيهما.
*/
it('takes both students to a hundred per cent on the shared items alone', function (): void {
    $insider = User::factory()->create();

    CohortMembership::query()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'cohort_id' => $this->tree['theirs']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $insider->getKey(),
        'joined_at' => now(),
    ]);

    $insiderEnrollment = Enrollment::create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'course_id' => $this->tree['course']->getKey(),
        'student_user_id' => $insider->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);

    foreach ([$this->tree['enrollment'], $insiderEnrollment] as $enrollment) {
        foreach (['shared_first', 'shared_last'] as $key) {
            /** @var Lesson $lesson */
            $lesson = $this->tree['lessons'][$key];

            $enrollment->progress()->updateOrCreate(
                ['lesson_id' => $lesson->getKey()],
                [
                    'workspace_id' => $this->tree['workspace']->getKey(),
                    'status' => 'completed',
                    'started_at' => now(),
                    'completed_at' => now(),
                ],
            );
        }

        CourseProgress::sync($enrollment->fresh());

        expect((int) $enrollment->fresh()->progress_pct)->toBe(100);
    }
});
