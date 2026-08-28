<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\RewardResource\Pages;

use App\Modules\Gamification\Filament\Resources\RewardResource;
use Filament\Resources\Pages\ListRecords;

/**
 * بلا زرِّ إنشاء: المكافأةُ تُكتب من `SaveReward` وحدَه — هو ما يُلزِم السقفَ
 * الشهريَّ على الأنواعِ ذاتِ الكلفةِ الماليّة (FR-031).
 */
class ListRewards extends ListRecords
{
    protected static string $resource = RewardResource::class;
}
