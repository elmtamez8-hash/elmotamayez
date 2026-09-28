<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Tenancy\Models\Workspace;

/**
 * The teacher's own answer to «do I take messages from people who do not study
 * with me?» (owner decision 2026-09-28, «استقبال رسائل من غير المشتركين»).
 *
 * ⚠️ ON BY DEFAULT, and a missing key reads as ON. A teacher who never opened the
 * setting has not refused anybody; the platform's promise on the public course
 * page is that a visitor can ask a question before paying.
 *
 * ⚠️ IT GOVERNS PROSPECTS ONLY. A subscriber — an active or completed enrolment in
 * this workspace — writes whatever this says, because a teacher who switches off
 * strangers has not switched off their own class. `ConversationPolicy::post()` is
 * where that order is kept.
 *
 * Stored in `workspaces.settings` beside `GradingSettings`' block — the one JSON
 * column this tree keeps a workspace's own preferences in — and MERGED, never
 * replaced, for the reason that class gives: billing writes the same column.
 */
class TeacherInboxSettings
{
    private const SETTINGS_KEY = 'inbox';

    public function acceptsProspects(Workspace $workspace): bool
    {
        $settings = $workspace->settings;

        if (! is_array($settings) || ! is_array($settings[self::SETTINGS_KEY] ?? null)) {
            return true;
        }

        return (bool) ($settings[self::SETTINGS_KEY]['accepts_prospects'] ?? true);
    }

    public function acceptsProspectsIn(int $workspaceId): bool
    {
        $workspace = Workspace::query()->find($workspaceId);

        // A conversation whose workspace is gone has nobody to receive anything;
        // the departure check in the policy answers that case with its own words.
        return $workspace instanceof Workspace && $this->acceptsProspects($workspace);
    }

    public function setAcceptsProspects(Workspace $workspace, bool $accepts): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $inbox = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];

        $settings[self::SETTINGS_KEY] = [...$inbox, 'accepts_prospects' => $accepts];

        $workspace->forceFill(['settings' => $settings])->save();
    }
}
