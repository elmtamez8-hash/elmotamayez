<?php

declare(strict_types=1);

use App\Modules\Community\Support\CommunitySettings;
use App\Modules\Compliance\Support\ComplianceSettings;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Support\PublicFieldAllowlist;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Store\Support\StoreSettings;
use App\Modules\Tenancy\Support\PlatformSettings;
use Filament\Facades\Filament;

/*
| The product's own name, read at run time.
|
| ⚠️ THE DEFECT THIS REPLACES SHIPPED AND SAT FOR MONTHS, and nothing failed while
| it did. The name lived in `NEXT_PUBLIC_PLATFORM_NAME`, which Next inlines at
| BUILD time; it was set nowhere on the server, so `lib/platform.ts` fell through
| to the placeholder «منصّتي» and every `<title>`, every Open Graph tag and the
| footer of the live site spelled a name that is not the product's. Measured on
| 2026-08-31: «منصّتي — مدرّسون خصوصيون بالعربية».
|
| A row plus this endpoint is what makes it changeable without a deploy — and the
| fallback below is what makes a database with nothing seeded still look like the
| product rather than like an unfinished one.
*/

it('answers the configured name without any authentication', function (): void {
    /*
    | ⚠️ NO `actingAs`, AND THAT IS THE ASSERTION. The name is on the LOGIN page,
    | in the `<title>` of every public page and in the web manifest — all read
    | before anybody has an account. Behind `auth:sanctum` the sign-in screen
    | could not spell the product it signs you in to.
    */
    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.name', 'المتميز');
});

it('falls back to the real name and never to a placeholder', function (): void {
    /*
    | ⚠️ THE FALLBACK IS THE PRODUCT'S NAME, NOT «منصّتي». A fallback that reads
    | like a placeholder is a fallback nobody notices is in use — which is the
    | entire history of this value. With no row at all the answer must still be
    | the name, so a fresh database looks correct rather than merely defaulted.
    */
    expect(config('platform.name'))->toBe('المتميز')
        ->and(PlatformSettings::get('platform.name'))->toBe('المتميز');
});

it('reflects an operator edit without a deploy, and busts the cache', function (): void {
    // The whole point of the move: this is what «من إعدادات المنصة» means.
    PlatformSettings::set('platform.name', 'اسم جديد');

    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.name', 'اسم جديد');

    /*
    | ⚠️ THE SECOND READ IS THE ONE THAT MATTERS. `PlatformSettings::get()` is
    | `Cache::rememberForever`, so an edit that did not forget the key would be
    | invisible until the cache was cleared by hand — the feature would look
    | implemented and change nothing.
    */
    expect(PlatformSettings::get('platform.name'))->toBe('اسم جديد');
});

it('sends the name and nothing else from the settings table', function (): void {
    /*
    | ⚠️ AN ALLOWLIST OF ONE FIELD. `platform_settings` holds the device limit, the
    | grant TTL, the operating fee and the gateway's basis points — a «settings»
    | endpoint that answered with `PlatformSettings::all()` would put the
    | platform's half of the price on a public URL, and every key added to that
    | table afterwards would join it silently.
    |
    | Asserted with `toBe()` on the exact key set: a test that only checked `name`
    | is PRESENT passes against a payload carrying all forty of them.
    */
    $payload = $this->getJson('/api/v1/platform')->assertOk()->json('data');

    expect(array_keys($payload))->toBe(PublicFieldAllowlist::PLATFORM_IDENTITY)
        // ⚠️ Widened on purpose (2026-09-26, owner decision): the legal pages'
        // controller identity. Any further field is a deliberate edit here too.
        // ⚠️ And again on 2026-09-27: the two freeze limits /terms states.
        ->and(PublicFieldAllowlist::PLATFORM_IDENTITY)->toBe([
            'name',
            'support_whatsapp',
            'legal_name',
            'postal_address',
            'contact_email',
            'freeze_max_days',
            'freeze_max_per_month',
            // ⚠️ And the rest of the numbers /terms and /refunds state
            // (2026-09-27, the pre-launch audit) — the reason is on the constant.
            'two_factor_grace_days',
            'max_unredeemed_credits',
            'stop_selling_after_days',
            'dormant_notice_months',
            'receipt_review_sla_hours',
            'deferred_initial_credits',
            'deferred_increase_after_on_time',
            'deferred_increase_by_credits',
            'deferred_max_credits',
            'deferred_reset_after_late_days',
            'cancellation_window_minutes',
            'attendance_required_stay_percent',
            'attendance_edit_window_hours',
            'renewal_notice_days',
            'chat_max_messages_per_minute',
            'review_min_sessions',
            'review_period_days',
            'offboarding_notice_days',
            'store_refund_window_hours',
        ]);
});

