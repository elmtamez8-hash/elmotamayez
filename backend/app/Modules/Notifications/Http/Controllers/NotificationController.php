<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Actions\MarkAllNotificationsRead;
use App\Modules\Notifications\Actions\MarkNotificationRead;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
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

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        return NotificationResource::collection($query->paginate($perPage))
            ->additional(['meta' => ['unread_count' => $this->unreadCountFor($user)]]);
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
