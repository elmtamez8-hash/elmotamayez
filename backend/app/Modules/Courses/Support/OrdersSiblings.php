<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

/**
 * A tree node that knows which column identifies "the same parent".
 *
 * An interface rather than an abstract method on the trait alone: the boot hook
 * receives an `Eloquent\Model`, and narrowing it by `instanceof` is how the call
 * to `siblingScopeColumn()` becomes provably safe rather than assumed.
 */
interface OrdersSiblings
{
    public function siblingScopeColumn(): string;
}
