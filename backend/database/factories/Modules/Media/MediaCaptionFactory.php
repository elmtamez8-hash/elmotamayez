<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Media;

use App\Modules\Media\Enums\CaptionKind;
use App\Modules\Media\Enums\CaptionSource;
use App\Modules\Media\Models\MediaCaption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaCaption> */
class MediaCaptionFactory extends Factory
{
    protected $model = MediaCaption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'language' => 'ar',
            'kind' => CaptionKind::Captions,
            'source' => CaptionSource::Manual,
            'storage_path' => 'captions/'.$this->faker->uuid().'.vtt',
            'is_default' => true,
        ];
    }
}
