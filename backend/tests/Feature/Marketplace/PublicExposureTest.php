<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Marketplace\Support\PublicFieldAllowlist;

/**
 * The guard test for the public marketplace.
 *
 * WorkspaceScope adds no condition when there is no authenticated user, so on a
 * guest request tenant isolation is simply absent. publiclyListed() stands in its
 * place. If this file fails, the marketplace is leaking across workspaces — stop
 * and fix that before anything else.
 */
/**
 * Every string key in a nested payload, flattened.
 *
 * @return list<string>
 */
function marketplacePayloadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...marketplacePayloadKeys($child)];
    }

    return $keys;
}

it('lists teachers from several participating workspaces in one feed', function () {
    foreach (['Academy A', 'Academy B', 'Academy C'] as $name) {
        marketplaceTeacher(marketplaceWorkspace($name));
    }

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3);
});

it('hides teachers whose workspace has not opted into the marketplace', function () {
    marketplaceTeacher(marketplaceWorkspace('Opted In'));
    $hidden = marketplaceTeacher(marketplaceWorkspace('Opted Out', participates: false));

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);

    $this->getJson("/api/v1/marketplace/teachers/{$hidden->uuid}")->assertNotFound();
});

it('hides teachers that are not approved', function (string $status) {
    $workspace = marketplaceWorkspace('Academy');

    // is_publicly_listed is deliberately left true: approval state must gate on its
    // own, so a stale flag cannot publish a suspended teacher.
    $teacher = marketplaceTeacher($workspace, ['approval_status' => $status]);

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0);
    $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")->assertNotFound();
})->with([
    TeacherProfile::STATUS_PENDING,
    TeacherProfile::STATUS_REJECTED,
    TeacherProfile::STATUS_SUSPENDED,
]);

it('never exposes private fields in any public payload', function () {
    $teacher = marketplaceTeacher(marketplaceWorkspace('Academy'));

    $this->asGuest();

    $payloads = [
        'teacher list' => $this->getJson('/api/v1/marketplace/teachers')->json(),
        'teacher detail' => $this->getJson("/api/v1/marketplace/teachers/{$teacher->uuid}")->json(),
        'course list' => $this->getJson('/api/v1/marketplace/courses')->json(),
        'home' => $this->getJson('/api/v1/marketplace/home')->json(),
        'subjects' => $this->getJson('/api/v1/marketplace/subjects')->json(),
        'stats' => $this->getJson('/api/v1/marketplace/stats')->json(),
    ];

    foreach ($payloads as $label => $payload) {
        foreach (marketplacePayloadKeys($payload) as $key) {
            expect(PublicFieldAllowlist::FORBIDDEN)->not->toContain(
                $key,
                "the {$label} payload exposed the forbidden key '{$key}'",
            );
        }
    }
});

it('drops every listing when a workspace withdraws from the marketplace', function () {
    $workspace = marketplaceWorkspace('Academy');
    marketplaceTeacher($workspace);

    $this->asGuest();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(1);

    $workspace->forceFill(['participates_in_marketplace' => false])->save();
    // The column write here stands in for SetMarketplaceParticipation (US4), which
    // owns this flush in production. Without it the withdrawal would still take
    // effect, but only once the 60s TTL expired.
    MarketplaceCache::flush();

    expect($this->getJson('/api/v1/marketplace/teachers')->json('meta.total'))->toBe(0);
});

it('returns an empty list rather than a 404 when filters match nothing', function () {
    marketplaceTeacher(marketplaceWorkspace('Academy'));

    $this->asGuest();

    $response = $this->getJson('/api/v1/marketplace/teachers?subject=nonexistent-subject');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
});
