<?php

declare(strict_types=1);

/*
 * The payment gateway's operational numbers (spec 007).
 *
 * NOT prices. Every figure a student or a teacher is charged lives in
 * `config/billing.php` and `platform_settings`; this file holds only what the
 * PROVIDER side of a payment needs — which provider answers, how long a pending
 * transaction may stay pending, how often a lost callback is retried, and which
 * callers a webhook is accepted from.
 *
 * ⚠️ NO SECRET IS EVER READ HERE OR STORED HERE (FR-010 · NFR-010). Credentials
 * and signing keys are environment variables read by the provider adapter that
 * needs them, and there is no adapter yet — the real gateway is deferred by the
 * owner's decision (Q8). A key defaulted to a string in this file would be a
 * secret in the repository, which is the thing FR-010 forbids.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | The id of the provider that answers when nothing names one.
    |
    | ⚠️ THE LIST OF PROVIDERS IS NOT HERE. Implementations register themselves
    | with one `->tag('payment.providers')` line in their module provider, on the
    | pattern `notification.channels` already uses, and PaymentProviderRegistry
    | resolves an id against that tag. A second list in config is a second place
    | to forget: a provider present in one and absent from the other resolves to
    | 404 for a caller holding a perfectly valid id.
    |
    | ⚠️ THE VALUE IS THE PROVIDER'S OWN `identifier()`, not its class name.
    | `manual_transfer` looked right and matched nothing: the registry answers
    | 404 for an unknown id, so every charge on the platform returned "no such
    | route" while the code, the config and the registry each looked correct on
    | their own.
    |
    | `manual` is the only implementation until a real gateway adapter lands
    | (Q8). It takes no credentials, which is why it can be the default.
    |
    */

    'default' => env('PAYMENTS_PROVIDER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Pending timeout (FR-015)
    |--------------------------------------------------------------------------
    |
    | How long a transaction may sit at `pending` before the sweep closes it in a
    | FINAL state. A payment with no end state is worse than a failed one: the
    | student's order never clears, nothing tells them why, and the row is
    | counted as neither collected nor lost by every report that follows.
    |
    | Longer than any real redirect flow (a bank page, a 3-D Secure step, an app
    | switch) and shorter than a working day, so a human sees the outcome on the
    | same day they paid.
    |
    */

    /*
    | ⚠️ `?:` وليس الافتراضيَّ وحدَه. `env()` يُرجِعُ **السلسلةَ الفارغةَ** لمفتاحٍ
    | معلَنٍ بلا قيمة (`KEY=`)، لا `null` — فالافتراضيُّ الثاني لا يُستعمَلُ أصلاً
    | و`(int) ''` يساوي **صفراً**. و`.env.example` يُعلِنُ هذين المفتاحَين فارغَين،
    | أي أنّ كلَّ نشرةٍ تُولَّدُ منه ترثُ صفراً بصمت.
    */
    'pending_timeout_minutes' => (int) (env('PAYMENTS_PENDING_TIMEOUT_MINUTES') ?: 120),

    /*
    |--------------------------------------------------------------------------
    | Deferred callback retries
    |--------------------------------------------------------------------------
    |
    | A callback that arrives before the transaction it refers to exists is
    | retried rather than dropped — a gateway may legitimately answer faster than
    | our own write commits.
    |
    | ⚠️ THE ATTEMPT COUNT IS A NUMBER, NOT A POLICY. A job with no declared
    | `tries` on a supervisor with no declared `tries` retries FOREVER, against a
    | reference that will never exist — and the provider was already answered
    | `202`, so nobody is waiting for the result to notice. Twelve attempts
    | across the backoff below span a little over eight hours, which covers a
    | provider outage without becoming a permanent background load.
    |
    | Eleven delays for twelve attempts: the first attempt is not delayed.
    |
    */

    'callback' => [
        /*
        | ⚠️ والصفرُ هنا ليس «بلا حدّ» — إنّه **هجرٌ فوريّ**: الشرطُ
        | `attempts >= tries()` صادقٌ من المحاولةِ الأولى، فكلُّ إشعارِ دفعٍ يسبقُ
        | كتابتَنا يُختَمُ `abandoned` ولا يُعادُ أبداً. دفعةٌ حقيقيّةٌ تُفقَدُ بصمت،
        | وسطرُ التحذيرِ الوحيدُ يقولُ «أُهمِل بعدَ ١ محاولة».
        */
        'max_attempts' => (int) (env('PAYMENTS_CALLBACK_MAX_ATTEMPTS') ?: 12),

        'backoff_seconds' => [10, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 14400],
    ],

    /*
    |--------------------------------------------------------------------------
    | Allocation order (FR-004, guardian edge)
    |--------------------------------------------------------------------------
    |
    | Which order one payment settles when the payer has several open — a parent
    | paying for three children.
    |
    | ⚠️ DECLARED HERE BECAUSE THE ALTERNATIVE IS A QUERY PLAN. Left to whatever
    | order the database returned, adding an index silently re-points the money,
    | and the child whose access is restored changes with it. Oldest first: the
    | debt that has been outstanding longest is the one already causing a block.
    |
    */

    'allocation_order' => 'oldest_first',

    /*
    |--------------------------------------------------------------------------
    | Webhook source addresses
    |--------------------------------------------------------------------------
    |
    | Comma-separated in the environment, empty by default.
    |
    | ⚠️ EMPTY MEANS "NOT CONFIGURED", NEVER "ALLOW EVERYTHING". The address list
    | is defence in depth beside the signature, not instead of it: an IP is
    | spoofable and a gateway's egress ranges change without notice, so a request
    | from an allowed address with a bad signature is still refused. It is here
    | because the environment is the only place a deployment-specific range can
    | live without becoming a code change (FR-010).
    |
    */

    'webhook_allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENTS_WEBHOOK_ALLOWED_IPS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Where a gateway returns the payer
    |--------------------------------------------------------------------------
    |
    | The origin of the STUDENT-FACING APP, which is not the API's own origin in
    | production. `PaymentReturnUrl` appends `/billing/pay/return?transaction=…`
    | to it and `InitiatePayment` hands the result to the provider.
    |
    | ⚠️ IT DEFAULTS TO `app.url` AND MUST BE SET SEPARATELY WHEN THEY DIFFER. In
    | development the browser reaches the API through Next's rewrite, so one host
    | serves both and the default is correct. Deployed, the API and the app are
    | two origins — and a return URL built from `app.url` lands the payer, holding
    | a gateway's redirect, on a JSON endpoint.
    |
    | ⚠️ AND IT IS AN ENV VAR RATHER THAN A `platform_settings` ROW. That rule is
    | about numbers an operator tunes from the panel; this is a deployment
    | address, and a row anybody with panel access could edit is a row that can
    | point a payer mid-payment at a host we do not control.
    |
    */

    'return_url_base' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

];