/*
| ⛔ /terms قالت «٣٠ يوماً» و«فترتَي تجميد» نصّاً مكتوباً في الصفحة، والرقمانِ
| صفّانِ في `platform_settings` يعدّلُهما المشغّلُ من اللوحة. فيومَ يتغيّرُ أحدُهما
| يرفضُ `CreateFreezePeriod` عندَ الرقمِ الجديد والصفحةُ تَعِدُ بالقديم.
|
| ⚠️ والمقارنةُ بـ`SessionSettings` نفسِه لا بثابت: المتحكّمُ يقرأُ الصفَّينِ
| بنفسِه (الاستيرادُ العكسيُّ حلقةٌ بين الوحدتين)، فقراءتانِ لسؤالٍ واحد —
| وهذه الحالةُ هي ما يُبقيهما جواباً واحداً.
*/
it('states the freeze limits the Action enforces, as integers, and follows an operator edit', function (): void {
    $settings = app(SessionSettings::class);

    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.freeze_max_days', $settings->freezeMaxDays())
        ->assertJsonPath('data.freeze_max_per_month', $settings->freezeMaxPerMonth());

    PlatformSettings::set('sessions.freeze_max_days', '14');
    PlatformSettings::set('sessions.freeze_max_per_month', '3');

    $payload = $this->getJson('/api/v1/platform')->assertOk()->json('data');

    // `toBe()` — an integer, never the string the settings row stores.
    expect($payload['freeze_max_days'])->toBe(14)
        ->and($payload['freeze_max_per_month'])->toBe(3)
        ->and($payload['freeze_max_days'])->toBe($settings->freezeMaxDays())
        ->and($payload['freeze_max_per_month'])->toBe($settings->freezeMaxPerMonth());
});

