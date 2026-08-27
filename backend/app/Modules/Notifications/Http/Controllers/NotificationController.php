<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Actions\MarkAllNotificationsRead;
use App\Modules\Notifications\Actions\MarkNotificationRead;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationCategory;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\FocusState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->currentUser($request);

        $query = Notification::query()
            ->forRecipient($user)
            ->with(['subject', 'workspace'])
            ->latest('id');

        $this->muteDuringFocus($query, $user);

        if ($request->boolean('unread')) {
            $query->unread();
        }

        // A filter, not a scope. Passing it narrows the feed to one academy;
        // omitting it shows everything the person is entitled to, across every
        // teacher they deal with (FR-025ب).
        if (is_string($request->query('workspace'))) {
            // Workspace is not itself tenant-scoped, so a plain lookup is right —
            // and an unknown uuid narrows to nothing rather than falling back to
            // showing everything.
            $workspace = Workspace::query()->where('uuid', $request->query('workspace'))->first();

            $query->where('workspace_id', $workspace?->getKey() ?? 0);
        }

        if (is_string($request->query('type')) && NotificationType::tryFrom((string) $request->query('type')) !== null) {
            $query->where('type', $request->query('type'));
        }

        /*
        | ⚠️ A SUBJECT, NOT A TYPE — and it narrows through the type list rather
        | than through a column. There is no `category` on the row and there must
        | not be: it would be a second copy of a classification the code already
        | holds, written at insert time, and wrong for every notification already
        | stored the day somebody re-files a type.
        |
        | An unknown value narrows to NOTHING rather than falling back to
        | everything — the same direction the `workspace` filter above fails in.
        | A tab headed «الحصص والمواعيد» that silently dropped its filter would
        | show the reader their whole feed under one word.
        */
        if (is_string($request->query('category'))) {
            $category = NotificationCategory::tryFrom((string) $request->query('category'));

            $query->whereIn('type', $category?->typeValues() ?? []);
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        return NotificationResource::collection($query->paginate($perPage))
            ->additional(['meta' => [
                'unread_count' => $this->unreadCountFor($user),
                'categories' => $this->categoriesFor($user),
            ]]);
    }

    /**
     * Its own endpoint because the header bell asks on every page and does not
     * need the payload. Served by the (recipient_user_id, read_at, id) index.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->unreadCountFor($this->currentUser($request))]);
    }

    public function markRead(Request $request, string $uuid, MarkNotificationRead $action): JsonResponse
    {
        $user = $this->currentUser($request);

        // 404 rather than 403. Nobody may enumerate this resource, so confirming
        // that a uuid exists is itself the leak the policy is there to prevent.
        $notification = Notification::query()->forRecipient($user)->where('uuid', $uuid)->firstOrFail();

        $action->handle($notification);

        return response()->json(['unread_count' => $this->unreadCountFor($user)]);
    }

    public function markAllRead(Request $request, MarkAllNotificationsRead $action): JsonResponse
    {
        $user = $this->currentUser($request);
        $action->handle($user);

        return response()->json(['unread_count' => 0]);
    }

    /**
     * The subjects this reader actually has, with what is unread in each.
     *
     * ⚠️ DERIVED FROM THE FEED, SO A TAB THAT ANSWERS NOTHING IS NEVER OFFERED.
     * A student never receives a settlement notice and a teacher never receives
     * a guardian-consent request, so a fixed row of seven tabs would show each of
     * them at least one control that empties the page — a tap that teaches the
     * reader not to trust the strip. Same rule the mistake notebook's filter bar
     * follows, and the rule spec 009's leaderboard picker was fixed under.
     *
     * ⚠️ AND THE COUNT IS THE POINT, NOT THE HIDING. Without it the tabs are
     * seven guesses; with it the page says «سبعة في الحصص وخمسة في الدراسة»
     * before the reader presses anything — which is the whole answer to a feed
     * of sixty-four.
     *
     * ONE `GROUP BY type` over this reader's own rows, folded into subjects in
     * PHP. Seven counting queries would be seven times the work for an answer
     * one pass already holds, and a `category` column to group on would be a
     * stored copy of a classification the code owns.
     *
     * ⚠️ IT IS NOT NARROWED BY THE REQUEST'S OWN FILTERS. The strip describes the
     * whole feed; recomputed under the category being read, every tab but the
     * open one would report zero and the reader would have no way back.
     *
     * @return list<array{key: string, label: string, unread: int, total: int}>
     */
    private function categoriesFor(User $user): array
    {
        $query = Notification::query()->forRecipient($user);

        $this->muteDuringFocus($query, $user);

        $rows = $query
            ->selectRaw('type, COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->groupBy('type')
            ->get();

        $byType = NotificationCategory::byType();
        $tally = [];

        foreach ($rows as $row) {
            // A type nobody classified belongs to no tab — and is still in «الكل».
            $category = $byType[(string) $row->getAttribute('type')] ?? null;

            if ($category === null) {
                continue;
            }

            $key = $category->value;
            $tally[$key]['total'] = ($tally[$key]['total'] ?? 0) + (int) $row->getAttribute('total');
            $tally[$key]['unread'] = ($tally[$key]['unread'] ?? 0) + (int) $row->getAttribute('unread');
        }

        $categories = [];

        // Walked in the enum's own order, so the strip does not reshuffle itself
        // between page loads as counts move.
        foreach (NotificationCategory::cases() as $category) {
            if (! isset($tally[$category->value])) {
                continue;
            }

            $categories[] = [
                'key' => $category->value,
                'label' => $category->label(),
                'unread' => $tally[$category->value]['unread'],
                'total' => $tally[$category->value]['total'],
            ];
        }

        return $categories;
    }

    private function unreadCountFor(User $user): int
    {
        $query = Notification::query()->forRecipient($user)->unread();

        $this->muteDuringFocus($query, $user);

        return $query->count();
    }

    /**
     * Hide the optional traffic while a student is in a focus session (009 FR-039).
     *
     * ⚠️ IT IS APPLIED HERE, ON THE READ, AND NOT IN `DispatchNotification` WHERE
     * spec 009's contract placed it. Implementing it there does not work, and the
     * reason is worth writing down rather than rediscovering:
     *
     *  - `QuietHours::deferUntil()` returns null immediately for anything that is
     *    not an EXTERNAL channel, so the bell has never passed through it. That
     *    part of the contract was right, and is why this exists at all.
     *  - But the notification RECORD is written before any channel is consulted
     *    (FR-007: one record however many channels carry it), and the bell reads
     *    that record — not a delivery row. So a check inside the dispatcher could
     *    only DROP the message, losing it, or defer a delivery the bell never
     *    looks at. Either way the badge still lights.
     *
     * Filtering the read mutes for exactly the length of the session, loses
     * nothing, and needs no column: when the session ends, everything the student
     * missed is simply there.
     *
     * ⚠️ AND `isMandatory()` IS THE VALVE, unchanged from quiet hours. A security
     * alert or a payment failure passes through — those change what the account
     * can do, and a student who cannot be told is a student locked out with no way
     * to learn why.
     *
     * @param  Builder<Notification>  $query
     */
    private function muteDuringFocus($query, User $user): void
    {
        if (! app(FocusState::class)->isFocusing($user)) {
            return;
        }

        $query->whereIn('type', NotificationType::mandatoryValues());
    }
}
