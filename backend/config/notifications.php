<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long a read notification survives before PruneOldNotificationsJob
    | removes it (FR-017). Unread notifications are never pruned: an unread row
    | is something the user has not seen yet, and deleting it turns a delivered
    | message into one that silently never arrived.
    |
    */

    'retention_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Delivery retries
    |--------------------------------------------------------------------------
    |
    | Applies to transient failures only. A PermanentDeliveryException is never
    | retried (FR-009) — an invalid phone number would just burn five attempts
    | and the provider's rate budget with it.
    |
    */

    'max_attempts' => 5,

    'backoff_seconds' => [30, 120, 300, 900],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Mandatory types (security, financial) go to their own queue so they do not
    | wait behind a bulk reminder run.
    |
    */

    'queues' => [
        'default' => 'notifications',
        'mandatory' => 'notifications-high',
    ],

    /*
    |--------------------------------------------------------------------------
    | Quiet hours
    |--------------------------------------------------------------------------
    |
    | Timezone used when a user has not chosen one. Quiet hours apply to external
    | channels only (FR-032): an in-app notification wakes nobody.
    |
    */

    'default_timezone' => env('NOTIFICATIONS_DEFAULT_TIMEZONE', 'Asia/Qatar'),

    /*
    |--------------------------------------------------------------------------
    | Contact verification
    |--------------------------------------------------------------------------
    */

    'verification' => [
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],

];
