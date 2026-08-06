<?php

declare(strict_types=1);

/*
 * Settlement routes.
 *
 * `App\Shared\Modules\Module` loads this file with the `/api/v1` prefix and the
 * `api` middleware group already applied — do not repeat either here.
 *
 * Every write carries the NAMED limiter `settlement-write`. An inline
 * `throttle:N,M` is banned: ThrottleRequests hashes only `domain|ip` with no
 * route in the key, so every inline limit in the product shares one counter and
 * the strictest one wins.
 */
