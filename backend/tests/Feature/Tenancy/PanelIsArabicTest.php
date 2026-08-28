<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| ⚠️ لوحةُ `/admin` عربيّةٌ بالكامل، والحارسُ هنا لأنّها **لم تكن**.
|
| أربعةُ مورِدٍ من أصلِ خمسةَ عشرَ كانت إنجليزيّةً بالكامل على الإنتاج — العنوانُ
| «Courses» فوقَ جدولٍ أعمدتُه «Title · Status · Price minor · Created at»، وزرٌّ
| يقولُ «إضافة course»، وشارةُ حالةٍ تطبعُ `published` خاماً بينما المرشِّحُ
| المجاورُ لها عربيّ. ولم يسقطْ شيء: تسميةُ Filament الافتراضيّةُ تُشتَقُّ من اسمِ
| العمود، فالشاشةُ تبدو مكتملةً لمن يقرأُ الكود.
|
| فالفحصُ يقرأُ **النصوصَ التي يراها المستخدِمُ** من ملفّاتِ اللوحةِ نفسِها، لا
| قائمةً تُكتَبُ بجانبِها: قائمةٌ ثانيةٌ هي إجابةٌ ثانيةٌ تشيخُ عندَ أوّلِ شاشةٍ
| يضيفُها أحد.
|
| ⚠️ والقاعدةُ «فيه حرفٌ عربيٌّ واحدٌ على الأقلّ» لا «لا حرفَ لاتينيّاً»: رمزُ
| العملةِ داخلَ «ريال قطري (QAR)» مقصودٌ، والشرطةُ الطويلةُ «—» و«٪» و«#» ليست
| كلماتٍ أصلاً. المرفوضُ هو نصٌّ لاتينيٌّ **بلا** عربيّةٍ معه.
*/

/**
 * @return list<string>
 */
function panelPhpFiles(): array
{
    $dirs = array_values(array_filter([
        app_path('Filament'),
        ...glob(app_path('Modules/*/Filament')) ?: [],
    ], 'is_dir'));

    $files = [];

    foreach (Finder::create()->files()->in($dirs)->name('*.php') as $file) {
        $files[] = $file->getRealPath();
    }

    sort($files);

    return $files;
}

/**
 * كلُّ نصٍّ يظهرُ للمستخدِمِ في ملفٍّ واحد، مع رقمِ سطرِه.
 *
 * @return list<array{0: string, 1: int}>
 */
function userFacingStrings(string $path): array
{
    $source = (string) file_get_contents($path);

    // الاستدعاءاتُ التي وسيطُها الأوّلُ نصٌّ يُقرأُ على الشاشة.
    $methods = 'label|placeholder|helperText|description|copyMessage|copyMessageDuration'
        .'|trueLabel|falseLabel|heading|subheading|title|emptyStateHeading'
        .'|emptyStateDescription|modalHeading|modalDescription|modalSubmitActionLabel'
        .'|successNotificationTitle|navigationLabel|badgeTooltip';

    $patterns = [
        // ->label('…')
        '/->(?:'.$methods.")\(\s*'((?:[^'\\\\]|\\\\.)*)'/u",
        // protected static ?string $modelLabel = '…';
        "/\\\$(?:modelLabel|pluralModelLabel|navigationLabel|title|heading|navigationGroup)\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/u",
        // public static function getNavigationLabel(): string { return '…'; }
        "/function get(?:NavigationLabel|ModelLabel|PluralModelLabel|NavigationGroup|Title|Heading)\(\)[^{]*\{\s*return\s*'((?:[^'\\\\]|\\\\.)*)'/u",
    ];

    $found = [];

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            [$text, $offset] = $match;
            $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
            $found[] = [(string) $text, $line];
        }
    }

    return $found;
}

it('لا يترك نصّاً لاتينيّاً في أيّ شاشةِ لوحة', function (): void {
    $files = panelPhpFiles();

    // الفحصُ يمرُّ فارغاً إن لم يجدْ ملفّاً — وذلك أخطرُ من فشلِه.
    expect($files)->not->toBeEmpty();

    $offences = [];
    $checked = 0;

    foreach ($files as $path) {
        foreach (userFacingStrings($path) as [$text, $line]) {
            $checked++;

            if ($text === '') {
                continue;
            }

            $hasArabic = preg_match('/\p{Arabic}/u', $text) === 1;
            $hasLatinWord = preg_match('/[A-Za-z]/u', $text) === 1;

            if ($hasArabic || ! $hasLatinWord) {
                continue;
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $offences[] = $relative.':'.$line.' → "'.$text.'"';
        }
    }

    // ولا يمرُّ بلا قياس: نصٌّ واحدٌ فقط يعني أنّ الأنماطَ توقّفَتْ عن المطابقة.
    expect($checked)->toBeGreaterThan(200);

    expect($offences)->toBe([]);
});

it('يترجم كلّ حالةٍ في كلّ مجموعةٍ مغلقةٍ تُعرَض', function (): void {
    $enums = [];

    /*
    | البحثُ في `app/` كلِّها لا في مجلّدات `Enums` وحدَها: `PlatformRole` و
    | `UserStatus` تسكنان `Identity/Support/`، وقصْرُ المسحِ على مجلَّدٍ باسمٍ
    | معيَّنٍ يجعلُ الحارسَ يمرُّ عليهما دونَ أن يراهما.
    */
    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $source = (string) file_get_contents($file->getRealPath());

        if (! str_contains($source, 'implements HasArabicLabel')) {
            continue;
        }

        if (preg_match('/namespace\s+([^;]+);/', $source, $ns) !== 1) {
            continue;
        }

        $class = trim($ns[1]).'\\'.$file->getFilenameWithoutExtension();

        if (enum_exists($class)) {
            $enums[] = $class;
        }
    }

    expect($enums)->not->toBeEmpty();

    foreach ($enums as $enum) {
        /** @var array<string, string> $options */
        $options = $enum::options();

        expect($options)->not->toBeEmpty("{$enum} لا يعرض خياراً واحداً");

        foreach ($options as $value => $label) {
            expect(preg_match('/\p{Arabic}/u', $label))
                ->toBe(1, "{$enum}::{$value} ترجمتُه ليست عربيّة: «{$label}»");
        }
    }
});
