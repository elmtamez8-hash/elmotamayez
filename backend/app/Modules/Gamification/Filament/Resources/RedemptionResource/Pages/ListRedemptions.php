<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\RedemptionResource\Pages;

use App\Modules\Gamification\Filament\Resources\RedemptionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * بلا زرِّ إنشاء وبلا إجراءِ بتّ: الطلبُ يُنشأ من `RedeemReward` ويُبَتُّ من
 * `DecideRedemption`، وكلاهما جملةٌ شرطيّةٌ واحدةٌ لا استمارة.
 */
class ListRedemptions extends ListRecords
{
    protected static string $resource = RedemptionResource::class;
}
