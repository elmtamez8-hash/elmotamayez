<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\BillingSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Credits nobody came back for, and a reminder that they are still there (Q-8).
 *
 * ⚠️ A REMINDER, NEVER AN EXPIRY. Nothing is forfeited, nothing is moved to
 * another teacher, and no counter is reset. The balance stays exactly as it is;
 * the only thing this changes is that its owner is told about it. A sweep that
 * confiscated dormant credits would be the platform inventing an expiry it never
 * sold — and moving them to another teacher would be a re-pricing, since a credit
 * is worth one session at ONE teacher's approved rate.
 *
 * ⚠️ AND IT IS SENT ONCE PER DORMANCY, not once a night for ever. The window is
 * anchored on `last_transaction_at`, so a student who never returns would be
 * reminded every night by a naive predicate — which is how a courtesy becomes the
 * reason someone turns notifications off. The notice itself is a transaction-free
 * event, so the anchor cannot move; the ledger stamps `notified_dormant_at`
 * instead, and a later purchase or charge clears it.
 */
class NotifyDormantBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BillingSettings $settings, DispatchNotification $notifications): void
    {
        $months = $settings->dormantNoticeMonths();
        $cutoff = now()->subMonths($months);

        // Pushed into SQL, all three conditions: only a POSITIVE balance has
        // anything to come back for, only an untouched one is dormant, and only
        // one not already told needs telling. A filter in PHP over every balance
        // on the platform would be the same answer read the expensive way.
        CreditBalance::query()
            ->withoutWorkspaceScope()
            ->where('remaining_credits', '>', 0)
            ->whereNotNull('last_transaction_at')
            ->where('last_transaction_at', '<=', $cutoff)
            ->whereNull('notified_dormant_at')
            // The student as well as the course: the notice names both, and one
            // of the two used to be fetched per row inside the loop — a 1 + N
            // hiding behind an eager load that looked complete.
            ->with(['course', 'student'])
            ->chunkById(200, function (iterable $balances) use ($months, $notifications): void {
                foreach ($balances as $balance) {
                    $this->notify($balance, $months, $notifications);
                }
            });
    }

    private function notify(CreditBalance $balance, int $months, DispatchNotification $notifications): void
    {
        $student = $balance->student;

        if ($student === null) {
            return;
        }

        // Stamped BEFORE the dispatch, not after. The notification is queued, so
        // a worker that dies between the two would otherwise leave the balance
        // eligible again tomorrow — and the cost of the two orders is not
        // symmetric: one reminder lost against one every night for ever.
        $balance->forceFill(['notified_dormant_at' => now()])->save();

        $notifications->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::CreditBalanceDormant,
            variables: [
                'course' => (string) $balance->course->title,
                'credits' => (string) $balance->remaining_credits,
                'months' => (string) $months,
            ],
            actionUrl: '/billing',
            subject: $student,
            workspaceId: (int) $balance->workspace_id,
        ));
    }
}
