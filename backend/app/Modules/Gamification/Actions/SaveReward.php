<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Data\RewardData;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Gamification\Support\GamificationSettings;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Define or edit a shop item (FR-029 … FR-031).
 */
class SaveReward extends Action
{
    public function __construct(private readonly GamificationSettings $settings) {}

    public function handle(RewardData $data, int $workspaceId, ?Reward $reward = null): Reward
    {
        /*
        | ⚠️ ENFORCED HERE AND NOT IN THE FormRequest ALONE (FR-031 · SC-012). A
        | money-valued reward with no monthly cap turns gamification from an
        | engagement budget into an open marketing expense — the fourth of the four
        | design controls the source document says cannot be skipped. The seeder,
        | the panel and any future importer reach this Action with no form behind
        | them, and a rule that lives only in a form is a rule with three ways
        | round it.
        */
        if ($data->type->isMoneyValued() && $data->monthlyCap === null) {
            throw new DomainException('المكافأة ذات القيمة النقدية يجب أن يكون لها سقف شهري.');
        }

        /*
        | ⚠️ AND THE CEILING ON THAT CAP IS THE PLATFORM'S, not the teacher's. A
        | limit a teacher sets for themselves is not a control; this is what makes
        | the cap mean something.
        */
        if ($data->monthlyCap !== null && $data->monthlyCap > $this->settings->maxMonthlyCap()) {
            throw new DomainException('السقف الشهري أعلى مما تسمح به المنصّة.');
        }

        $attributes = [
            'title' => $data->title,
            'price_coins' => $data->priceCoins,
            'stock' => $data->stock,
            'type' => $data->type,
            'monthly_cap' => $data->monthlyCap,
            'is_active' => $data->isActive,
        ];

        if ($reward !== null) {
            $reward->fill($attributes)->save();

            return $reward->refresh();
        }

        return Reward::query()->create([...$attributes, 'workspace_id' => $workspaceId]);
    }
}
