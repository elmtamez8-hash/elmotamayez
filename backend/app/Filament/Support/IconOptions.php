<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * خياراتُ منتقي أيقونة، كلُّ خيارٍ بمعاينتِه — لـ`Select::allowHtml()`.
 *
 * ⚠️ `allowHtml()` يحتاجُ `native(false)` بجانبِه، وإلّا طُبِعَ الوسمُ نصّاً في
 * قائمةِ المتصفّحِ الأصليّة. والنصُّ العربيُّ مُهرَّبٌ هنا بـ`e()`: الـHTML الوحيدُ
 * الذي يمرُّ هو رسمُ الأيقونةِ من حزمةِ Heroicons نفسِها.
 *
 * مفتاحٌ مخزَّنٌ خارجَ القائمةِ (صفٌّ قديمٌ كُتِبَ نصّاً حرّاً) يُضافُ إلى الخياراتِ
 * باسمِه كما هو، فلا تُفرِغُ شاشةُ التعديلِ الحقلَ ولا ترفضُ حفظاً لم يمسَّه.
 */
final class IconOptions
{
    /**
     * @param  array<string, string>  $labels  heroicon name => Arabic label
     * @return array<string, string>
     */
    public static function html(array $labels, mixed $current = null): array
    {
        if (is_string($current) && $current !== '' && ! array_key_exists($current, $labels)) {
            $labels[$current] = $current.' (قيمة قديمة)';
        }

        $options = [];

        foreach ($labels as $key => $label) {
            $options[$key] = '<span style="display:inline-flex;align-items:center;gap:.5rem">'
                .self::svg($key).'<span>'.e($label).'</span></span>';
        }

        return $options;
    }

    /** The heroicon's markup, or nothing for a name the icon set does not have. */
    public static function svg(string $heroicon): string
    {
        try {
            return svg('heroicon-o-'.$heroicon, '', ['style' => 'width:1.25rem;height:1.25rem;flex:none'])->toHtml();
        } catch (\Throwable) {
            return '';
        }
    }
}
