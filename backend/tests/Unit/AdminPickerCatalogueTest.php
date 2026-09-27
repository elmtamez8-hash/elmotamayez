<?php

declare(strict_types=1);

use App\Modules\Gamification\Support\BadgeIcon;
use App\Modules\Marketplace\Support\SubjectIcon;
use App\Modules\Settlement\Support\PayoutMethods;
use App\Modules\Tenancy\Filament\Resources\FeatureFlagResource;
use App\Shared\Support\Countries;
use Database\Seeders\GamificationCatalogSeeder;
use Database\Seeders\TaxonomySeeder;
use Symfony\Component\Finder\Finder;

/*
| ⛔ كلُّ حقلٍ كانَ نصّاً حرّاً في اللوحةِ وصارَ قائمةً مغلقةً له قارئٌ في مكانٍ
| آخر — الواجهة، أو البذرة، أو `Flags::enabled()`. القائمةُ التي لا تطابقُ قارئَها
| تُعيدُ العطبَ نفسَه بشكلٍ آخر: خيارٌ يُحفَظُ ولا يُرسَم، أو قيمةٌ يكتبُها الكودُ
| ولا يعرضُها المنتقي. كلُّ اختبارٍ هنا يقارنُ القائمةَ بقارئِها، لا بنفسِها.
|
| ⚠️ لا قاعدةَ بيانات: قراءةُ ملفّاتٍ وثوابت.
*/

/** Keys of one `const NAME: Record<…> = { … };` block in a frontend file, in order. */
function frontendRecordKeys(string $file, string $const): array
{
    $source = (string) file_get_contents(base_path('../frontend/'.$file));

    // The named block only — `subject-icon.ts` declares `BY_SLUG` above it.
    // Lazy up to the first `= {`: the type annotation carries `=>` of its own.
    preg_match('/const '.$const.'\b.*?=\s*\{(.*?)\}\s*;/s', $source, $block);

    preg_match_all('/^\s*"?([a-z0-9-]+)"?\s*:/m', $block[1] ?? '', $keys);

    return $keys[1];
}

it('offers exactly the subject icons the marketplace knows how to draw', function (): void {
    $frontend = frontendRecordKeys('src/components/marketplace/subject-icon.ts', 'BY_ICON_NAME');

    expect($frontend)->not->toBeEmpty()
        ->and(SubjectIcon::keys())->toBe($frontend);
});

it('seeds every subject with an icon the picker offers', function (): void {
    $seeded = array_unique(array_column(
        (new ReflectionClassConstant(TaxonomySeeder::class, 'SUBJECTS'))->getValue(),
        2,
    ));

    expect(array_values(array_diff($seeded, SubjectIcon::keys())))->toBe([]);
});

it('seeds every badge with an icon the picker offers', function (): void {
    $seeded = array_column(
        (new ReflectionClassConstant(GamificationCatalogSeeder::class, 'BADGES'))->getValue(),
        'icon',
    );

    expect($seeded)->not->toBeEmpty()
        ->and(array_values(array_diff($seeded, array_keys(BadgeIcon::LABELS))))->toBe([]);
});

it('names the signup countries exactly as the signup form does, in its order', function (): void {
    $source = (string) file_get_contents(base_path('../frontend/src/lib/countries.ts'));

    preg_match_all('/code:\s*"([A-Z]{2})",\s*name:\s*"([^"]+)"/u', $source, $rows);

    expect($rows[1])->not->toBeEmpty()
        ->and(Countries::SIGNUP)->toBe(array_combine($rows[1], $rows[2]))
        // And they lead the full list, so the picker opens on the markets that sell.
        ->and(array_slice(Countries::all(), 0, count(Countries::SIGNUP), true))->toBe(Countries::SIGNUP);
});

it('names a country outside the signup list in Arabic, and keeps an unknown code as it is', function (): void {
    expect(Countries::name('QA'))->toBe('قطر')
        ->and(Countries::name('qa'))->toBe('قطر')
        ->and(Countries::all())->toHaveKey('FR')
        ->and(Countries::name('FR'))->not->toBe('FR')
        ->and(Countries::name('ZZ'))->toBe('ZZ')
        ->and(Countries::name(null))->toBeNull()
        ->and(Countries::options('ZZ'))->toHaveKey('ZZ');
});

it('offers every feature flag the code asks about, and only those', function (): void {
    $declared = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match_all("/const FLAG = '([a-z0-9_]+)';/", $file->getContents(), $m) > 0) {
            array_push($declared, ...$m[1]);
        }
    }

    sort($declared);
    $offered = array_keys(FeatureFlagResource::knownKeys());
    sort($offered);

    expect($declared)->not->toBeEmpty()
        ->and($offered)->toBe($declared);
});

it('keeps every payout method inside the column the API writes', function (): void {
    // `teacher_payouts.method` is `string(32)` and the API validates `max:32`.
    foreach (PayoutMethods::ALL as $method) {
        expect(mb_strlen($method))->toBeLessThanOrEqual(32);
    }
});
