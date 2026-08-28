<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| ترجمةُ Filament نفسِها — لا تسمياتِنا.
|
| ⚠️ مفتاحٌ غائبٌ عن ملفِّ `ar` في الحزمةِ **يسقطُ إلى الإنجليزيّةِ بصمت**: لا
| خطأ، ولا مكانَ فارغ، ولا سطرَ في السجلّ. سبعةٌ وثلاثون مفتاحاً كانت كذلك، وأكثرُها
| نصوصٌ يقرؤها قارئُ الشاشةِ فلا تظهرُ في لقطةِ شاشةٍ ولا في مسحٍ للـDOM المرئيّ:
| منطقةُ `aria-live` في كلِّ جدولٍ كانت تنطقُ «No results»، وعمودُ ✓/✗ يُقرأُ
| «Yes»/«No»، والقسمُ المطويُّ «Collapse section». وثلاثةٌ منها مرئيّةٌ بالعين:
| زرّا «Download»/«Open in new tab» على كلِّ حقلِ رفعِ ملفّ، و«Skip to content»
| أوّلُ ما يبلغُه زرُّ Tab في كلِّ صفحة.
|
| الفحصُ يسألُ **المُترجِمَ نفسَه** لا يقارنُ ملفَّين: التغطيةُ الحقيقيّةُ هي ما
| يخرجُ من `__()` بعدَ دمجِ `lang/vendor/` فوقَ الحزمة، وهو ما يراه المستخدِم.
*/

/**
 * كلُّ مفاتيحِ ملفٍّ إنجليزيٍّ في الحزمةِ بصيغةٍ منقوطة.
 *
 * @return list<string>
 */
function vendorLangKeys(string $path): array
{
    /** @var mixed $data */
    $data = require $path;

    if (! is_array($data)) {
        return [];
    }

    $flatten = function (array $node, string $prefix) use (&$flatten): array {
        $keys = [];

        foreach ($node as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = [...$keys, ...$flatten($value, $dotted)];

                continue;
            }

            if (is_string($value) && $value !== '') {
                $keys[] = $dotted;
            }
        }

        return $keys;
    };

    return $flatten($data, '');
}

it('لا يترك نصّاً من Filament بلا عربيّة', function (): void {
    app()->setLocale('ar');

    /*
    | مساحةُ الترجمةِ لكلِّ حزمة. `filament/filament` وحدَها مساحتُها `filament`،
    | والبقيّةُ تحملُ بادئةَ اسمِها.
    */
    $namespaces = [
        /*
        | ⚠️ مساحةُ `filament/filament` اسمُها **`filament-panels`** لا `filament`
        | — و`filament` مساحةُ `filament/support`. نشرتُ ترجمةً تحتَ الاسمِ الخطأ
        | أوّلَ مرّةٍ فلم تُقرَأْ قطّ، وبَدَتْ ناجحةً لأنّ `__()` أعادَ ما كتبتُه
        | من ملفٍّ لا يقرؤه أحد. الاسمُ يُؤخَذُ من `->name()` في مزوّدِ الحزمة.
        */
        'filament/filament' => 'filament-panels',
        'filament/support' => 'filament',
        'filament/tables' => 'filament-tables',
        'filament/forms' => 'filament-forms',
        'filament/infolists' => 'filament-infolists',
        'filament/schemas' => 'filament-schemas',
        'filament/notifications' => 'filament-notifications',
        'filament/widgets' => 'filament-widgets',
        'filament/actions' => 'filament-actions',
        'filament/query-builder' => 'filament-query-builder',
    ];

    /*
    | ⚠️ استثناءاتٌ مقصودة، لا ثقوبٌ في الحارس. هذه رموزٌ لا تُترجَم: روابطُ
    | المنطقِ في بانيةِ الاستعلامِ تُعرَضُ كما هي في كلِّ لغة، ووحدةُ حجمِ الملفِّ
    | كذلك.
    */
    $untranslatable = [
        // روابطُ المنطقِ تُعرَضُ كما هي في كلِّ لغة.
        'filament-query-builder::query-builder.operators.and',
        'filament-query-builder::query-builder.operators.or',
        // اتّجاهُ الصفحةِ رمزُ CSS لا نصّ، وترجمتُه تكسرُ التخطيط.
        'filament-panels::layout.direction',
        // وحدةُ الزاوية.
        'filament-forms::components.file_upload.editor.fields.rotation.unit',
        // أنماطُ أسماءِ ملفّاتٍ تُنزَّل، لا جُمَلٌ تُقرأ.
        'filament-actions::export.file_name',
        'filament-actions::import.example_csv.file_name',
        'filament-actions::import.failure_csv.file_name',
    ];

    $english = [];
    $checked = 0;

    foreach ($namespaces as $package => $namespace) {
        $enDir = base_path('vendor/'.$package.'/resources/lang/en');

        if (! is_dir($enDir)) {
            continue;
        }

        foreach (Finder::create()->files()->in($enDir)->name('*.php')->depth(0) as $file) {
            $group = $file->getFilenameWithoutExtension();

            foreach (vendorLangKeys($file->getRealPath()) as $key) {
                $full = $namespace.'::'.$group.'.'.$key;

                if (in_array($full, $untranslatable, true)) {
                    continue;
                }

                $checked++;
                $translated = __($full);

                if (! is_string($translated)) {
                    continue;
                }

                /*
                | نصٌّ بلا حرفٍ عربيٍّ واحدٍ وفيه كلمةٌ لاتينيّة: إمّا مفتاحٌ غائبٌ
                | سقطَ إلى الإنجليزيّة، أو ترجمةٌ لم تُترجَم. ورمزٌ محضٌ (`:count`،
                | `%`) يمرُّ لأنّه ليس كلاماً.
                */
                if (preg_match('/\p{Arabic}/u', $translated) === 1) {
                    continue;
                }

                if (preg_match('/[A-Za-z]{3,}/u', $translated) !== 1) {
                    continue;
                }

                $english[] = $full.' → "'.$translated.'"';
            }
        }
    }

    // فحصٌ يمرُّ على لا شيءٍ أخطرُ من غيابِه.
    expect($checked)->toBeGreaterThan(400);

    expect($english)->toBe([]);
});
