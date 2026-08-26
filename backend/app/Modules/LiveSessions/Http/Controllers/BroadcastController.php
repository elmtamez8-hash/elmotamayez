<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LiveSessions\Actions\IssueJoinTicket;
use App\Modules\LiveSessions\Actions\PerformHostAction;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Exceptions\BroadcastProviderUnavailable;
use App\Modules\LiveSessions\Exceptions\UnsupportedCapability;
use App\Modules\LiveSessions\Http\Resources\JoinTicketResource;
use App\Modules\LiveSessions\Models\ClassSession;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BroadcastController extends Controller
{
    /**
     * A ticket, or 403 telling the caller nothing.
     *
     * No policy call and no route-model authorisation before the Action: every
     * reason to refuse — no seat, wrong time, room closed, session cancelled —
     * has to produce the SAME answer, and a policy that denies differently from
     * the Action is an enumeration oracle (FR-015 · the scenario 2 rule).
     */
    public function join(Request $request, ClassSession $session, IssueJoinTicket $action): JsonResponse
    {
        try {
            $ticket = $action->handle($session, $this->currentUser($request));
        } catch (BroadcastProviderUnavailable $e) {
            /*
             * ⚠️ CAUGHT ABOVE THE UNIFORM REFUSAL, AND THE ORDER IS THE WHOLE FIX.
             * It extends RuntimeException, so the arm below would swallow it and
             * tell a teacher whose provider is down to go and check their booking.
             * 503 with the real sentence: an outage does not vary with who is
             * asking, so naming it leaks nothing — FR-015 is about entitlement.
             */
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'broadcast_unavailable',
            ], 503);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'session_not_joinable',
            ], 403);
        }

        return response()->json(JoinTicketResource::make($ticket));
    }

    /**
     * The heartbeat.
     *
     * Answers with what the SERVER believes, not with what the client sent —
     * the page displays, the server decides. Refused for anyone who could not
     * get a ticket, because a ping is a claim to be in the room.
     */
    public function presence(Request $request, ClassSession $session, IssueJoinTicket $tickets, RecordPresencePing $action): JsonResponse
    {
        $user = $this->currentUser($request);

        try {
            $tickets->handle($session, $user);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'انتهت صلاحية وجودك في الغرفة.',
                'code' => 'session_not_joinable',
            ], 403);
        }

        $attendance = $action->handle($session, $user);

        return response()->json([
            'stay_seconds' => $attendance->stay_seconds,
            'status' => $attendance->status->value,
            'session_status' => $session->refresh()->status->value,
        ]);
    }

    public function host(Request $request, ClassSession $session, string $action, PerformHostAction $performer): JsonResponse
    {
        $this->authorize('host', $session);

        $hostAction = HostAction::tryFrom($action);

        if ($hostAction === null) {
            return response()->json(['message' => 'إجراء غير معروف.'], 422);
        }

        $target = $request->input('target_uuid') === null
            ? null
            : User::query()->where('uuid', $request->input('target_uuid'))->first();

        try {
            $performer->handle($session, $hostAction, $target);
        } catch (UnsupportedCapability $e) {
            // 501, not 500: the request was fine and the platform is fine — this
            // provider simply cannot do it, and saying so is the honest answer.
            return response()->json(['message' => $e->getMessage()], 501);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['done' => true]);
    }
}
