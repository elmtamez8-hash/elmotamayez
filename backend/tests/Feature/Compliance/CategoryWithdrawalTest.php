<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\ConsentRegistry;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Withdrawing consent to an optional category stops it (SC-019 · FR-007).
 *
 * ⚠️ THIS REQUIREMENT HAD NOTHING BEHIND IT — no column, no Action, no route and
 * no test. The reason it went unnoticed is that `data_categories.is_required`
 * looks like the answer, and it is not: it describes the CATALOGUE, not any
 * person's choice.
 */
beforeEach(function (): void {
    $this->student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $this->version = app(ConsentRegistry::class)->currentVersion(ConsentDocument::DataProcessing);

    $this->optional = DataCategory::query()->where('is_required', false)->firstOrFail();
});

function putCategories(User $caller, array $payload): TestResponse
{
    Sanctum::actingAs($caller);
    test()->asGuest();

    return test()->putJson('/api/v1/privacy/consents/categories', $payload);
}

it('writes a new row rather than editing the old one', function (): void {
    putCategories($this->student, [
        'categories' => [$this->optional->key],
        'version' => $this->version,
    ])->assertOk();

    $this->travel(1)->seconds();

    putCategories($this->student, [
        'categories' => [],
        'version' => $this->version,
    ])->assertOk();

    /*
    | ⚠️ TWO ROWS, NOT ONE EDITED. Each is a signature at a moment; rewriting the
    | first would erase the evidence of what was actually agreed to, and the
    | history is what a dispute is settled from. Same argument as `LedgerEntry`.
    */
    $rows = TermsConsent::query()
        ->where('student_user_id', $this->student->getKey())
        ->orderBy('consented_at')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->categories)->toContain($this->optional->key)
        ->and($rows[1]->categories)->not->toContain($this->optional->key);
});

it('reports the narrower set as the one now in force', function (): void {
    $registry = app(ConsentRegistry::class);

    putCategories($this->student, [
        'categories' => [$this->optional->key],
        'version' => $this->version,
    ])->assertOk();

    expect($registry->consentedCategories($this->student, ConsentDocument::DataProcessing))
        ->toContain($this->optional->key);

    $this->travel(1)->seconds();

    putCategories($this->student, ['categories' => [], 'version' => $this->version])->assertOk();

    /*
    | ⚠️ THE LATEST ROW WINS, IT IS NOT A UNION. Merging across rows would make
    | withdrawal impossible by construction — everything ever accepted would stay
    | accepted for ever, and the endpoint would report success while changing
    | nothing.
    */
    expect($registry->consentedCategories($this->student, ConsentDocument::DataProcessing))
        ->not->toContain($this->optional->key);
});

/*
 * A required category cannot be withdrawn, and the answer is not a refusal.
 *
 * ⚠️ ADDED BACK RATHER THAN VALIDATED AWAY. Rejecting a payload that omits a
 * required key would let a stale screen block a legitimate withdrawal of
 * something else — and the person would see an error about a category they never
 * touched.
 */
it('keeps every required category whatever the client sends', function (): void {
    $required = DataCategory::query()->where('is_required', true)->pluck('key')->all();

    expect($required)->not->toBeEmpty();

    $response = putCategories($this->student, ['categories' => [], 'version' => $this->version])->assertOk();

    foreach ($required as $key) {
        expect($response->json('categories'))->toContain($key);
    }
});

/*
 * ⚠️ AN EMPTY ARRAY IS A MEANINGFUL ANSWER AND MUST NOT BE `required`.
 *
 * Laravel's `required` rejects `[]`, which would make "I consent to none of the
 * optional categories" the one choice the endpoint cannot express — the exact
 * choice the right exists for. The rule is `present`.
 */
it('accepts an empty selection', function (): void {
    putCategories($this->student, ['categories' => [], 'version' => $this->version])->assertOk();
});

it('refuses a submission against superseded policy text', function (): void {
    putCategories($this->student, [
        'categories' => [],
        'version' => 'a-version-that-is-not-current',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'policy_version_changed');
});

/*
 * A stranger cannot narrow someone else's consent, and the refusal reveals
 * nothing: "no such student" and "not yours" answer identically, so the endpoint
 * cannot be used to confirm that an identifier belongs to a real account.
 */
it('refuses a caller with no authority over the named student', function (): void {
    $other = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $stranger = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    putCategories($stranger, [
        'student_uuid' => $other->uuid,
        'categories' => [],
        'version' => $this->version,
    ])->assertForbidden();

    putCategories($stranger, [
        'student_uuid' => (string) Str::uuid(),
        'categories' => [],
        'version' => $this->version,
    ])->assertForbidden();
});
