<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention — MOVED, and deliberately not replaced by a value here
    |--------------------------------------------------------------------------
    |
    | ⚠️ `retention_days` LIVED HERE AND WAS A SECOND OWNER OF A DURATION THE
    | CATALOGUE ALREADY HELD (spec 013 · FR-031أ). Two owners of one number means
    | what an operator shortens from the panel is not what actually deletes — and
    | they had already diverged: this file said 90 days while
    | `data_categories.notification_record` says 180, so the sweep an operator was
    | reading about ran at twice the speed they were told.
    |
    | It is now `data_categories.retain_days` for the `notification_record` row,
    | swept by `RunRetentionSweepJob` through
    | `NotificationsPersonalData::expire()`, which carries the `read_at` condition
    | that used to live in `PruneOldNotificationsJob` — an unread row is a message
    | its recipient never saw, and deleting it turns a delivered notification into
    | one that silently never arrived.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Default country code
    |--------------------------------------------------------------------------
    |
    | Used to read a locally-written phone number as an international one.
    |
    | ⚠️ HERE AND NOT UNDER `whatsapp` BELOW, AND A GUARD IS WHY. The action that
    | normalises a number lives in Actions/, where ProviderAgnosticTest forbids
    | naming a channel at all — so `config('notifications.whatsapp.…')` fails the
    | build, by design. It is also the truer home: the market's dialling code is a
    | property of the platform, and SMS will read the same value.
    |
    */

    'default_country_code' => (string) env('NOTIFICATIONS_DEFAULT_COUNTRY_CODE', '974'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (spec 020)
    |--------------------------------------------------------------------------
    |
    | ⚠️ THE AUTH HEADER IS A FULL NAME AND A FULL VALUE, NOT A BRANCH ON A
    | PROVIDER. Our BSP authenticates with `D360-API-KEY: <key>`; Meta's own
    | Cloud API, whose request body is byte-identical, wants
    | `Authorization: Bearer <token>`. Both are two env values, so moving between
    | them is a deployment change and not a code change — and the day a second
    | provider needed an `if` here would be the day a third one needed two.
    |
    | Everything here is env, and none of it is a platform_settings row: that
    | table is readable by anyone who can open the admin panel, so a sending key
    | there widens who can message a parent from "whoever runs the server" to
    | "whoever runs a workspace". The same line 019 drew for the signing key.
    |
    | Unset credentials are a state of the DEPLOYMENT, not an incident: the
    | channel answers isEnabled() false, every delivery is recorded `skipped`,
    | and nothing fails.
    |
    */

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://waba-v2.360dialog.io'),
        'auth_header' => env('WHATSAPP_AUTH_HEADER', 'D360-API-KEY'),
        'api_key' => env('WHATSAPP_API_KEY'),
        'timeout_seconds' => (int) env('WHATSAPP_TIMEOUT_SECONDS', 15),

        // The product is Arabic-only (spec 002), so the approved templates are
        // Arabic. Configurable rather than constant because the template's
        // language is a property of what Meta approved, not of our code.
        'language' => env('WHATSAPP_LANGUAGE', 'ar'),
    ],

];
