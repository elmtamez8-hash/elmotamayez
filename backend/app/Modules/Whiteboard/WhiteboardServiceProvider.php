<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard;

use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Policies\BoardPolicy;
use App\Modules\Whiteboard\Support\WhiteboardPersonalData;
use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Spec 039 — the teacher's whiteboard: boards prepared before class, drawn on live
 * and shared through the existing screen share, exported after.
 *
 * Auto-discovered by {@see ModulesServiceProvider}; never register it by hand in
 * bootstrap/providers.php.
 *
 * A new module costs six things (see StoreServiceProvider): the `phpstan.neon` row,
 * the capital `M` on `Database/Migrations`, a registered personal-data owner, its own
 * rows in the isolation tests, and `docs/README.md` + `docs/erd.md`.
 */
class WhiteboardServiceProvider extends Module
{
    protected string $name = 'Whiteboard';

    public function register(): void
    {
        parent::register();

        $this->app->tag([WhiteboardPersonalData::class], 'compliance.personal_data');
    }

    public function boot(): void
    {
        parent::boot();

        // Bound explicitly: the guesser fails OPEN (StoreServiceProvider explains).
        Gate::policy(Board::class, BoardPolicy::class);
    }
}
