<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Media;

use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaAsset> */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_type' => Lesson::class,
            'owner_id' => 1,
            'provider' => 'local',
            'provider_asset_id' => 'media/'.$this->faker->uuid().'.mp4',
            'kind' => MediaKind::Video,
            'role' => MediaRole::Primary,
            'status' => MediaAssetStatus::Ready,
            'original_filename' => 'lesson.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 10_485_760,
            'duration_seconds' => 600,
            'ready_at' => now(),
        ];
    }

    public function processing(): self
    {
        return $this->state(fn (): array => [
            'status' => MediaAssetStatus::Processing,
            'ready_at' => null,
        ]);
    }

    public function failed(string $reason = 'فشل الرفع.'): self
    {
        return $this->state(fn (): array => [
            'status' => MediaAssetStatus::Failed,
            'failure_reason' => $reason,
            'ready_at' => null,
        ]);
    }
}
