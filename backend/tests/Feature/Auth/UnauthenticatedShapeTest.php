<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| ⛔ **طريقٌ محميٌّ بلا توكنٍ يردُّ ٤٠١ — ولو لم يطلبِ العابرُ JSON.**
|
| قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٥: كلُّ طريقٍ خلفَ `auth:sanctum` كانَ يردُّ
| **٥٠٠** لطلبٍ بلا `Accept: application/json`. السبب:
| `ApplicationBuilder::withMiddleware()` يضعُ افتراضاً
| `redirectGuestsTo(fn () => route('login'))` **قبلَ** أن يعملَ إغلاقُ
| `bootstrap/app.php`، ولا وجودَ لطريقٍ اسمُه `login` هنا — هذه واجهةُ برمجةٍ
| ولوحةُ Filament، ولوحةُ Filament تُعيدُ تعريفَ `redirectTo()` في صنفِها. فيُرمى
| `RouteNotFoundException` **داخلَ الوسيطِ**، قبلَ أن يُبنى
| `AuthenticationException`، ولا يبلغُ المعالِجُ سطرَ الـ٤٠١.
|
| ⚠️ **و`shouldRenderJsonWhen` يُخفي العطلَ بدلَ أن يكشفَه**: جوابُ الـ٥٠٠ يخرجُ
| **JSON**، فيقرؤُه العابرُ «خطأُ خادمٍ حقيقيّ» لا «إعدادٌ ناقص».
|
| ⚠️ **ولا تراه الواجهةُ ولا أيُّ اختبارٍ قائم**: `lib/api.ts` يُرسِلُ الهيدرَ في
| كلِّ طلب، و**كلُّ** اختباراتِ هذا المستودعِ تستعملُ `getJson`/`postJson` —
| وكلاهما يضعُ `Accept: application/json`. فمَن يقعُ في العطلِ هو ما يأتي من
| خارجِ ذلك: فاحصُ مراقبةٍ يقرأُ الموقعَ «واقعاً» وهو يعمل، أو `curl` في تشخيصِ
| عُطلٍ آخرَ يرسلُ القارئَ خلفَ سببٍ لا وجودَ له.
|
| **كيفَ يمسك**: احذفْ `redirectGuestsTo(fn () => null)` من `bootstrap/app.php`
| ⇒ يسقطُ كلُّ شقٍّ هنا بـ«٥٠٠ بدلَ ٤٠١».
*/

/**
 * ⚠️ **`get()` لا `getJson()`**، وهذا هو الاختبارُ كلُّه: الثاني يضعُ
 * `Accept: application/json`، وهو بالضبط الهيدرُ الذي يُخفي العطل.
 */
it('answers a protected route without the JSON header with 401, not 500', function (string $path): void {
    $this->get($path)->assertStatus(401);
})->with([
    'الاختبارات' => '/api/v1/exams',
    'الواجبات' => '/api/v1/assignments',
    'التسجيلات' => '/api/v1/enrollments',
    'الشهادات' => '/api/v1/certificates',
    'الجلسات' => '/api/v1/auth/sessions',
]);

it('still answers 401 to a caller that does ask for JSON', function (): void {
    $this->getJson('/api/v1/exams')->assertStatus(401);
});

/*
| ⚠️ **والضابطُ على الشجرةِ كلِّها، لا على خمسةِ مساراتٍ اختِيرَت باليد.**
|
| خمسةٌ تُمسِكُ العطلَ اليومَ ولا تقولُ شيئاً عن الطريقِ السادسِ الذي يُضاف. هذا
| الشقُّ يمشي **كلَّ** طريقِ `GET` تحتَ `api/` يحملُ `auth:sanctum`، ويطرقُه بلا
| هيدرٍ وبلا توكن.
|
| والمقصورُ على `GET` بلا معاملاتٍ عمداً: طريقٌ بمعرّفٍ في مسارِه يحتاجُ صفّاً
| موجوداً، و`POST` يمرُّ على تحقُّقٍ من المدخلات — والسؤالُ هنا سؤالٌ عن الوسيطِ
| وحدَه، يقعُ قبلَ الاثنَين.
*/
it('answers 401 on every protected GET route in the tree', function (): void {
    $bad = [];
    $walked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/') || str_contains($uri, '{')) {
            continue;
        }

        if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            continue;
        }

        $walked++;

        $status = $this->get('/'.$uri)->status();

        if ($status !== 401) {
            $bad[] = $uri.' ⇒ '.$status;
        }
    }

    // الضابطُ الموجَب: مشيةٌ لم تجدْ طريقاً واحداً تُرضي «صفرَ فروق» تماماً.
    expect($walked)->toBeGreaterThan(20, 'the walk found no protected routes — it measured nothing')
        ->and($bad)->toBe([], implode(PHP_EOL, $bad));
});
