<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Support\MarketplaceCache;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * «معدّل الاستجابة» on a teacher's public page: of the times a student (or a
 * prospect, or a guardian writing as the child) wrote in the private chat and
 * was left waiting, the share the teacher's side answered within a day.
 *
 * ⚠️ IT HAD THREE READERS AND NO WRITER until 2026-10-11 — the public profile,
 * the panel and the factory — so every real teacher showed «—» and the demo
 * seeder's 95 was the only value the product had ever displayed.
 *
 * One WAIT is the first student message after the last staff reply (or the first
 * in the thread); follow-ups while waiting are the same wait. It is ANSWERED when
 * a `from_staff` message lands within RESPONSE_HOURS. A wait still open and
 * younger than that is not judged yet, so a message sent an hour ago never
 * counts against anyone. Hidden (moderated) messages are ignored on both sides.
 *
 * The private chat is per WORKSPACE (one thread per student), so every profile
 * in a workspace — the owner and any co-teacher — shows the workspace's rate:
 * the question a visitor is asking is «will someone answer me», and an assistant
 * answering IS the teacher's side answering.
 */
class SyncResponseRatesJob implements ShouldQueue
{
    use Queueable;

    public const WINDOW_DAYS = 90;

    public const RESPONSE_HOURS = 24;

    // ponytail: below this many judged waits the figure is noise (one unanswered
    // message reads «0٪»), so it stays «—». Make it a platform setting if asked.
    public const MINIMUM_WAITS = 5;

    public int $timeout = 600;

    /**
     * The middleware rather than the schedule's `withoutOverlapping()`, which
     * guards only the dispatch (docs/gotchas/deploy-ops.md).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('marketplace:response-rates'))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        $workspaceIds = DB::table('teacher_profiles')->distinct()->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            // ponytail: one workspace's 90 days of private messages in memory; a
            // cursor (or a per-conversation walk) when a workspace grows past that.
            $messages = DB::table('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.workspace_id', $workspaceId)
                ->where('conversations.kind', 'private')
                ->whereNull('messages.hidden_at')
                ->where('messages.created_at', '>=', $now->subDays(self::WINDOW_DAYS))
                ->orderBy('messages.conversation_id')
                ->orderBy('messages.id')
                ->get(['messages.conversation_id', 'messages.from_staff', 'messages.created_at']);

            DB::table('teacher_profiles')
                ->where('workspace_id', $workspaceId)
                ->update(['response_rate' => self::rate($messages, $now)]);
        }

        MarketplaceCache::flush();
    }

    /**
     * @param  iterable<\stdClass>  $messages  `conversation_id`, `from_staff`, `created_at`, ordered by conversation, then id
     */
    public static function rate(iterable $messages, CarbonImmutable $now): ?int
    {
        $answered = 0;
        $judged = 0;
        $conversation = null;
        $waitingSince = null;

        foreach ($messages as $message) {
            if ($message->conversation_id !== $conversation) {
                $judged += self::expired($waitingSince, $now);
                $waitingSince = null;
                $conversation = $message->conversation_id;
            }

            $at = CarbonImmutable::parse($message->created_at);

            if (! (bool) $message->from_staff) {
                $waitingSince ??= $at;

                continue;
            }

            if ($waitingSince !== null) {
                $judged++;
                $answered += $at->lessThanOrEqualTo($waitingSince->addHours(self::RESPONSE_HOURS)) ? 1 : 0;
                $waitingSince = null;
            }
        }

        $judged += self::expired($waitingSince, $now);

        return $judged < self::MINIMUM_WAITS ? null : (int) round($answered / $judged * 100);
    }

    /** 1 when a wait left open has gone unanswered for longer than allowed — judged against. */
    private static function expired(?CarbonImmutable $waitingSince, CarbonImmutable $now): int
    {
        return $waitingSince !== null && $waitingSince->addHours(self::RESPONSE_HOURS)->lessThan($now) ? 1 : 0;
    }
}
