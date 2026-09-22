<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuthSession> */
class AuthSessionFactory extends Factory
{
    protected $model = AuthSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),

            /*
            | ⛔ THE DEVICE IS DERIVED FROM THE USER, NEVER A FACTORY OF ITS OWN.
            |
            | Both keys used to be independent factories — the ChapterFactory shape
            | this repository has already paid for: one person's session pointing at
            | another person's machine. Spec 038's legal-hold case is where it bites
            | hardest: the fixture exempts the session's owner, the sweep anonymises
            | a device belonging to somebody else entirely, and the assertion passes
            | green over the exact defect it was written to catch.
            |
            | ⚠️ AND IT IS NAMED *BELOW* `user_id` ON PURPOSE. `expandAttributes()`
            | walks the definition in order and hands each closure only what it has
            | already resolved, so a derived key above its parent receives a Factory
            | instance rather than an id.
            */
            'device_id' => fn (array $attributes): Device => Device::factory()->create([
                'user_id' => $attributes['user_id'],
            ]),

            'status' => AuthSession::STATUS_ACTIVE,

            /*
            | ⛔ WITHOUT THIS COLUMN EVERY FIXTURE ROW IS BORN ALREADY ANONYMISED.
            |
            | `ip_hash IS NULL` is spec 038's marker for "this row has been swept",
            | so a factory that never writes it makes every retention assertion above
            | it green against a build with no arm in it at all.
            |
            | ⚠️ 64 characters, which is `hash('sha256', …)` exactly and the column's
            | full width (`string('ip_hash', 64)`). A longer literal is ERROR 1406 on
            | MySQL's strict mode and a silent truncation on SQLite.
            */
            'ip_hash' => hash('sha256', (string) Str::uuid()),

            'last_active_at' => now(),
        ];
    }

    public function ended(): self
    {
        return $this->state(fn (): array => [
            'status' => AuthSession::STATUS_ENDED,
            'ended_at' => now(),
        ]);
    }
}
