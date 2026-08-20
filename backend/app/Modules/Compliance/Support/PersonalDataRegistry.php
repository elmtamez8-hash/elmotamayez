<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Shared\Contracts\PersonalDataOwner;
use Illuminate\Contracts\Container\Container;

/**
 * Every module that declared itself an owner of personal data.
 *
 * ⚠️ THE TAG IS THE WHOLE COUPLING. `Compliance` never names a module, a model or
 * a table belonging to anyone else; it resolves `compliance.personal_data` and
 * walks whatever registered itself — the same shape as 003's
 * `notification.channels`, and the reason Constitution III survives a requirement
 * that touches thirteen schemas.
 *
 * ⚠️ AND THE ORDER IS FIXED, WHICH IS NOT COSMETIC. The container returns tagged
 * bindings in registration order, and module providers are auto-discovered by a
 * directory scan — so the order can differ between two machines, or after any file
 * is added. An archive whose files come out in a different order each time makes
 * every automated comparison of two exports pure noise, and `SC-010`'s
 * "two runs produce the same state" unmeasurable.
 */
final class PersonalDataRegistry
{
    /** @var list<PersonalDataOwner>|null */
    private ?array $owners = null;

    public function __construct(private readonly Container $container) {}

    /** @return list<PersonalDataOwner> */
    public function all(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }

        /** @var list<PersonalDataOwner> $owners */
        $owners = array_values(array_filter(
            iterator_to_array($this->container->tagged('compliance.personal_data'), false),
            fn (mixed $owner): bool => $owner instanceof PersonalDataOwner,
        ));

        usort($owners, fn (PersonalDataOwner $a, PersonalDataOwner $b): int => strcmp($a->moduleKey(), $b->moduleKey()));

        return $this->owners = $owners;
    }

    /** @return list<string> the module keys, sorted */
    public function moduleKeys(): array
    {
        return array_map(fn (PersonalDataOwner $owner): string => $owner->moduleKey(), $this->all());
    }

    /**
     * The one owner that declared this category, or null.
     *
     * Null rather than an exception: a category row whose module has been removed
     * is a stale catalogue entry, and the sweep must skip it and carry on rather
     * than die and leave every later category unprocessed.
     */
    public function forCategory(string $category): ?PersonalDataOwner
    {
        foreach ($this->all() as $owner) {
            if (in_array($category, $owner->describe(), true)) {
                return $owner;
            }
        }

        return null;
    }
}
