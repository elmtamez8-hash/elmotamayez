<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\PlanResource\Pages;

use App\Modules\Payments\Actions\SetPlanPrice;
use App\Modules\Payments\Filament\Resources\PlanResource;
use App\Modules\Payments\Models\Plan;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * 036 . T072 -- THE DECISION: THIS SCREEN PRICES A PLAN AND EDITS NOTHING ELSE.
 *
 * The teacher's own fields stay `Placeholder`s on {@see PlanResource::form()} --
 * shown so the officer can see WHAT they are putting a number on, never edited
 * here -- and that is deliberate rather than unfinished. 036 asked whether the
 * new shape fields belong on this page; they do not, and the reason is what this
 * page already is.
 *
 * ⛔ AND A FIELD ADDED HERE WOULD BE IGNORED IN SILENCE, WHICH IS WHY THE
 * QUESTION HAD TO BE ANSWERED RATHER THAN LEFT. This page declares no `form()`
 * of its own, and `handleRecordUpdate()` below passes `price_minor` ALONE to the
 * Action: any other key in `$data` is dropped on the floor, the toast says «تم
 * الحفظ», and the column never moves. An officer who switched a plan to «حصص»
 * here would be told it worked.
 *
 * The shape is the TEACHER's to write (FR-025), from their own screen or from
 * the create-on-their-behalf door, both of which go through `SavePlan` where the
 * one-shape rule lives. Two write paths for one column is how they disagree.
 */
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
