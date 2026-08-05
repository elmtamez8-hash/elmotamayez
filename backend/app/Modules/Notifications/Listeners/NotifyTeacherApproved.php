<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyTeacherApproved implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(TeacherApproved $event): void
    {
        $profile = $event->profile;
        $user = $profile->user;

        if ($user === null) {
            return;
        }

        // An approved teacher inside a workspace that has not opted into the
        // marketplace is approved but unlisted. Saying "your profile is live"
        // would send them looking for a page that is not there — so the two cases
        // are two template variables, not one sentence.
        $this->dispatch->handle(new NotificationRequest(
            recipient: $user,
            type: NotificationType::TeacherApplicationApproved,
            variables: [
                'name' => $user->name,
                'listing_state' => $profile->is_publicly_listed
                    ? 'ملفك الآن ظاهر في السوق ويمكن للطلاب حجز حصص معك.'
                    : 'ملفك جاهز، وسيظهر للطلاب فور تفعيل أكاديميتك للعرض في السوق.',
            ],
            actionUrl: $profile->is_publicly_listed ? '/teachers/'.$profile->uuid : null,
            workspaceId: $profile->workspace_id,
        ));
    }
}
