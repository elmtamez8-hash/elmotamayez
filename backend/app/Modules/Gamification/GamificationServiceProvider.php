<?php

declare(strict_types=1);

namespace App\Modules\Gamification;

use App\Shared\Modules\Module;
use App\Shared\Modules\ModulesServiceProvider;

/**
 * Spec 009 — the gamification module.
 *
 * Auto-discovered by {@see ModulesServiceProvider}; never
 * register it by hand in bootstrap/providers.php.
 *
 * ⚠️ ZERO NEW EVENTS IN ANY EXISTING MODULE. This module listens to five events
 * that already ship (005 · 008) and emits three of its own that Notifications
 * subscribes to. It never calls an Action belonging to another module — the two
 * questions it cannot answer alone (who attended a session, is this student
 * frozen) are asked through contracts in App\Shared\Contracts.
 */
class GamificationServiceProvider extends Module
{
    protected string $name = 'Gamification';
}
