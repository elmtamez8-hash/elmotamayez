<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\BreachStatus;
use App\Modules\Compliance\Models\BreachReport;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Move a reported breach along, and record what triage found (FR-040 · SC-020).
 *
 * ⚠️ EVERY FIELD THIS ACTION WRITES IS DELIBERATELY OUTSIDE `$fillable`, SO
 * `update()` WOULD DISCARD ALL OF THEM IN SILENCE. `BreachReport::$fillable` holds
 * only what a reporter may write, because the public route mass-assigns a request
 * body — and mass assignment drops a non-fillable key with no exception, no log
 * and a 200. That is exactly how spec 013's own US1 shipped three columns that
 * were never once written. `forceFill()` is the deliberate other door, and it is
 * only safe here because this Action's caller is a platform officer whose input
 * has already been validated field by field.
 *
 * ⚠️ AND A NOTIFICATION TIMESTAMP IS NEVER OVERWRITTEN. It is the record of WHEN
 * the authority and the affected people were told, measured against a deadline in
 * `ComplianceSettings` — re-stamping it on a later edit moves a legal fact to
 * whenever somebody last touched the row, and always inside the window.
 */
class AdvanceBreachReport extends Action
{
    /**
     * @param  array{affected_categories?: list<string>|null, affected_subject_count?: int|null, authority_notified?: bool, subjects_notified?: bool}  $triage
     */
    public function handle(BreachReport $report, BreachStatus $to, array $triage = []): BreachReport
    {
        /*
        | ⚠️ FORWARD ONLY, AND EQUAL IS ALLOWED. Handling a breach is a sequence of
        | obligations that have already been discharged — walking a report back to
        | `reported` would leave `authority_notified_at` set on a row claiming
        | nobody has been told, and there is no undoing a notification anyway.
        | Staying put is how the officer adds a category count without moving the
        | status, which is the ordinary case during triage.
        */
        if ($to->rank() < $report->status->rank()) {
            throw new DomainException('لا يمكن إرجاع البلاغ إلى حالةٍ سابقة.');
        }

        if (array_key_exists('affected_categories', $triage)) {
            $report->forceFill(['affected_categories' => $triage['affected_categories']]);
        }

        if (array_key_exists('affected_subject_count', $triage)) {
            $report->forceFill(['affected_subject_count' => $triage['affected_subject_count']]);
        }

        if (($triage['authority_notified'] ?? false) && $report->authority_notified_at === null) {
            $report->forceFill(['authority_notified_at' => now()]);
        }

        if (($triage['subjects_notified'] ?? false) && $report->subjects_notified_at === null) {
            $report->forceFill(['subjects_notified_at' => now()]);
        }

        /*
        | ⚠️ `Notified` IS REFUSED UNTIL BOTH SIDES ARE ACTUALLY RECORDED. The
        | authority and the people whose data leaked are two obligations on two
        | clocks, which is why the table carries two columns rather than a flag —
        | and a status anybody can set to `notified` while one of them is null is a
        | row that reads as complete over an obligation nobody discharged. The
        | deadline shown beside it is derived from these columns, so an unguarded
        | status would also stop that countdown.
        */
        if ($to === BreachStatus::Notified
            && ($report->authority_notified_at === null || $report->subjects_notified_at === null)) {
            throw new DomainException('لا يمكن اعتماد الإبلاغ قبل تسجيل إخطارِ الجهة المختصّة وإخطارِ المعنيّين.');
        }

        if ($to === BreachStatus::Closed && $report->closed_at === null) {
            $report->forceFill(['closed_at' => now()]);
        }

        $report->forceFill(['status' => $to])->save();

        return $report;
    }
}
