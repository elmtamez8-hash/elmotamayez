<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolve a single teacher for the public profile page.
 *
 * Route-model binding is deliberately NOT used: it resolves by uuid without the
 * publiclyListed() guard, which on a guest request means any workspace's draft
 * profile is reachable by url.
 */
class ShowPublicTeacher extends Action
{
    public function handle(string $uuid): TeacherProfile
    {
        $teacher = TeacherProfile::query()
            ->publiclyListed()
            ->with([
                'user:id,first_name,last_name',
                'subjects',
                'gradeLevels',
                'availabilitySlots',
            ])
            ->where('teacher_profiles.uuid', $uuid)
            ->first();

        if ($teacher === null) {
            // One response for "no such teacher", "not approved", "not published"
            // and "workspace withdrew". Distinguishing them would confirm that an
            // unpublished profile exists.
            throw new NotFoundHttpException('غير متاح');
        }

        return $teacher;
    }
}