/*
| The rest of the numbers /terms and /refunds state, pinned to the reader each
| Action enforces with — the freeze-limit test above, for every clause.
|
| ⚠️ THE CONTROLLER SPELLS EACH DEFAULT AND CLAMP A SECOND TIME (the modules that
| own them depend on Tenancy), so two readers answer one question. This is what
| keeps them one answer: every field against its accessor on the shipped
| defaults, then again after an operator has moved every row — a copied default
| that drifted from its accessor fails the first half, a field that ignores the
| row fails the second.
|
| Two have no accessor of their own and are compared with the read their user
| makes, verbatim: `TwoFactorMandate::applyTo()` and `ExpireSubscriptionsJob::warn()`.
*/
function termsNumbersFromAccessors(): array
{
    $billing = app(BillingSettings::class);
    $sessions = app(SessionSettings::class);
    // A 100-minute session: `requiredStaySeconds() / 60` is the percentage itself.
    $session = (new ClassSession)->forceFill(['duration_minutes' => 100]);

    return [
        'two_factor_grace_days' => max(0, (int) PlatformSettings::get('auth.two_factor_grace_days', 14)),
        'max_unredeemed_credits' => $billing->maxUnredeemedCredits(),
        'stop_selling_after_days' => $billing->stopSellingAfterDays(),
        'dormant_notice_months' => $billing->dormantNoticeMonths(),
        'receipt_review_sla_hours' => $billing->reviewSlaHours(),
        'deferred_initial_credits' => $billing->initialLimitCredits(),
        'deferred_increase_after_on_time' => $billing->increaseAfterOnTime(),
        'deferred_increase_by_credits' => $billing->increaseByCredits(),
        'deferred_max_credits' => $billing->maxLimitCredits(),
        'deferred_reset_after_late_days' => $billing->decreaseAfterLateDays(),
        'cancellation_window_minutes' => $sessions->cancellationWindowMinutes(),
        'attendance_required_stay_percent' => intdiv($sessions->requiredStaySeconds($session), 60),
        'attendance_edit_window_hours' => $sessions->attendanceEditWindowHours(),
        'renewal_notice_days' => (int) PlatformSettings::get('subscription.expiring_notice_days', (int) config('subscriptions.expiring_notice_days', 3)),
        'chat_max_messages_per_minute' => CommunitySettings::maxMessagesPerMinute(),
        'review_min_sessions' => CommunitySettings::reviewMinSessions(),
        'review_period_days' => CommunitySettings::reviewPeriodDays(),
        'offboarding_notice_days' => ComplianceSettings::offboardingNoticeDays(),
        'store_refund_window_hours' => StoreSettings::refundWindowHours(),
    ];
}

it('states every terms number the Actions enforce, as integers, and follows an operator edit', function (): void {
    $payload = $this->getJson('/api/v1/platform')->assertOk()->json('data');

    foreach (termsNumbersFromAccessors() as $field => $value) {
        expect($payload[$field])->toBe($value, $field);
    }

    // The shipped defaults the pages used to spell by hand, so a default that
    // moves is a decision somebody sees rather than a sentence that changes.
    expect($payload)->toMatchArray([
        'two_factor_grace_days' => 14,
        'max_unredeemed_credits' => 24,
        'stop_selling_after_days' => 60,
        'dormant_notice_months' => 12,
        'cancellation_window_minutes' => 1440,
        'attendance_required_stay_percent' => 50,
        'attendance_edit_window_hours' => 48,
        'deferred_initial_credits' => 1,
        'deferred_increase_after_on_time' => 3,
        'deferred_increase_by_credits' => 1,
        'deferred_max_credits' => 4,
        'deferred_reset_after_late_days' => 14,
        'renewal_notice_days' => 3,
        'chat_max_messages_per_minute' => 30,
    ]);

    // Every row moved by an operator — stored as the strings the panel writes.
    foreach ([
        'auth.two_factor_grace_days' => '7',
        'billing.max_unredeemed_credits' => '30',
        'billing.stop_selling_after_days' => '45',
        'billing.dormant_notice_months' => '6',
        'billing.review_sla_hours' => '12',
        'billing.limit.initial_credits' => '2',
        'billing.limit.increase_after_on_time' => '5',
        'billing.limit.increase_by_credits' => '2',
        'billing.limit.max_credits' => '6',
        'billing.limit.decrease_after_late_days' => '10',
        'sessions.cancellation_window_minutes' => '720',
        'sessions.required_stay_ratio' => '0.75',
        'sessions.attendance_edit_window_hours' => '72',
        'subscription.expiring_notice_days' => '5',
        'community.chat.max_messages_per_minute' => '20',
        'community.review.min_sessions' => '6',
        'community.review.period_days' => '45',
        'compliance.offboarding_notice_days' => '60',
        'store.refund_window_hours' => '24',
    ] as $key => $value) {
        PlatformSettings::set($key, $value);
    }

    $payload = $this->getJson('/api/v1/platform')->assertOk()->json('data');

    foreach (termsNumbersFromAccessors() as $field => $value) {
        // `toBe()` — an integer, never the string the settings row stores.
        expect($payload[$field])->toBe($value, $field);
    }

    expect($payload)->toMatchArray([
        'two_factor_grace_days' => 7,
        'cancellation_window_minutes' => 720,
        'attendance_required_stay_percent' => 75,
        'deferred_max_credits' => 6,
        'renewal_notice_days' => 5,
        'store_refund_window_hours' => 24,
    ]);
});

