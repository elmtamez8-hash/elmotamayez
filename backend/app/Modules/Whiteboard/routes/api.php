<?php

declare(strict_types=1);

/*
| Whiteboard routes — spec 039.
|
| Auto-prefixed `/api/v1` with the `api` middleware group by
| `App\Shared\Modules\Module::registerRoutes()`.
|
| ⚠️ EVERY NESTED UUID IS RESOLVED THROUGH THE BOARD, never by implicit binding on
| its own: no module here uses `scopeBindings()`, and a page or file uuid bound
| alone would resolve another board's row in the same workspace.
|
| ⚠️ EVERY ROUTE HERE HAS A ROW IN `BoardIsolationTest`, which counts them — a new
| route without a row turns that test red.
*/
