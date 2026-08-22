<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Models\BreachReport;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reported breach, as the officer's queue sees it.
 *
 * ⚠️ THE TWO DEADLINES ARE DERIVED HERE AND STORED NOWHERE. `created_at` plus the
 * notice window from `ComplianceSettings` is the whole calculation — a column
 * would be a second copy that stops agreeing with the setting the first time an
 * operator changes it, and the number a regulator shortens is exactly the number
 * an operator changes.
 *
 * ⚠️ AND `reporter_contact` REACHES ONLY THIS PAYLOAD, WHICH IS BEHIND
 * `compliance.breaches.manage`. It is whatever a person outside the platform left
 * so we could answer them, which makes it personal data belonging to somebody who
 * never opened an account.
 *
 * @mixin BreachReport
 */
class BreachReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'reporter_contact' => $this->reporter_contact,
            // Whether the reporter was signed in, never who they are: the queue
            // sorts and triages, it does not profile.
            'reported_by_account' => $this->reported_by_user_id !== null,
            'affected_categories' => $this->affected_categories,
            'affected_subject_count' => $this->affected_subject_count,
            'authority_notified_at' => $this->authority_notified_at?->toIso8601String(),
            'subjects_notified_at' => $this->subjects_notified_at?->toIso8601String(),
            'authority_notice_due_at' => $this->dueAt(
                ComplianceSettings::authorityNoticeHours(),
                $this->authority_notified_at !== null,
            ),
            'subjects_notice_due_at' => $this->dueAt(
                ComplianceSettings::subjectNoticeHours(),
                $this->subjects_notified_at !== null,
            ),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A deadline stops existing once it has been met — an obligation already
     * discharged is not overdue, and a queue that keeps counting on it reads as a
     * standing failure nobody can clear.
     */
    private function dueAt(int $hours, bool $alreadyDone): ?string
    {
        if ($alreadyDone || $this->status === BreachStatus::Closed) {
            return null;
        }

        return $this->created_at?->addHours($hours)->toIso8601String();
    }
}
