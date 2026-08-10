<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Models\User;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\ConsentRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermsConsent>
 */
class TermsConsentFactory extends Factory
{
    protected $model = TermsConsent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Defaults to the student signing for themselves. The guardian case
            // is the one that needs proving, so it is a state rather than the
            // default — a factory whose default is the harder path makes every
            // test exercise it by accident.
            //
            // Read back from the resolved attribute, never by reusing one
            // Factory instance for both keys: each attribute is expanded on its
            // own, so a shared instance creates TWO users and the default would
            // silently be the guardian case it claims not to be.
            'student_user_id' => fn (array $attributes): int => (int) $attributes['user_id'],
            'document' => ConsentDocument::DeferredPaymentTerms->value,

            // The version in force, resolved rather than written: a literal here
            // would go stale the first time the terms were republished, and every
            // fixture would then be an acceptance of superseded text — which the
            // readers correctly ignore, so the suite would fail everywhere except
            // where it should.
            'version' => fn (array $attributes): string => app(ConsentRegistry::class)->currentVersion(
                ConsentDocument::tryFrom((string) $attributes['document']) ?? ConsentDocument::DeferredPaymentTerms,
            ),
            'ip_address' => '203.0.113.10',
            'user_agent' => 'PHPUnit',
            'consented_at' => now(),
        ];
    }
}
