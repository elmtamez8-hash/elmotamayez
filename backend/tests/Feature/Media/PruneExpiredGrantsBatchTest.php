<?php

declare(strict_types=1);

use App\Modules\Media\Jobs\PruneExpiredGrantsJob;
use App\Modules\Media\Models\PlaybackGrant;

/*
| The prune deletes in batches and keeps going until nothing old is left.
|
| The batch size is lowered to 2 over five old rows, so the job can only finish
| the pile by looping: a job that ran one limited DELETE and stopped would leave
| three rows behind, and one with no limit at all is not what the class says.
*/

function pruneBatchGrant(string $expiresAt): PlaybackGrant
{
    return PlaybackGrant::factory()->create([
        'workspace_id' => 1,
        'media_asset_id' => 1,
        'user_id' => 1,
        'auth_session_id' => 1,
        'expires_at' => $expiresAt,
    ]);
}

it('deletes every grant past the week of grace, across several batches, and keeps the rest', function (): void {
    foreach (range(1, 5) as $i) {
        pruneBatchGrant(now()->subDays(8 + $i)->toDateTimeString());
    }

    $recent = pruneBatchGrant(now()->subDays(6)->toDateTimeString());
    $live = pruneBatchGrant(now()->addMinutes(5)->toDateTimeString());

    (new PruneExpiredGrantsJob(batchSize: 2))->handle();

    expect(PlaybackGrant::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$recent->getKey(), $live->getKey()])->sort()->values()->all());
});
