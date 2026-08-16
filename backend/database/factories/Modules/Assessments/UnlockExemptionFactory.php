<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Models\User;
use App\Modules\Assessments\Models\UnlockExemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnlockExemption>
 */
class UnlockExemptionFactory extends Factory
{
    protected $model = UnlockExemption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            // No ClassSession::factory() default: an exemption on no session is
            // an exemption from nothing. The caller supplies it.
            'class_session_id' => null,
            'student_user_id' => User::factory(),
            'reason' => 'ظرفٌ عائلي.',
            'granted_by' => User::factory(),
        ];
    }
}
