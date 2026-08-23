<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Shared\Contracts\AssistantScopeDirectory;

/**
 * The permissions no assistant may exercise, however they came to hold one.
 *
 * ⚠️ DERIVED FROM PREFIXES, NEVER LISTED BY HAND — and the property a hand-written
 * list has is the wrong way round. FR-003 refuses an assistant every financial
 * surface, so the safe default for a name nobody has classified yet is FORBIDDEN;
 * a literal array makes it ALLOWED, silently, until somebody remembers. A
 * settlement permission added next month is financial the day it is added, and
 * nothing has to be edited here for that to be true. It is the same mechanism
 * that makes {@see RolePermissionMatrix::platformPermissions()}
 * correct by construction.
 *
 * ⚠️ AND IT IS DERIVED OVER `Permissions::all()`, NOT OVER `tenantPermissions()`.
 * A platform permission reaching an assistant is exactly as bad as a tenant one —
 * worse, in fact — so the wall must not be narrower than the vocabulary.
 *
 * ⚠️ IN `Shared\Support` AND NOT IN `Community`, because the class that ASKS is
 * {@see User::hasPermissionTo()} — see the note there for why the
 * refusal could not live in a `Gate::before`. `Community` owns the assignment row
 * and binds {@see AssistantScopeDirectory}; the vocabulary
 * of what is financial belongs beside the model that has to answer.
 *
 * ⚠️ `billing.balance.view` IS THE ONE EXCLUSION (ت-١), and it is an exclusion
 * rather than a missing prefix on purpose. Credits and withheld state carry no
 * money (`StudentBalanceAllowlist` fails the build over a field that would), and
 * the 006 argument still holds: the assistant who schedules a session needs to
 * know who is able to book one. Phase 1 MOVED it from `$assistantTeacher` to
 * `$teacher` for that reason — the owner ticks it back onto a custom role,
 * deliberately, for a named person. Walling it here would make that grant
 * unreachable while leaving the tick box on the screen.
 */
final class AssistantForbiddenPermissions
{
    /** @var list<string> */
    private const PREFIXES = ['settlement.', 'billing.', 'payments.', 'orders.'];

    /** @var list<string> */
    private const ALLOWED = [Permissions::BILLING_BALANCE_VIEW];

    /** @var array<string, true>|null */
    private static ?array $memo = null;

    /**
     * A lookup map rather than a list: this is read inside a `Gate::before`, which
     * Laravel calls on EVERY ability check — an `in_array` over ~25 names, once
     * per row of a Filament table, is a scan nobody would write on purpose.
     *
     * @return array<string, true>
     */
    public static function map(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $forbidden = [];

        foreach (Permissions::all() as $permission) {
            if (in_array($permission, self::ALLOWED, true)) {
                continue;
            }

            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($permission, $prefix)) {
                    $forbidden[$permission] = true;

                    break;
                }
            }
        }

        return self::$memo = $forbidden;
    }

    public static function refuses(string $ability): bool
    {
        return isset(self::map()[$ability]);
    }
}
