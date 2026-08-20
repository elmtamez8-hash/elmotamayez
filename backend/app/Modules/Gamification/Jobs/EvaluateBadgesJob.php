<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Jobs;

use App\Models\User;
use App\Modules\Gamification\Actions\EvaluateBadges;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Badge evaluation, off the path of whatever triggered it (FR-018 · SC-016).
 *
 * Rules count rows in the ledger, so running them inline would add queries to
 * every single award — which is the cost SC-016 measures.
 *
 * ⚠️ IT TAKES AN ID, NOT A MODEL. `SerializesModels` would re-fetch the user on
 * every retry, and a job whose payload is a whole User is a job that fails to
 * deserialize if the account is removed between dispatch and run.
 */
class EvaluateBadgesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $studentUserId) {}

    public function handle(EvaluateBadges $evaluate): void
    {
        $student = User::query()->find($this->studentUserId);

        if ($student === null) {
            return;
        }

        $evaluate->handle($student);
    }
}
