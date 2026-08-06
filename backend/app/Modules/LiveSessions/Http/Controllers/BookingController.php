<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Http\Resources\SessionBookingResource;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function store(Request $request, ClassSession $session, BookSeat $action): JsonResponse
    {
        $this->authorize('view', $session);

        try {
            $booking = $action->handle($session, $this->currentUser($request));
        } catch (DomainException $e) {
            // 409, not 422: nothing about the request was malformed — the state
            // of the world changed. The message still says which, so the screen
            // can explain rather than just refuse (FR-008).
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(SessionBookingResource::make($booking->load('classSession')), 201);
    }

    public function destroy(Request $request, SessionBooking $booking, CancelBooking $action): JsonResponse
    {
        $this->authorize('delete', $booking);

        try {
            $booking = $action->handle($booking, $request->input('reason'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(SessionBookingResource::make($booking->load('classSession')));
    }
}
