<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Support\TimezoneLabel;
use App\Shared\Support\UserClock;
use Carbon\CarbonImmutable;

/*
| ⛔ **اسمٌ واحدٌ للمنطقةِ الزمنيّة، بصيغةِ «البلد — المدينة»** (قرارُ المالك
| ٢٠٢٦-٠٩-٢٧).
|
| كانت ثلاثُ خرائط: منتقي الإعدادات يقول «قطر — الدوحة»، و`timezoneLabel()` في
| الواجهةِ يقول «توقيت قطر»، ونسخةُ الخادمِ في الإشعاراتِ تقولُ «توقيت قطر» —
| ثلاثةُ أسماءٍ لساعةٍ واحدة. الواجهةُ الآن تقرأُ من `timezone-names.ts` وحدَه،
| والخادمُ لا يستطيعُ استيرادَ ملفِّ TypeScript، فنسختُه تُقارَنُ هنا بالأصل حرفاً
| بحرف: مُدخَلٌ يُضافُ إلى أحدِهما دونَ الآخرِ يُسقِطُ البناء.
|
| ⚠️ ولا قاعدةَ بياناتٍ هنا: قراءةُ ملفٍّ ومقارنة.
*/
it('names every zone exactly as the frontend picker does', function (): void {
    $source = (string) file_get_contents(base_path('../frontend/src/lib/timezone-names.ts'));

    preg_match_all('/^\s+"?([A-Za-z_\/]+)"?:\s*"([^"]+)",?\s*$/mu', $source, $matches, PREG_SET_ORDER);

    $frontend = [];

    foreach ($matches as $match) {
        $frontend[$match[1]] = $match[2];
    }

    // ⚠️ `toBe` compares ORDER too, on purpose: the two maps are kept in the same
    // order so a diff between them reads line for line. Re-sorting one side
    // fails here — re-sort both.
    expect($frontend)->not->toBeEmpty()
        ->and($frontend)->toHaveKey('Asia/Qatar')
        ->and(TimezoneLabel::LABELS)->toBe($frontend);
});

it('keeps the zone name when nobody has translated it', function (): void {
    expect(TimezoneLabel::for('Asia/Qatar'))->toBe('قطر — الدوحة')
        ->and(TimezoneLabel::for('Africa/Cairo'))->toBe('مصر — القاهرة')
        // Not a country: «(توقيت غرينتش (UTC))» in a sentence, never «توقيت التوقيت».
        ->and(TimezoneLabel::for('UTC'))->toBe('غرينتش (UTC)')
        ->and(TimezoneLabel::for('Pacific/Fiji'))->toBe('Pacific/Fiji');
});

it('reads a UTC clock as Greenwich in a sentence, with no doubled word', function (): void {
    $utc = (new User)->forceFill(['timezone' => 'UTC']);

    expect(UserClock::format($utc, CarbonImmutable::parse('2026-11-18 15:00', 'UTC')))
        ->toBe('2026-11-18 15:00 (توقيت غرينتش (UTC))');
});
