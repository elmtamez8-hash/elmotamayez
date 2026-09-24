<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\LinkGuardian;
use App\Modules\Identity\Data\LinkGuardianData;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| Both halves of a guardian link, each told to the one person it is for
| (`EveryNotificationTypeIsTestedTest`): the student is ASKED, and the guardian
| who asked is ANSWERED.
|
| ⚠️ Neither type targets guardians — each has a direct recipient — so a copy in
| the other party's feed would be the fan-out spec 030 decided against, and
| would move `WhatsAppDefaultsTest`'s pinned count. Both absences are asserted.
|
| The student is self-registered (null context), and the link is made by the
| real Action rather than written `active` by hand.
*/

it('asks the student, then answers the guardian once the student accepts', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    $relation = app(LinkGuardian::class)->handle($guardian, LinkGuardianData::fromArray([
        'student_name' => $student->name,
        'student_uuid' => $student->uuid,
        'relation_type' => RelationType::Guardian->value,
        'permissions' => [GuardianPermission::Payments->value],
    ]));

    assertNotifiedOnce($student, NotificationType::GuardianLinkRequested);

    expect(wasNotified($guardian, NotificationType::GuardianLinkRequested))->toBeFalse()
        ->and(wasNotified($guardian, NotificationType::GuardianLinkDecided))->toBeFalse();

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertOk();

    assertNotifiedOnce($guardian, NotificationType::GuardianLinkDecided);

    expect(wasNotified($student, NotificationType::GuardianLinkDecided))->toBeFalse();

    // A second press is not a second decision.
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertOk();

    assertNotifiedOnce($guardian, NotificationType::GuardianLinkDecided);
});
