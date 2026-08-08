<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Gives a new tree node the next free position among its siblings.
 *
 * Every `order` column in the tree defaults to 0, and until now nothing filled
 * it deliberately — so sibling order was undefined, which matters because
 * `Enrollment::canAccessLesson()` decides what a student may open from exactly
 * those numbers. A unique index now forbids the tie; this fills the value so
 * that forbidding it does not simply break every create.
 *
 * A boot hook rather than an Action, for the same reason `HasUuid` is one: it is
 * an identity concern, not a business rule. Seeders, factories, Filament and the
 * recording publisher all create nodes, and a rule any of them can skip is a
 * rule that produces the duplicate the index will reject at the worst moment.
 * Choosing a NEW order is what the Actions own; this only answers "where does an
 * unspecified one go", and the answer is always: last.
 *
 * @phpstan-require-implements OrdersSiblings
 */
trait HasSiblingOrder
{
    public static function bootHasSiblingOrder(): void
    {
        static::creating(function (Model $model): void {
            if (! $model instanceof OrdersSiblings) {
                return;
            }

            // Only fills a gap; never overrides. A caller that named a position
            // meant it, and silently moving it would leave the reorder Action
            // unable to place anything.
            if ($model->getAttribute('order') !== null) {
                return;
            }

            $column = $model->siblingScopeColumn();
            $parentId = $model->getAttribute($column);

            if ($parentId === null) {
                return;
            }

            $highest = static::query()
                ->withoutGlobalScopes()
                ->where($column, $parentId)
                ->max('order');

            $model->setAttribute('order', $highest === null ? 0 : ((int) $highest) + 1);
        });
    }
}
