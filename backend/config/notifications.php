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
