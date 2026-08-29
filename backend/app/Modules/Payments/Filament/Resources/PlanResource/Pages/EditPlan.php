<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\PlanResource\Pages;

use App\Modules\Payments\Actions\SetPlanPrice;
use App\Modules\Payments\Filament\Resources\PlanResource;
use App\Modules\Payments\Models\Plan;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * ⚠️ THE SAVE GOES THROUGH THE ACTION, AND WITHOUT THIS OVERRIDE IT WOULD
     * SILENTLY DO NOTHING AT ALL.
     *
     * Filament's default `handleRecordUpdate()` is `$record->update($data)` —
     * mass assignment — and `price_minor` is deliberately NOT `$fillable`,
     * because it is the platform's half of a row two actors write and a
     * mass-assignable price is one extra key in a request body away from a
     * teacher setting it. Mass assignment DISCARDS a non-fillable key in
     * silence: no exception, no log, a green «تم الحفظ» toast and a column that
     * never moved. Three of those shipped on `student_profiles` in spec 013 and
     * every assertion about them passed, because the response echoes what was
     * submitted rather than what was stored.
     *
     * Routing it through `SetPlanPrice` also gets the activity-log entry, so
     * «who put this number on this teacher's plan» has an answer.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Plan $record */
        $raw = $data['price_minor'] ?? null;

        return app(SetPlanPrice::class)->handle(
            $record,
            $raw === null || $raw === '' ? null : (int) $raw,
        );
    }

    /**
     * No DeleteAction: `subscriptions.plan_id` points at this row, and a
     * student's own subscription must keep naming what they bought.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
