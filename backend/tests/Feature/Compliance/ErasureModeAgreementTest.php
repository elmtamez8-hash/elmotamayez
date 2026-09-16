<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\DataCategory;
use App\Modules\Compliance\Support\PersonalDataRegistry;
use App\Shared\Contracts\PersonalDataOwner;

/*
| ⛔ وحدةٌ تختلفُ أوضاعُ مسحِ فئاتِها لا تمسحُ شيئاً على الإطلاق.
|
| `ExecuteDataErasure::modeFor()` يجمعُ أوضاعَ كلِّ فئاتِ الوحدةِ ويطلبُها
| **مُجمَعةً**: فإن اختلفَت سجَّلَ `unclear_mode` ورجعَ بـ`Retain`، فلا اسمٌ
| يُجهَّلُ ولا بريدٌ ولا هاتفٌ ولا تاريخُ ميلاد — لوحدةٍ كاملة.
|
| ⚠️ **وقعَ هذا فعلاً في ٢٠٢٦-٠٩-١٦**: فئةٌ واحدةٌ أُضيفَت لـ`Identity` بوضعِ
| `Delete` بينما أخواتُها الأربعُ `Anonymise`، فأطفأَتِ المسحَ في الوحدةِ كلِّها.
| والحزمةُ مسكَتْها — لكنّ رسالتَها كانت «الاسمُ ما زالَ Zubaida» في خمسِ حالاتٍ
| متفرّقة: عَرَضٌ يبعُدُ عن سببِه خمسةَ ملفّات. وهذا الملفُّ يُسمّي السبب.
|
| ⚠️ ويقرأُ السِّجِلَّ لا قائمةً مكتوبةً بيد: وحدةٌ تُضافُ غداً محروسةٌ بلا سطر.
*/

it('keeps every module of one mind about how it erases', function (): void {
    $disagreeing = [];

    foreach (app(PersonalDataRegistry::class)->all() as $owner) {
        /** @var PersonalDataOwner $owner */
        $modes = DataCategory::query()
            ->whereIn('key', $owner->describe())
            ->pluck('erasure_mode')
            ->map(fn (mixed $mode): string => is_object($mode) ? (string) $mode->value : (string) $mode)
            ->unique()
            ->values()
            ->all();

        if (count($modes) > 1) {
            $disagreeing[$owner->moduleKey()] = $modes;
        }
    }

    expect($disagreeing)->toBe(
        [],
        'A module whose categories disagree erases NOTHING — `modeFor()` falls back to Retain.',
    );
});

/*
| ⚠️ والاتّجاهُ المقابل: وحدةٌ تُصرِّحُ بفئةٍ لا صفَّ لها في الكتالوجِ تُنتِجُ
| قائمةَ أوضاعٍ أقصرَ من قائمةِ فئاتِها — فتمرُّ الحالةُ أعلاه بينما `modeFor()`
| يقرأُ عن ثلاثٍ ويُصرَّحُ بأربع.
*/
it('finds a catalogue row for every category a module declares', function (): void {
    $undeclared = [];

    foreach (app(PersonalDataRegistry::class)->all() as $owner) {
        /** @var PersonalDataOwner $owner */
        $known = DataCategory::query()->whereIn('key', $owner->describe())->pluck('key')->all();

        $missing = array_values(array_diff($owner->describe(), $known));

        if ($missing !== []) {
            $undeclared[$owner->moduleKey()] = $missing;
        }
    }

    expect($undeclared)->toBe([]);
});
