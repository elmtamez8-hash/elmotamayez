<?php

declare(strict_types=1);

/*
 * Routes for the LiveSessions module.
 *
 * App\Shared\Modules\Module adds the `/api/v1` prefix and the `api` middleware
 * group automatically — do not repeat either here. The root routes/api.php is
 * intentionally empty for the same reason.
 *
 * Every write carries a NAMED limiter (`throttle:sessions` / `throttle:presence`).
 * An inline `throttle:60,1` is banned: ThrottleRequests keys guests on domain|ip
 * with no route in the hash, so every inline limit shares one counter.
 */
