<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Tenancy\Models\Workspace;

/**
 * The workspace's own answer to FR-033: does the grader see whose paper it is?
 *
 * ⚠️ THE ANSWER IS APPLIED IN THE RESOURCE, NOT ON THE SCREEN. A page that hides
 * a name the payload still carries is an anonymity that survives exactly as long
 * as nobody opens the network tab — and the person most likely to open it is the
 * teacher who suspects whose handwriting they are reading.
 *
 * Off by default. A teacher of twelve knows the essays anyway; turning it on is
 * a deliberate act by somebody who wants the discipline.
 */
class GradingSettings
{
    private const SETTINGS_KEY = 'grading';

    public function isAnonymous(Workspace $workspace): bool
    {
        $settings = $workspace->settings;

        if (! is_array($settings) || ! is_array($settings[self::SETTINGS_KEY] ?? null)) {
            return false;
        }

        return (bool) ($settings[self::SETTINGS_KEY]['anonymous'] ?? false);
    }

    /**
     * ⚠️ MERGED, NEVER REPLACED. `workspaces.settings` is one JSON column that
     * billing already writes to; assigning a fresh array here drops a teacher's
     * billing mode the first time they touch a grading switch.
     */
    public function setAnonymous(Workspace $workspace, bool $anonymous): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $grading = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];

        $settings[self::SETTINGS_KEY] = [...$grading, 'anonymous' => $anonymous];

        $workspace->forceFill(['settings' => $settings])->save();
    }
}
