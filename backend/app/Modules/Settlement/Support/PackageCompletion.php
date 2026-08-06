<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;

/**
 * Has the session package actually reached the students?
 *
 * The seat earns because the absent student still gets the recording, the files
 * and the homework (Q2ج). Until that is true, the premise of the earning has not
 * been met — so a unit waits. It is released automatically and never by a human
 * decision (FR-008ب).
 *
 * Only `recording` is checkable today; files and homework arrive with spec 008.
 * FR-008أ is itself conditional ("if the course requires them"), so a component
 * nobody can require yet is not required.
 */
class PackageCompletion
{
    public function __construct(
        private readonly SettlementSettings $settings,
        private readonly BroadcastProviderInterface $provider,
    ) {}

    /**
     * What is still missing, or null when nothing is.
     *
     * A reason rather than a boolean, because FR-008د says the teacher must see
     * exactly what their unit is waiting for — "pending" with no explanation is
     * an unpaid hour and a support ticket.
     */
    public function missingReason(ClassSession $session): ?string
    {
        if (! in_array('recording', $this->settings->requiredPackageComponents(), true)) {
            return null;
        }

        // A provider that cannot record will never produce one, so there is
        // nothing to wait for. Holding the teacher's earning against a
        // capability the platform never bought is a deduction with no cause —
        // the same reasoning as FR-008ج, one step earlier.
        if (! $this->provider->capabilities()->recording) {
            return null;
        }

        return match ($session->recording_status) {
            // Delivered, or provably never coming.
            'published', 'failed', 'no_course' => null,
            // Still on its way — including null, which is the state between the
            // session closing and the ingest job starting. Treating null as
            // "nothing expected" would release every unit on the spot and make
            // the whole pending mechanism decorative.
            default => 'تسجيل الحصة لم يصل بعد.',
        };
    }

    /** Whether the release happened despite the provider losing the recording. */
    public function isRecordingFault(ClassSession $session): bool
    {
        return $session->recording_status === 'failed';
    }
}
