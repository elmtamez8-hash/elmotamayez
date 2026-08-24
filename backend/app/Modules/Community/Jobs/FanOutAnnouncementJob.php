<?php

declare(strict_types=1);

namespace App\Modules\Community\Jobs;

use App\Models\User;
use App\Modules\Community\Models\Announcement;
use App\Modules\Community\Support\AnnouncementAudience;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell everyone in an announcement's scope, once each (FR-043 · SC-016).
 *
 * ⚠️ IT WALKS A KEYSET AND RE-DISPATCHES ITSELF, RATHER THAN LOOPING OVER
 * EVERYONE. `DispatchNotification` costs six to eight queries per recipient —
 * `TemplateRenderer` holds no cache — so three hundred students is roughly two
 * thousand four hundred queries in a job whose timeout is sixty seconds. One pass
 * that tried to finish would be killed most of the way through.
 *
 * ⚠️ AND BOTH HALVES ARE LOAD-BEARING, WHICH IS WHY NEITHER IS ENOUGH ALONE.
 *
 * The DIFF makes a re-run harmless: before dispatching, the chunk's recipients
 * are checked against the notifications already carrying this announcement's
 * source key, in ONE query, and the ones already told are dropped. So a worker
 * killed mid-chain, a queue retry, or a manual re-dispatch from zero all resume
 * without telling anybody twice — which is the half `SC-016` fails in one
 * direction without.
 *
 * The KEYSET makes progress guaranteed: were the diff the only mechanism, a
 * chunk whose renders all failed would be re-read for ever and the fan-out would
 * never reach the students after it. `$afterUserId` moves whatever happened.
 *
 * Between the two, `tries` is deliberately left at the queue default rather than
 * pinned to 1. A retry here is safe by construction, and refusing one would turn
 * a single dropped database connection into a partial delivery nobody notices —
 * the silent half of `SC-016`'s failure.
 *
 * ⚠️ AND THE ANNOUNCEMENT IS RE-READ AT THE TOP OF EVERY CHUNK, not once at the
 * start. A teacher who hides a notice mid-fan-out has stopped it; a job holding
 * a serialised copy would keep delivering a retracted message to the half of the
 * class it had not reached yet.
 */
class FanOutAnnouncementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Recipients per pass. Fifty × ~8 queries sits well inside the timeout. */
    private const CHUNK = 50;

    public function __construct(
        private readonly int $announcementId,
        private readonly int $afterUserId = 0,
    ) {
        $this->onQueue('community');
    }

    public function handle(AnnouncementAudience $audience, DispatchNotification $notifications): void
    {
        $announcement = Announcement::query()
            ->withoutGlobalScopes()
            ->find($this->announcementId);

        if ($announcement === null || ! $announcement->isLive()) {
            return;
        }

        $recipientIds = $this->nextChunk($audience->userIdsFor($announcement));

        if ($recipientIds === []) {
            return;
        }

        // ⚠️ ONE QUERY FOR THE WHOLE CHUNK, over the source index. Asked per
        // recipient it would be a fifty-first query per pass for a question the
        // index answers in one.
        $alreadyTold = Notification::query()
            ->where('source_type', Announcement::SOURCE_TYPE)
            ->where('source_id', $announcement->getKey())
            ->whereIn('recipient_user_id', $recipientIds)
            ->pluck('recipient_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $pending = array_values(array_diff($recipientIds, $alreadyTold));

        if ($pending !== []) {
            $type = $announcement->is_urgent
                ? NotificationType::AnnouncementUrgent
                : NotificationType::Announcement;

            /*
            | ⚠️ LOOKED UP RATHER THAN READ OFF THE RELATION, and a deleted author
            | does not silence a live announcement. `announcements.author_user_id`
            | carries no foreign key, so a removed account leaves the relation
            | resolving to null — and `$announcement->author->name` would then
            | throw, fail the job, and reach NOBODY over a message about a lesson
            | that is still happening. One query per chunk, not per recipient.
            */
            $author = User::query()->find($announcement->author_user_id);
            $authorName = $author instanceof User ? $author->name : 'المدرّس';

            foreach (User::query()->whereIn('id', $pending)->get() as $recipient) {
                $notifications->handle(new NotificationRequest(
                    recipient: $recipient,
                    type: $type,
                    // The order matches the seeder's `variables` array, which is
                    // the order that would travel to a provider as positional
                    // parameters. These two types never leave the platform, and
                    // the discipline is kept anyway — a template that starts
                    // leaving one day must not need its callers audited first.
                    variables: [
                        'teacher_name' => $authorName,
                        'body' => $announcement->body,
                    ],
                    workspaceId: (int) $announcement->workspace_id,
                    sourceType: Announcement::SOURCE_TYPE,
                    sourceId: (int) $announcement->getKey(),
                ));
            }
        }

        // Forward progress whatever happened above, and the next pass re-reads
        // the announcement — so a hide lands within one chunk.
        self::dispatch($this->announcementId, (int) max($recipientIds));
    }

    /**
     * @param  list<int>  $all
     * @return list<int>
     */
    private function nextChunk(array $all): array
    {
        $remaining = array_values(array_filter($all, fn (int $id): bool => $id > $this->afterUserId));

        return array_slice($remaining, 0, self::CHUNK);
    }
}
