<?php

declare(strict_types=1);

use App\Shared\Models\ActivityEntry;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

/*
|--------------------------------------------------------------------------
| Activity log
|--------------------------------------------------------------------------
|
| Published for ONE key — `activity_model` — and everything else below is
| spatie's own default, copied so that publishing this file changes nothing
| except the thing it was published to change. A partial config file is worse
| than none: the package merges nothing, so a missing key here becomes a silent
| null where the package expected a value.
|
| ⚠️ THE MODEL IS SWAPPED PRODUCT-WIDE, WHICH IS THE POINT AND ALSO THE RISK.
| Every `activity()` call in the codebase now writes through
| {@see ActivityEntry}, whose only addition is refusing to be updated or deleted
| (FR-027). That reaches twenty-three Actions in seven modules, including spec
| 014's settlement audit — the guard belongs on the row rather than in the shared
| `LogsActivity` trait precisely because of that breadth: a helper can be
| declined by the next writer, a model cannot.
|
| ⚠️ AND `clean_after_days` IS LEFT AT SPATIE'S DEFAULT AND UNSCHEDULED. Nothing
| in this application runs `activitylog:clean`, and if anything ever does it will
| meet the delete guard and fail loudly — which is the correct order of events
| for a financial trail: a retention policy is a decision somebody takes, not a
| default that quietly removes evidence.
|
*/

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * When the clean command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     */
    'clean_after_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    /*
     * This model will be used to log activity. It refuses to be updated or
     * deleted — the one reason this file exists.
     */
    'activity_model' => ActivityEntry::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     */
    'default_except_attributes' => [],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     *
     * ⚠️ LEFT OFF DELIBERATELY. A buffered entry has no id until the buffer is
     * flushed, and a financial decision recorded "after the response was sent"
     * is a decision that is not recorded if the process dies between the two.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
