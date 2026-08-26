<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Exceptions;

use RuntimeException;

/**
 * The provider could not be reached, or refused for a reason that is ours to fix.
 *
 * ⚠️ A DISTINCT ANSWER FROM «you may not enter», AND THE DISTINCTION IS THE POINT.
 * Every entitlement refusal in this module is deliberately identical (`FR-015`):
 * no seat, wrong time, room closed and session cancelled all produce one sentence,
 * because a distinguishable one is an enumeration tool. An outage is not an
 * entitlement fact — it does not vary with who is asking — so telling the truth
 * about it leaks nothing, and telling the 403 story instead sends a teacher to
 * check a booking that was never the problem.
 *
 * It was a **500** until 2026-08-26 (`T051` step ١٢, the step the quickstart calls
 * the most important and least noticed): `TwirpError` escaped `createRoom()`
 * uncaught, so the teacher's screen carried a cURL error naming an internal host
 * — and, with `APP_DEBUG` off in production, nothing at all. The money half of
 * that step was already right and stays right: no room, no `room_opened_at`, and
 * zero rows in `credit_transactions`, `teaching_units` and `ledger_entries`,
 * because `SessionDelivered` never fires for a session that never opened.
 */
final class BroadcastProviderUnavailable extends RuntimeException {}
