<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **مفتاحانِ مختلفان لسطحَين، ومسؤولُ المنصّةِ كانَ يكتبُ كلمةَ سرِّه مرّتَين.**
|
| الواجهةُ رمزُ Sanctum في `localStorage`، و`‎/admin` جلسةٌ وكوكي —
| و`localStorage` لا يسافرُ مع طلبِ صفحة. فالجسرُ يسألُ بالمفتاحِ القائمِ ويصنعُ
| الآخَر، والخطرُ كلُّه في تفاصيلِه: كلُّ حالةٍ هنا تُغلِقُ باباً بعينِه.
*/
function panelAdmin(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

it('mints a ticket for somebody the panel would let in', function (): void {
    Sanctum::actingAs(panelAdmin());

    $this->postJson('/api/v1/auth/panel-ticket')
        ->assertOk()
        ->assertJsonStructure(['url', 'expires_in']);
});

it('refuses a signed-in caller the panel would not let in', function (): void {
    // ⚠️ الشرطُ `mayAccessAdminPanel()` نفسُها: مدرّسٌ لهُ سطحُه في `‎/manage`
    // ولا يدخلُ لوحةَ المنصّة.
    Sanctum::actingAs(User::factory()->create(['is_super_admin' => false]));

    $this->postJson('/api/v1/auth/panel-ticket')
        ->assertStatus(403)
        ->assertJsonPath('code', 'panel_forbidden');
});

it('refuses a caller with no key at all', function (): void {
    $this->postJson('/api/v1/auth/panel-ticket')->assertStatus(401);
});

it('signs the holder in and sends them to the panel', function (): void {
    $admin = panelAdmin();

    Sanctum::actingAs($admin);
    $url = $this->postJson('/api/v1/auth/panel-ticket')->json('url');

    // جلسةٌ جديدةٌ تماماً: الرمزُ لا يسافرُ مع طلبِ صفحة، وهذا هو الطلبُ الذي
    // كانَ يهبطُ على شاشةِ الدخول.
    $this->app['auth']->forgetGuards();

    $this->get($url)->assertRedirect(config('filament.path', 'admin'));

    expect(Auth::guard('web')->id())->toBe($admin->getKey());
});

it('spends the ticket once and refuses it for ever after', function (): void {
    /*
    | ⛔ **وهذه هي الحالةُ التي يقعُ عليها أثرُ العطلِ لو كُتِبَ `get` بدلَ
    | `pull`.** التذكرةُ تبقى في سجلِّ المتصفّحِ وفي سجلِّ كلِّ وكيلٍ بينَهما،
    | فتذكرةٌ تُعادُ تجعلُ قارئَ السجلِّ مسؤولَ منصّة.
    */
    $admin = panelAdmin();

    Sanctum::actingAs($admin);
    $url = $this->postJson('/api/v1/auth/panel-ticket')->json('url');

    $this->app['auth']->forgetGuards();
    $this->get($url)->assertRedirect(config('filament.path', 'admin'));

    $this->app['auth']->forgetGuards();
    $this->get($url)->assertStatus(403);
});

it('refuses a ticket that was never minted', function (): void {
    $this->get('/panel/enter/'.str_repeat('a', 64))->assertStatus(403);
});

it('refuses a ticket whose holder lost the right between minting and spending', function (): void {
    /*
    | ستّونَ ثانيةً بينَ الطلبِ والصرف، وسحبُ الصلاحيّةِ داخلَها يجبُ أن يُغلِقَ
    | البابَ لا أن يسبقَه. شرطٌ يُسألُ عندَ السكِّ وحدَه يفتحُ للمعزولِ توّاً.
    */
    $admin = panelAdmin();

    Sanctum::actingAs($admin);
    $url = $this->postJson('/api/v1/auth/panel-ticket')->json('url');

    $admin->forceFill(['is_super_admin' => false])->save();

    $this->app['auth']->forgetGuards();
    $this->get($url)->assertStatus(403);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('lets the ticket die of old age', function (): void {
    $admin = panelAdmin();

    Sanctum::actingAs($admin);
    $url = $this->postJson('/api/v1/auth/panel-ticket')->json('url');

    // العمرُ على الخادمِ لا في التذكرة: لا شيءَ يُقرَأُ منها ولا شيءَ يُزوَّرُ
    // فيها، فمسحُ المخبأِ هو ما يُحاكي انقضاءَ الستّينَ ثانية.
    Cache::flush();

    $this->app['auth']->forgetGuards();
    $this->get($url)->assertStatus(403);
});

/*
| ⛔ **وتثبيتُ الجلسةِ غيرُ مقيسٍ هنا، والسببُ يُقالُ لا يُخفى.**
|
| `session()->regenerate()` موجودةٌ في المُتحكِّمِ وهي الصوابُ — لكنّ هذا الملفَّ
| **لا يستطيعُ أن يُثبِتَها**، وقد كتبتُ الحالةَ ثمّ حذفتُها لأنّها كانت خضراءَ
| مع الحذفِ ومع الإبقاءِ سواءً بسواء.
|
| قِيسَ في ثلاثِ محاولات:
| - `startSession()` تُهيّئُ جلسةً في الحاويةِ لا تُرسَلُ مع الطلب، فالمعرَّفُ
|   بعدَها مختلفٌ دائماً — طُبِعَ الاثنانِ وكانا مختلفَينِ في الحالتَين.
| - زرعُ كوكي الجلسةِ بـ`withUnencryptedCookie` لم يُتبَنَّ معرَّفاً كذلك:
|   حذفُ `regenerate()` أبقى الحالةَ خضراء.
|
| ولم أقِسْ **لماذا** لم يُتبنَّ، فلا أدّعيه.
|
| وحارسٌ أخضرُ في الحالتَينِ أسوأُ من غيابِه: يقولُ إنّ الثغرةَ محروسةٌ وهي ليست،
| وهو عطبُ «أخضرُ لسببٍ خاطئ» الذي يسجّلُه هذا المستودعُ بنصِّه. فالسطرُ يبقى
| موثَّقاً في المُتحكِّمِ ومسؤوليّةُ إثباتِه على فحصٍ يدويٍّ أو Playwright، حيثُ
| ثمّةَ متصفّحٌ يحملُ كوكي حقيقيّاً.
*/
