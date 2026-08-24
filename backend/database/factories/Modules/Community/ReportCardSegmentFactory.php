<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Models\User;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportCardSegment> */
class ReportCardSegmentFactory extends Factory
{
    protected $model = ReportCardSegment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'report_card_id' => ReportCard::factory(),
            'teacher_user_id' => User::factory(),
            'student_user_id' => User::factory(),
            'components' => [
                'exams' => ['pct' => 80.0, 'weight' => 50.0],
                'attendance' => ['pct' => 90.0, 'weight' => 50.0],
            ],
            'attendance_pct' => 90.0,
            'segment_pct' => 85.0,
        ];
    }
}
