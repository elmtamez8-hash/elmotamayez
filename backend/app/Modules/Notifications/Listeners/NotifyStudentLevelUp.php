<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Gamification\Events\LevelReachedUp;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The student crossed a level threshold (spec 009 · FR-012).
 *
 * ⚠️ NO `workspaceId`. Experience is PLATFORM-owned — one level per person across
 * every teacher they study with — so attributing the congratulation to whichever
 * workspace happened to trigger the award would be filing a fact about the person
 * under one of their teachers.
 *
 * The event is raised after commit, so by the time this runs the level it names is
 * really stored.
 */
class NotifyStudentLevelUp implements ShouldQueue
{
    public function __construct(private readonly DispatchNotification $dispatch) {}

    public function handle(LevelReachedUp $event): void
    {
        // A level with no name in the catalogue would render an empty variable,
        // and TemplateRenderer counts present-but-empty as MISSING and drops the
        // whole notification. The number is always true.
        $name = $event->levelNameAr !== '' ? $event->levelNameAr : (string) $event->level;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $event->student,
            type: NotificationType::LevelUp,
            variables: [
                'student_name' => $event->student->name,
                'level_name' => $name,
            ],
            actionUrl: '/progress',
        ));
    }
}
