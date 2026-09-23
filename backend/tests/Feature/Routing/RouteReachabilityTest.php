<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| ⛔ **مسارُ `web` بلا `location` في nginx يُخدَمُ من Next ويردُّ ٤٠٤ — وقد شُحِنَ
| نصفُ ميزةٍ إلى الإنتاجِ بهذا بالضبط.**
|
| `‎/` في `docker/nginx.prod.conf` لـNext، وLaravel يصلُه ما تذكرُه قائمةُ
| البادئاتِ **وحدَه** (`/api` · `/admin` · `/horizon` · `/livewire` ·
| `/sanctum` · `/broadcasting`). فجسرُ لوحةِ الإدارةِ شُحِنَ في ٢٠٢٦-٠٩-١٧ ونصفُه
| يعمل: `‎/api/v1/auth/panel-ticket` أجابَ ٤٠١ صحيحاً — لأنّه تحتَ `/api` —
| بينما `‎/panel/enter/{ticket}` أجابَ **٤٠٤**، أي التذكرةُ تُسَكُّ ولا تُصرَفُ
| أبداً.
|
| ⚠️ **ولا اختبارَ في هذا المستودعِ كانَ يمكنُ أن يراه**: pest يُنادي التطبيقَ
| مباشرةً بلا nginx، فالمسارُ أخضرُ في تسعِ حالاتٍ ومفقودٌ على الإنتاج. وهو
| عطلُ `SchemaIdentifierLengthTest` نفسُه بوجهٍ آخَر — قيدٌ لا يوجدُ في البيئةِ
| التي تُشغَّلُ فيها الاختبارات، فيُقرَأُ من الملفِّ الذي يحملُه.
|
| ⚠️ **والنطاقُ مسارَاتُنا وحدَها** (`App\Modules\…`). Filament وLivewire وHorizon
| تسجّلُ مساراتٍ بعشرات، وبعضُها غيرُ مُغطّىً عمداً أو غيرُ مستعمَل — وحارسٌ
| بثلاثةِ استثناءاتٍ مكتوبةٍ هو الحارسُ الذي يُسكَتُ بإضافةِ رابع.
*/
it('serves every web route this repository defines through a real nginx location', function (): void {
    $config = file_get_contents(base_path('../docker/nginx.prod.conf'));

    expect($config)->toBeString()->not->toBeEmpty();

    /*
    | البادئاتُ كما يقرؤُها nginx: `location ^~ /x` و`location /x/`. كلتاهما
    | مطابقةُ بادئة، و`^~` تعني «لا تُجرِّبْ تعبيراً نمطيّاً بعدَها» — وهو ما
    | يجعلُ `‎/livewire` يلتقطُ `‎/livewire-9d2a62fb` كذلك.
    */
    preg_match_all('#^\s*location\s+(?:\^~\s+)?(/[A-Za-z0-9._-]*)#m', (string) $config, $matches);

    $served = array_values(array_filter(
        array_map(fn (string $path): string => rtrim($path, '/'), $matches[1]),
        fn (string $path): bool => $path !== '',
    ));

    expect($served)->toContain('/api');

    $unreachable = [];

    foreach (Route::getRoutes() as $route) {
        $action = $route->getActionName();

        // مساراتُنا وحدَها — انظر الفقرةَ أعلاه.
        if (! str_starts_with($action, 'App\\Modules\\')) {
            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');

        // ما يمرُّ بمجموعةِ `api` يُخدَمُ من `‎/api` وقد تحقّقنا منها أعلاه.
        if (str_starts_with($uri, '/api/')) {
            continue;
        }

        $covered = false;

        foreach ($served as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                $covered = true;

                break;
            }
        }

        if (! $covered) {
            $unreachable[] = $route->methods()[0].' '.$uri;
        }
    }

    /*
    | الرسالةُ تقولُ الإصلاحَ لا العطلَ وحدَه: قارئُ هذا الفشلِ لن يخمّنَ أنّ
    | الحلَّ سطرٌ في ملفِّ nginx، وحارسٌ يرسبُ بلا مخرجٍ يُعطَّلُ بعدَ ثالثِ مرّة.
    */
    expect($unreachable)->toBe([], implode(
        "\n",
        array_merge(
            ['مسارٌ لا يصلُه nginx — أضِفْ `location ^~ /<البادئة>` في docker/nginx.prod.conf:'],
            $unreachable,
        ),
    ));
});
