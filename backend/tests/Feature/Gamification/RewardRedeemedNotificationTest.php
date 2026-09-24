<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\RedeemReward;
use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;

/*
| `reward_redeemed` from a real redemption (`EveryNotificationTypeIsTestedTest`).
|
| A redeemed reward can be a discount on a session, which changes what the family
| pays — so the guardian entitled to PAYMENTS hears about it, and one entitled
| only to attendance does not.
*/

it('tells the student and the paying guardian that a reward was redeemed', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    $this->createEnrollment($workspace, $course, $student);

    $payer = guardianOf($student, [GuardianPermission::Payments]);
    $attendanceOnly = guardianOf($student, [GuardianPermission::Attendance]);

    app(ProgressWriter::class)->coinBalanceFor((int) $student->getKey(), (int) $workspace->getKey());
    CoinBalance::query()->withoutWorkspaceScope()
        ->where('user_id', $student->getKey())
        ->where('workspace_id', $workspace->getKey())
        ->update(['coins' => 100]);

    $reward = Reward::query()->create([
        'workspace_id' => $workspace->getKey(),
        'title' => 'دفتر ملاحظات',
        'price_coins' => 50,
        'stock' => 5,
        'type' => RewardType::Printed,
        'monthly_cap' => 10,
        'is_active' => true,
    ]);

    app(RedeemReward::class)->handle($student, $reward->uuid);

    assertNotifiedOnce($student, NotificationType::RewardRedeemed);
    assertNotifiedOnce($payer, NotificationType::RewardRedeemed);

    expect(wasNotified($attendanceOnly, NotificationType::RewardRedeemed))->toBeFalse();
});
