<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Community\Contracts\OnlineDirectory;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Online on the platform» = the person's own `private-user.{uuid}` channel is
 * occupied on Reverb right now.
 *
 * ⚠️ NO NEW CHANNEL, AND THAT IS THE DESIGN. Every signed-in tab already holds
 * `user.{uuid}` — the notification bell and the chat sidebar subscribe to it on
 * every panel page — so «is this channel occupied» IS «does this person have the
 * product open», measured by the socket itself. The alternatives were worse:
 *  - a presence channel per user that counterparts JOIN hands every joiner the
 *    member list, so each of a teacher's students would see which OTHER students
 *    are talking to that teacher;
 *  - a presence channel per conversation joined from every page is one
 *    subscription and one `/broadcasting/auth` request per thread, per page load —
 *    two hundred for a busy teacher, on the `broadcast-auth` limiter.
 * Here the server asks Reverb one question (the Pusher HTTP API,
 * `GET /channels?filter_by_prefix=private-user.`) and filters the answer to the
 * uuids it was given. Nothing is stored (`FR-058`).
 *
 * The answer is cached for a few seconds, shared by every reader: a list that
 * polls is otherwise one Reverb request per open sidebar every poll.
 */
final class ReverbOnlineDirectory implements OnlineDirectory
{
    private const PREFIX = 'private-user.';

    private const CACHE_KEY = 'community:online-user-channels';

    private const FRESH_SECONDS = 10;

    private const STALE_SECONDS = 30;

    public function __construct(private readonly BroadcastManager $broadcast) {}

    public function onlineAmong(array $userUuids): array
    {
        if ($userUuids === []) {
            return [];
        }

        $online = $this->occupiedUserUuids();

        return array_values(array_filter($userUuids, fn (string $uuid): bool => isset($online[$uuid])));
    }

    /** @return array<string, true> */
    private function occupiedUserUuids(): array
    {
        /*
        | ⚠️ `flexible`, NOT `remember`: fresh for ten seconds, then served stale for
        | twenty more while ONE locked refresh runs after the response. With
        | `remember`, the instant the entry expired every sidebar polling at that
        | moment asked Reverb at once.
        */
        /** @var array<string, true> $cached */
        $cached = Cache::flexible(
            self::CACHE_KEY,
            [self::FRESH_SECONDS, self::STALE_SECONDS],
            fn (): array => $this->askReverb(),
        );

        return $cached;
    }

    /**
     * One question to Reverb. Any failure — no socket configured, the server
     * down, the 3-second client timeout in `config/broadcasting.php` — answers
     * «nobody», which is also what an outage looks like: a dot that does not
     * light is not an error anybody can act on.
     *
     * @return array<string, true>
     */
    private function askReverb(): array
    {
        try {
            // Inside the try: resolving the connection can itself throw (a
            // missing key, an unreachable driver).
            $broadcaster = $this->broadcast->connection();

            // `null` or `log` in tests and in a deployment with no socket.
            if (! $broadcaster instanceof PusherBroadcaster) {
                return [];
            }

            $response = $broadcaster->getPusher()->getChannels(['filter_by_prefix' => self::PREFIX]);
        } catch (Throwable $e) {
            Log::warning('community.online_lookup_failed', ['exception' => $e::class]);

            return [];
        }

        $uuids = [];

        foreach (array_keys((array) ($response->channels ?? [])) as $name) {
            $uuids[substr((string) $name, strlen(self::PREFIX))] = true;
        }

        return $uuids;
    }
}
