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

/*
| ⛔ الجسرُ كانَ يتقاسمُ دلوَ `throttle:auth` مع تسجيلِ الدخول، والمفتاحُ الثاني
| لذلك الدلوِ `email:` + حقلٌ لا يحملُه هذا الطلبُ أبداً — فكانَ `email:` فارغاً،
| دلواً واحداً لكلِّ مَن على المنصّة. خمسُ ضغطاتٍ من أيِّ طالبٍ في دقيقةٍ تُغلِقُ
| الجسرَ على كلِّ مسؤولٍ بـ٤٢٩ لا ذنبَ له فيه.
*/
it('keeps a stranger\'s presses out of the staff member\'s bucket', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_super_admin' => false]));

    foreach (range(1, 6) as $_) {
        $this->postJson('/api/v1/auth/panel-ticket');
    }

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs(panelAdmin());

    $this->postJson('/api/v1/auth/panel-ticket')->assertOk();
});

it('still bounds one account pressing it in a loop', function (): void {
    Sanctum::actingAs(panelAdmin());

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/panel-ticket')->assertOk();
    }

    $this->postJson('/api/v1/auth/panel-ticket')->assertStatus(429);
});

/*
| ⚠️ A DESTINATION INSIDE THE PANEL, NAMED AT MINT AND NEVER AT ENTER. The bell's
| «a receipt is waiting» lands on that order's page rather than the panel's front
| door — and the GET link still carries nothing but the ticket, so nobody can
| craft one that sends a staff member elsewhere.
*/
it('lands on a path inside the panel when one was asked for at mint', function (): void {
    Sanctum::actingAs(panelAdmin());

    $url = $this->postJson('/api/v1/auth/panel-ticket', ['to' => '/admin/orders/abc-123/edit'])->json('url');

    $this->app['auth']->forgetGuards();

    $this->get($url)->assertRedirect('/admin/orders/abc-123/edit');
});

it('ignores a destination outside the panel and lands on its front page', function (string $to): void {
    Sanctum::actingAs(panelAdmin());

    $url = $this->postJson('/api/v1/auth/panel-ticket', ['to' => $to])->json('url');

    $this->app['auth']->forgetGuards();

    $this->get($url)->assertRedirect(config('filament.path', 'admin'));
})->with([
    'another host' => 'https://evil.example/admin',
    'protocol-relative' => '//evil.example/admin',
    'outside the panel' => '/dashboard',
    'a prefix lookalike' => '/administrator',
    'climbing out' => '/admin/../dashboard',
    'a query' => '/admin/orders?next=https://evil.example',
]);
