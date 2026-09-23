<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **بابُ اللوحةِ كانَ الدخولَ الوحيدَ الذي لا يتركُ أثراً** — ٠٣٧ · قصّة ٢.
|
| `‎/admin` يُصادِقُ على حارسِ `web` ولا يمرُّ بـ`StartAuthSession` أبداً، فمن فتحَ
| لوحتَه كانَ له دخولٌ حيٌّ لم يسمعْ به `auth_sessions`: غائبٌ عن «أجهزتي»، خارجَ
| العدّ، ولا سبيلَ إلى إنهائِه. والشاشةُ تقولُ إنّها تعرضُ كلَّ أجهزتِك.
|
| ⚠️ **والقياسُ هنا على المُعالِجِ لا على طلبٍ تالٍ، وهذا ليس تفصيلاً.** سائقُ
| الجلساتِ في الاختباراتِ `array`، وعميلُ الاختبارِ لا يحملُ كوكيَّ الجلسةِ من طلبٍ
| إلى طلب — فحالةٌ تقولُ «بعدَ الإنهاءِ يصيرُ الطلبُ التالي زائراً» تنجحُ سواءٌ
| نُفِّذَ الإتلافُ أم لم يُنفَّذ، لأنّ الطلبَ التاليَ زائرٌ على كلِّ حال. تلك هي
| الحالةُ الفارغةُ التي حُذِفَت من هذا المستودَعِ مرّةً بدلَ أن تُشحَنَ كحارسٍ كاذب.
| `Session::getHandler()->read($id)` يقرأُ ما يملكُه المُعالِجُ فعلاً، فيرسبُ إن
| لم يُتلَفْ شيء.
|
| **كيفَ يُمسَك**:
|   · احذفْ فرعَ `session_id` من `TerminateAuthSession` ⇒ يسقطُ «يُتلِفُ الجلسة».
|   · أعِدْ `session()->regenerate()` بعدَ `login()` في `PanelHandoffController`
|     ⇒ يسقطُ «المعرِّفُ المسجَّلُ حيّ»، لأنّ الصفَّ يشيرُ حينَها إلى جلسةٍ
|     لم تُكتَبْ قطّ.
|   · احذفْ `Event::listen(Login::class, …)` ⇒ تسقطُ الحالاتُ الثلاثُ الأولى.
*/
function panelSignIn(User $admin): AuthSession
{
    Sanctum::actingAs($admin);
    $url = test()->postJson('/api/v1/auth/panel-ticket')->json('url');

    // جلسةٌ جديدةٌ تماماً، كما يصلُ المتصفّحُ: الرمزُ لا يسافرُ مع طلبِ صفحة.
    // (`app()` لا `test()->app` — الثانيةُ محميّةٌ خارجَ جسمِ الحالة، والحاويةُ
    // واحدةٌ على كلِّ حال.)
    app('auth')->forgetGuards();

    test()->get($url)->assertRedirect(config('filament.path', 'admin'));

    $session = AuthSession::query()->whereNotNull('session_id')->latest('id')->first();

    expect($session)->not->toBeNull();

    /** @var AuthSession $session */
    return $session;
}

it('records a panel sign-in as a device, with no bearer token behind it', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $session = panelSignIn($admin);

    expect($session->user_id)->toBe($admin->getKey())
        ->and($session->status)->toBe(AuthSession::STATUS_ACTIVE)
        // ⚠️ فتحُ اللوحةِ لا يَسُكُّ رمزَ API. رمزٌ يُسَكُّ هنا اعتمادٌ لم يطلبْه
        // أحدٌ ويُصادِقُ كلَّ مسارٍ لو تسرّب.
        ->and($session->token_id)->toBeNull()
        ->and($admin->tokens()->count())->toBe(0);
});

it('records the session id the browser actually carries', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $session = panelSignIn($admin);

    // الجلسةُ مكتوبةٌ تحتَ هذا المعرِّفِ بالذات. لو وُلِّدَ معرِّفٌ آخرُ بعدَ
    // `login()` لكانَ الصفُّ يشيرُ إلى لا شيءٍ — ويبدو سليماً.
    expect(Session::getHandler()->read((string) $session->session_id))->not->toBe('');
});

