<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Shared\Contracts\UnlockDirectory;
use Illuminate\Database\Eloquent\Model;

/**
 * Assessments' answer to "has this student earned the next session?".
 *
 * ⚠️ IT FORMATS THE RESOLVER'S VERDICT AND COMPUTES NOTHING OF ITS OWN. The
 * eligibility screen and the booking door must refuse for the same reason in the
 * same words; a second computation here is how a student reads one cause on the
 * page and hits another at the button.
 */
class EloquentUnlockDirectory implements UnlockDirectory
{
    public function __construct(
        private readonly UnlockResolver $resolver,
        private readonly UnlockReader $reader,
    ) {}

    /**
     * @param  iterable<Model>  $sessions
     */
    public function stamp(iterable $sessions, User $student): void
    {
        $this->reader->stamp(collect($sessions), $student);
    }

    public function refusalFor(User $student, int $classSessionId): ?string
    {
        return $this->refusalsFor($student, [$classSessionId])[$classSessionId] ?? null;
    }

    /**
     * @return array{open: bool, reason: string|null, missing: list<string>, rule_scope: string, exempt: bool}
     */
    public function explain(User $student, int $classSessionId): array
    {
        $verdict = $this->resolver->resolve($student, [$classSessionId])[$classSessionId] ?? UnlockVerdict::open();

        return [
            'open' => $verdict->open,
            'reason' => $verdict->reason,
            'missing' => $verdict->missing,
            'rule_scope' => $verdict->ruleScope,
            'exempt' => $verdict->exempt,
        ];
    }

    /**
     * @param  list<int>  $classSessionIds
     * @return array<int, string|null>
     */
    public function refusalsFor(User $student, array $classSessionIds): array
    {
        $out = [];

        foreach ($this->resolver->resolve($student, $classSessionIds) as $sessionId => $verdict) {
            $out[$sessionId] = $verdict->open ? null : $verdict->reason;
        }

        return $out;
    }
}
