<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\RewardResource\Pages;

use App\Modules\Gamification\Filament\Resources\RewardResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * صفحةُ القراءة، وهي ما يعلّق عليه مديرُ العلاقة: طلباتُ الاستبدال لا تُقرأ إلّا
 * بجانب المكافأةِ التي تخصم مخزونَها.
 */
class ViewReward extends ViewRecord
{
    protected static string $resource = RewardResource::class;
}
