<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| ⛔ **MySQL يرفضُ أيَّ مُعرِّفٍ فوقَ ٦٤ حرفاً، وSQLite لا سقفَ له إطلاقاً — فلا
| اختبارٌ في هذه الشجرةِ يرى ذلك، وكلُّها تجري على SQLite.**
|
| قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٤: هجرةُ `course_waitlist_entries` مرَّت خضراءَ
| محلّيّاً وفي البناءِ الآليِّ كلِّه، ثمّ سقطَ النشرُ عندَها بـ
| `ERROR 1059 Identifier name … is too long` — الاسمُ المولَّدُ من ثلاثةِ أعمدةٍ
| على جدولٍ طويلِ الاسمِ بلغَ ٦٨ حرفاً. **وسقطَ معها كلُّ ما بعدَها**: هجرتا
| ردمِ صنفِ البياناتِ والقالب، فبقيَ الإنتاجُ بلا صفٍّ يُمسَحُ وبلا قالبٍ يُرسَل.
|
| ⚠️ **والحارسُ على المخطَّطِ كلِّه لا على جدولٍ واحد.** الفهارسُ تُقرَأُ من
| `sqlite_master` بعدَ أن تكونَ كلُّ الهجراتِ قد جرَت، فأيُّ هجرةٍ تُكتَبُ غداً
| تمرُّ من هنا بلا سطرٍ يُضافُ لها — وهي الطريقةُ الوحيدةُ لحراسةِ قيدٍ لا
| يُوجَدُ في المحرّكِ الذي تجري عليه الاختبارات.
|
| ⚠️ **ويشملُ أسماءَ الجداولِ كذلك**: السقفُ نفسُه، والاسمُ الطويلُ هو ما يجعلُ
| فهارسَه تتجاوزُه.
*/

/** السقفُ في MySQL، ولا علاقةَ له بالمحرّكِ الذي يقرأُ هذا. */
const MYSQL_IDENTIFIER_LIMIT = 64;

it('keeps every table name inside the limit of the engine this ships to', function (): void {
    $long = collect(DB::select("select name from sqlite_master where type = 'table'"))
        ->pluck('name')
        ->reject(fn (string $name): bool => str_starts_with($name, 'sqlite_'))
        ->filter(fn (string $name): bool => strlen($name) > MYSQL_IDENTIFIER_LIMIT)
        ->values()
        ->all();

    expect($long)->toBe([]);
});

it('keeps every index name inside the limit of the engine this ships to', function (): void {
    /*
    | ⚠️ **بلا استثناءٍ لفهرسٍ مولَّدٍ تلقائيّاً**: المولَّدُ هو بالضبطِ ما سقطَ.
    | والعلاجُ سطرٌ واحدٌ في الهجرة — اسمٌ مكتوبٌ بيدٍ كمُعامِلٍ ثانٍ لـ
    | `unique()`/`index()` — لا تقصيرُ اسمِ الجدولِ بعدَ أن صارَ في الكود.
    */
    $long = collect(DB::select("select name from sqlite_master where type = 'index' and name is not null"))
        ->pluck('name')
        ->reject(fn (string $name): bool => str_starts_with($name, 'sqlite_autoindex_'))
        ->filter(fn (string $name): bool => strlen($name) > MYSQL_IDENTIFIER_LIMIT)
        ->values()
        ->all();

    expect($long)->toBe([]);
});
