<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Support\TimezoneLabel;
use App\Shared\Support\TimezoneName;
use App\Shared\Support\UserClock;
use Carbon\CarbonImmutable;

/*
| ⛔ **اسمٌ واحدٌ للمنطقةِ الزمنيّة، بصيغةِ «البلد — المدينة»** (قرارُ المالك
| ٢٠٢٦-٠٩-٢٧).
|
| كانت ثلاثُ خرائط: منتقي الإعدادات يقول «قطر — الدوحة»، و`timezoneLabel()` في
| الواجهةِ يقول «توقيت قطر»، ونسخةُ الخادمِ في الإشعاراتِ تقولُ «توقيت قطر» —
| ثلاثةُ أسماءٍ لساعةٍ واحدة. الأسماءُ الآن في ملفِّ JSON واحد له نسختان: نسخةُ
| الواجهة (`frontend/src/lib/timezone-names.json`) ونسخةُ الخادم بجوار
| `TimezoneLabel`، لأنّ كلَّ تطبيقٍ يُبنى وحدَه ولا يرى ملفَّ الآخر. تُقارَنُ
| النسختان هنا: مُدخَلٌ يُضافُ إلى إحداهما دونَ الأخرى يُسقِطُ البناء.
|
| ⚠️ ولا قاعدةَ بياناتٍ هنا: قراءةُ ملفٍّ ومقارنة.
*/
it('names every zone exactly as the frontend picker does', function (): void {
    // Decoded, not compared as bytes: a line-ending difference on a Windows
    // checkout is not a naming difference.
    $frontend = json_decode(
        (string) file_get_contents(base_path('../frontend/src/lib/timezone-names.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // ⚠️ `toBe` compares ORDER too, on purpose: both files are sorted by zone
    // name, so a diff between them reads line for line.
    expect($frontend)->toBeArray()->not->toBeEmpty()
        ->and(TimezoneLabel::labels())->toBe($frontend);
});

/*
| «ترجم أسماء باقي المحافظات والدول» (المالك، ٢٠٢٦-٠٩-٢٧): كلُّ منطقةٍ يقبلُها
| الخادمُ لها اسمٌ عربيّ، ولا تتشابهُ منطقتان في الاسم.
*/
it('names every zone the server accepts, and no two alike', function (): void {
    $zones = timezone_identifiers_list();
    $labels = array_map(fn (string $zone): string => TimezoneLabel::for($zone), $zones);

    expect(array_values(array_filter($zones, fn (string $zone): bool => TimezoneLabel::for($zone) === $zone)))->toBe([])
        ->and(array_keys(array_filter(array_count_values($labels), fn (int $n): bool => $n > 1)))->toBe([]);
});

it('names only real zones, so a typo in a key cannot hide an unnamed one', function (): void {
    $known = timezone_identifiers_list(DateTimeZone::ALL_WITH_BC);

    expect(array_values(array_diff(array_keys(TimezoneLabel::labels()), $known)))->toBe([]);
});

it('keeps the zone name when the map does not know it', function (): void {
    expect(TimezoneLabel::for('Asia/Qatar'))->toBe('قطر — الدوحة')
        ->and(TimezoneLabel::for('Africa/Cairo'))->toBe('مصر — القاهرة')
        // Not a country: «(توقيت غرينتش (UTC))» in a sentence, never «توقيت التوقيت».
        ->and(TimezoneLabel::for('UTC'))->toBe('غرينتش (UTC)')
        ->and(TimezoneLabel::for('Pacific/Fiji'))->toBe('فيجي — سوفا')
        ->and(TimezoneLabel::for('Nowhere/Nothing'))->toBe('Nowhere/Nothing');
});

it('reads a UTC clock as Greenwich in a sentence, with no doubled word', function (): void {
    $utc = (new User)->forceFill(['timezone' => 'UTC']);

    expect(UserClock::format($utc, CarbonImmutable::parse('2026-11-18 15:00', 'UTC')))
        ->toBe('2026-11-18 15:00 (توقيت غرينتش (UTC))');
});

/*
| ⛔ الأسماءُ القديمةُ التي يقولُها المتصفّح (`Asia/Calcutta`) تُخزَّنُ بأسمائها
| الجديدة (`Asia/Kolkata`) — قرارُ المالك ٢٠٢٦-٠٩-٢٧. الخريطةُ ملفٌّ بنسختين مثلُ
| الأسماء، وكلُّ سطرٍ فيها يُطابِقُ ما تقولُه ICU.
*/
it('folds old spellings exactly as the frontend does', function (): void {
    $frontend = json_decode(
        (string) file_get_contents(base_path('../frontend/src/lib/timezone-aliases.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($frontend)->toBeArray()->not->toBeEmpty()
        ->and(TimezoneName::aliases())->toBe($frontend);
});

it('folds each old spelling to a zone PHP lists, as ICU says, and names both alike', function (): void {
    $listed = timezone_identifiers_list();

    foreach (TimezoneName::aliases() as $alias => $zone) {
        expect(in_array($alias, $listed, true))->toBeFalse()
            ->and(in_array($zone, $listed, true))->toBeTrue()
            ->and(TimezoneLabel::for($alias))->toBe(TimezoneLabel::for($zone));

        if (method_exists(IntlTimeZone::class, 'getIanaID')) {
            expect(IntlTimeZone::getIanaID($alias))->toBe($zone);
        }
    }

    expect(TimezoneName::canonical('Asia/Calcutta'))->toBe('Asia/Kolkata')
        ->and(TimezoneName::canonical('Asia/Qatar'))->toBe('Asia/Qatar');
});