/*
| رقمُ الواتسابِ للدعم — الحقلُ الثاني، وقد وصلَ للسببِ الذي وصلَ به الاسم.
|
| ⛔ **الزرُّ العائمُ مبنيٌّ منذُ زمنٍ ولم يُعرَضْ لأحدٍ قطّ.** رقمُه كانَ في
| `NEXT_PUBLIC_WHATSAPP_NUMBER`، يُدمَجُ وقتَ البناءِ ولم يُضبَطْ في أيِّ بيئة،
| فقرأَ `SUPPORT_WHATSAPP` سلسلةً فارغةً ولم يُرسَمِ الزرُّ ولا في التذييل. ولم
| يفشلْ شيء: الفراغُ هو أيضاً كيفَ يُطفَأُ الزرُّ عن قصد، فالعطبُ والإعدادُ
| الصحيحُ لهما نفسُ الشكلِ بالضبط.
*/

it('answers the support number without any authentication, and empty means off', function (): void {
    /*
    | ⚠️ الفراغُ يُرسَلُ ولا يُحذَفُ المفتاح. مفتاحٌ غائبٌ يجعلُ العميلَ يخمّنُ
    | أهوَ «غيرُ مضبوط» أم «خادمٌ أقدمُ من هذا الحقل»؛ والسلسلةُ الفارغةُ تقولُ
    | «لا خطَّ دعمٍ» بشكلٍ واحد.
    */
    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.support_whatsapp', '');

    PlatformSettings::set('platform.support_whatsapp', '97455512345');

    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.support_whatsapp', '97455512345');
});

it('keeps the support number in the settings the panel can edit', function (): void {
    expect(PlatformSettings::KEYS)->toHaveKey('platform.support_whatsapp')
        // ⚠️ مفتاحٌ بلا ملفِّ إعداداتٍ خلفَه يُرجِعُ `null`، و`platform_settings.value`
        // ليسَ `NULL`-able: هذا بعينُه العطبُ الذي قتلَ البذرَ في PR #108.
        ->and(config('platform.support_whatsapp'))->toBe('');
});

it('is registered in the platform settings the panel can edit', function (): void {
    /*
    | A key the panel cannot reach is a key nobody can change, which is the state
    | this whole change exists to leave behind. `KEYS` is what
    | `ManagePlatformSettings` and `PlatformSettings::all()` both read.
    */
    expect(PlatformSettings::KEYS)->toHaveKey('platform.name');
});

/*
| ⚠️ THIS CASE EXISTS BECAUSE THE PANEL'S LOGO DID NOT FOLLOW THE ROW, AND THE
| ONLY THING THAT FOUND IT WAS CHANGING THE ROW AND LOOKING.
|
| `brandName()` was moved to a closure over the setting and `brandLogo()` was
| not — it kept building its `aria-label` from `config('app.name')`, eagerly, at
| application boot. It MATCHED, because `APP_NAME` happens to hold the same
| string, so every screen looked right: two spellings of the product's name, one
| of which had stopped being the product's name the moment an operator renamed it
| from `/admin/platform-settings`. Reading the code would not have shown it.
|
| Both are closures now, so both are resolved per request. The assertion is that
| the rendered label MOVES when the row moves — not that it equals a constant,
| which the old, broken build also satisfied.
*/
it('renders the panel brand from the settings row, not from a second source', function (): void {
    PlatformSettings::set('platform.name', 'اسمٌ للّوحة');

    $panel = Filament::getPanel('admin');

    expect((string) $panel->getBrandName())->toBe('اسمٌ للّوحة')
        ->and((string) $panel->getBrandLogo())->toContain('اسمٌ للّوحة');
});
