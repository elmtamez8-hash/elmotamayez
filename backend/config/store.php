<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The platform's cut of a store sale, in basis points
    |--------------------------------------------------------------------------
    |
    | ⚠️ THE FALLBACK, NOT THE SETTING. The operator edits
    | `platform_settings['store.commission_bps']` from the panel; this is what a
    | database with nothing seeded answers. The repository's rule — «operational
    | numbers live in `platform_settings`, not `config/`» — is why the row wins.
    |
    | Basis points, so a rate is an integer all the way through. 1000 = 10%.
    |
    */
    'commission_bps' => 1000,

    /*
    |--------------------------------------------------------------------------
    | The refund window for a digital purchase, in hours
    |--------------------------------------------------------------------------
    |
    | Decision C4 of the second clarification session: 48 hours, and it closes
    | early the moment the file is opened. Both halves matter — a window with no
    | «opened» condition is a free copy of every book on the platform.
    |
    */
    'refund_window_hours' => 48,

];