it('destroys the panel session when the device is ended', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $session = panelSignIn($admin);
    $sessionId = (string) $session->session_id;

    expect(Session::getHandler()->read($sessionId))->not->toBe('');

    app(TerminateAuthSession::class)->handle($session, SessionEndReason::Manual);

    expect(Session::getHandler()->read($sessionId))->toBe('')
        ->and($session->fresh()?->status)->toBe(AuthSession::STATUS_ENDED);
});

/*
| ⚠️ **الحارسُ اسمٌ، والتسجيلُ كلُّه معلَّقٌ به.** `RecordPanelSignIn` يُجيبُ عن
| `web` وحدَه — لأنّ `Login` يُطلَقُ لكلِّ حارس. وصفحةُ دخولِ Filament ليست لنا
| لنضعَ فيها سطراً، فالرابطُ الوحيدُ بينَها وبينَ التسجيلِ هو أنّها تُصادِقُ على
| هذا الحارسِ بعينِه. تغييرُ حارسِ اللوحةِ يوماً يوقِفُ تسجيلَ كلِّ دخولٍ منها
| **بصمت** — فيُقاسُ الاسمُ صراحةً بدلَ أن يُفترَض.
*/
it('keeps the panel on the guard the recorder answers for', function (): void {
    expect(Filament::getPanel('admin')->getAuthGuard())->toBe('web');
});

/*
| ⚠️ والضابطُ على الاتّجاهِ الآخَر، وبلا هذه الحالةِ يمرُّ بناءٌ يكتبُ صفَّ لوحةٍ
| لكلِّ شيء: `Login` يُطلَقُ لكلِّ حارس، و`Sanctum::actingAs` تهجئةُ ١٢١٧ موضعاً
| في هذه الشجرة.
*/
it('writes no panel row for an API sign-in', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/auth/me')->assertOk();

    expect(AuthSession::query()->whereNotNull('session_id')->count())->toBe(0);
});

it('leaves the API door writing a token and no session id', function (): void {
    Cache::flush();

    $user = User::factory()->create(['email' => 'api-door@example.test']);
    $user->forceFill(['password' => bcrypt('secret-password-1')])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'api-door@example.test',
        'password' => 'secret-password-1',
    ])->assertOk();

    $session = AuthSession::query()->where('user_id', $user->getKey())->latest('id')->first();

    expect($session)->not->toBeNull()
        ->and($session?->token_id)->not->toBeNull()
        ->and($session?->session_id)->toBeNull();
});

/*
| ⛔ **وصفُّ اللوحةِ كانَ يبقى «نشِطاً» إلى الأبد** — جلسةُ الويبِ تنتهي وحدَها بعدَ
| `session.lifetime` دقيقةً من السكون، ولا شيءَ يُنهي الصفّ. `TouchPanelSession`
| يُحرِّكُ `last_active_at` ما دامتِ اللوحةُ مُستعمَلة، والكنسُ الليليُّ يُنهي ما سكن
| (`IdleSessionSweepTest`).
|
| الطلبُ التالي يركبُ الجلسةَ المسجَّلةَ نفسَها بكوكيِّها — لا بـ`actingAs`، الذي
| يُصادِقُ بلا جلسةٍ فلا يبلغُ الصفَّ أبداً.
*/
function panelRequest(AuthSession $session, string $method = 'get', string $uri = '/admin'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withCookie((string) config('session.cookie'), (string) $session->session_id)
        ->{$method}($uri);
}

it('moves last_active_at while the panel is in use, at most every few minutes', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $session = panelSignIn($admin);

    $this->travel(6)->minutes();
    panelRequest($session)->assertOk();

    $touched = $session->fresh()?->last_active_at;
    expect($touched?->getTimestamp())->toBe(now()->getTimestamp());

    // Inside the interval: no second write.
    $this->travel(1)->minute();
    panelRequest($session)->assertOk();

    expect($session->fresh()?->last_active_at?->getTimestamp())->toBe($touched?->getTimestamp());
});

it('ends the device row when the operator signs out of the panel', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $session = panelSignIn($admin);

    panelRequest($session, 'post', '/admin/logout');

    $session->refresh();
    expect($session->status)->toBe(AuthSession::STATUS_ENDED)
        ->and($session->ended_reason)->toBe(SessionEndReason::Logout);
});
