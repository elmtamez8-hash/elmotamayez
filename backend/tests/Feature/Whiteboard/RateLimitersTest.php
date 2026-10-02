<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/*
| The whiteboard's two limiters exist and count PER USER. A school authoring from
| one address is many teachers, so an ip key would throttle a whole staff room
| the moment one of them opened a 300-page board.
*/

it('registers the whiteboard limiters, keyed by user', function (string $name): void {
    $limiter = RateLimiter::limiter($name);
    expect($limiter)->not->toBeNull();

    $teacher = User::factory()->create();
    $request = Request::create('/api/v1/boards');
    $request->setUserResolver(fn () => $teacher);

    /** @var Limit $limit */
    $limit = $limiter($request);

    expect($limit->key)->toBe('user:'.$teacher->getKey())
        ->and($limit->maxAttempts)->toBeGreaterThanOrEqual(240);
})->with(['whiteboard-autosave', 'whiteboard-files']);
