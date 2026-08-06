<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Settlement;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerEntry> */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'teacher_profile_id' => TeacherProfile::factory(),
            'type' => LedgerEntryType::Unit,
            'amount_minor' => 5000,
            'currency' => 'QAR',
        ];
    }

    public function deduction(int $minor): static
    {
        return $this->state(fn (): array => [
            'type' => LedgerEntryType::Deduction,
            // Negative: the enum declares this type must be, and a factory that
            // produced a positive deduction would make every total it feeds wrong
            // in a way the assertions would then enshrine.
            'amount_minor' => -abs($minor),
            'reason' => 'خصم تجريبي',
        ]);
    }
}
