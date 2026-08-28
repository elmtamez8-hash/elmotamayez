<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/*
| كلُّ مورِدٍ في اللوحةِ له بابٌ يُجيبُ عن «هل تُفتَحُ هذه الشاشةُ لك؟».
|
| ⚠️ والبابُ إمّا `canViewAny()` على المورِدِ **أو** `viewAny()` في سياسةِ النموذج،
| ولا ثالثَ لهما. `Resource::canViewAny()` تُفوِّضُ إلى السياسة عبرَ
| `get_authorization_response()` — فإن لم تكنْ للنموذجِ سياسةٌ أصلاً، أو كانت بلا
| دالّةٍ بهذا الاسم، **سقطَ الطلبُ إلى `Response::allow()`**: أي أنّ الشاشةَ تُفتَحُ
| لكلِّ من يجتازُ بابَ اللوحةِ نفسِه. وقائمةُ Filament لا تستدعي سياسةَ الصفِّ أبداً،
| فسياسةُ `view()` مهما دقَّتْ لا تحرسُ جدولاً.
|
| هكذا سلَّمَتْ `OrderResource` مساعِدَ مدرّسٍ بريدَ كلِّ طالبٍ والمبلغَ الذي دفعَه،
| في مساحةٍ يُمنَعُ فيها من أيِّ بيانٍ ماليّ — و`OrderPolicy::view()` كانت صحيحةً
| طَوالَ الوقت.
|
| ⚠️ وتصريحُ `canViewAny()` فوقَ سياسةٍ تملكُ `viewAny()` ليس تشديداً بل **إجابةٌ
| ثانية**: `PlatformStaffResource` عليه سياسةٌ تمنعُ مالكَ المساحة، وتصريحٌ يقرأُ
| `roles.manage` بدلَها كان يفتحُ له شاشةَ تفويضاتِ المنصّة. فالقاعدةُ «أحدُهما»،
| لا «كلاهما».
*/

/**
 * ⚠️ `list<string>` لا `list<class-string<Resource>>`: مُنسِّقُ الأسلوبِ يُصغِّرُ
 * `Resource` داخلَ التعليقِ فيصيرُ `resource` — وهو نوعٌ بدائيٌّ في PHP.
 *
 * @return list<string>
 */
function panelResources(): array
{
    /** @var list<string> $resources */
    $resources = Filament::getPanel('admin')->getResources();

    sort($resources);

    return $resources;
}

it('لكلّ موردٍ في اللوحة بابٌ يُجيب عن فتحِ الشاشة', function (): void {
    $resources = panelResources();

    // فحصٌ يمرُّ على لا شيءٍ أخطرُ من غيابِه.
    expect($resources)->not->toBeEmpty();

    $undefended = [];

    foreach ($resources as $resource) {
        // مورِدُ الأدوارِ من حزمةِ Shield، وبابُه مسؤوليّتُها.
        if (! str_starts_with($resource, 'App\\')) {
            continue;
        }

        $declaresOwnDoor = (new ReflectionMethod($resource, 'canViewAny'))
            ->getDeclaringClass()->getName() !== Resource::class;

        if ($declaresOwnDoor) {
            continue;
        }

        /** @var class-string<Model> $model */
        $model = $resource::getModel();
        $policy = Gate::getPolicyFor($model);

        if ($policy !== null && method_exists($policy, 'viewAny')) {
            continue;
        }

        $undefended[] = $resource.' ('.class_basename($model).': لا `canViewAny()` ولا سياسةُ `viewAny()`)';
    }

    expect($undefended)->toBe([]);
});

it('لا يبني موردٌ بابَه على اسم دَورٍ مكتوبٍ نصّاً', function (): void {
    $offences = [];

    foreach (panelResources() as $resource) {
        if (! str_starts_with($resource, 'App\\')) {
            continue;
        }

        $path = (new ReflectionClass($resource))->getFileName();

        if ($path === false) {
            continue;
        }

        $source = (string) file_get_contents($path);

        /*
        | الأدوارُ تُقارَنُ بثوابتِ `Roles::`؛ والمرفوضُ هو النصُّ الخام — قاعدةٌ
        | مكتوبةٌ ضدَّ سلسلةٍ حرفيّةٍ تسقطُ بإعادةِ تسميةٍ واحدةٍ في اللوحة، وهي
        | العلّةُ نفسُها التي جعلَتْ جدارَ المساعِدِ يُكتَبُ عندَ الفحصِ لا على الاسم.
        */
        foreach (["'assistant-teacher'", "'tenant-owner'", "'super_admin'"] as $needle) {
            if (str_contains($source, 'hasRole('.$needle) || str_contains($source, '->can('.$needle)) {
                $offences[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$needle;
            }
        }
    }

    expect($offences)->toBe([]);
});
