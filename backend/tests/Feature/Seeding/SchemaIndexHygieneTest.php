<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| ⛔ **فهرسانِ على العمودِ نفسِه شجرةٌ تُكتَبُ مع كلِّ صفٍّ ولا تُقرَأُ أبداً — ولا
| شيءَ في هذه الشجرةِ كانَ يراه.**
|
| قِيسَ على قاعدةٍ حقيقيّةٍ في ٢٠٢٦-٠٩-١٩: `credit_purchases.order_id` تحملُ
| `credit_purchases_order_id_index` **و**`credit_purchases_order_id_unique` معاً.
| والسببُ ليسَ سهواً: هجرةُ ٢٠٢٦-٠٩-٠٩ فتحَت وصفَها بـ«had no index at all»
| و«the table carried exactly two indexes» — **وكلتا الجملتَينِ خطأ**، فالفهرسُ
| العاديُّ كانَ موجوداً منذُ شهر. أي أنّ الكاتبَ قرأَ الجدولَ ولم يقرأْ ما سبقَه،
| فكتبَ ادّعاءَ غيابٍ وادّعاءَ حصرٍ — وهما بالضبطِ النوعانِ اللذانِ تقولُ قاعدةُ
| هذا المستودعِ إنّهما يُقاسانِ قبلَ أن يُكتَبا.
|
| ⚠️ **والحارسُ على المخطَّطِ كلِّه لا على ذلكَ الجدول.** يُقرَأُ بعدَ أن تجريَ
| كلُّ الهجرات، فأيُّ هجرةٍ تُكتَبُ غداً تمرُّ من هنا بلا سطرٍ يُضافُ لها — وهي
| قاعدةُ `SchemaIdentifierLengthTest` جنبَه.
|
| ⚠️ **والتطابقُ تامٌّ لا بادئة.** فهرسٌ مفرَدٌ هو بادئةُ فهرسٍ مركَّبٍ زائدٌ
| نظريّاً وقد يكونُ مقصوداً (ترتيبٌ مختلفٌ، عمودٌ يُقرَأُ وحدَه)؛ أمّا القائمةُ
| نفسُها بالترتيبِ نفسِه فلا وجهَ لها إطلاقاً.
*/

/**
 * أعمدةُ كلِّ فهرسٍ على هذا الجدول: اسمُ الفهرسِ ⇒ «عمود,عمود».
 *
 * ⚠️ `sqlite_autoindex_*` مُستثنىً: يُولِّدُه المحرّكُ لقيدِ `UNIQUE` مُعلَنٍ في
 * `CREATE TABLE` نفسِه، فهو القيدُ لا فهرساً ثانياً كتبَه أحد.
 *
 * @return array<string, string>
 */
function indexColumnsOn(string $table): array
{
    $out = [];

    foreach (DB::select("pragma index_list('".$table."')") as $index) {
        $name = (string) $index->name;

        if (str_starts_with($name, 'sqlite_autoindex_')) {
            continue;
        }

        $columns = array_map(
            static fn (object $column): string => (string) $column->name,
            DB::select("pragma index_info('".$name."')"),
        );

        $out[$name] = implode(',', $columns);
    }

    return $out;
}

it('never indexes one column list twice on one table', function (): void {
    $duplicates = [];

    foreach (DB::select("select name from sqlite_master where type = 'table'") as $row) {
        $table = (string) $row->name;

        if (str_starts_with($table, 'sqlite_')) {
            continue;
        }

        $byColumns = [];

        foreach (indexColumnsOn($table) as $name => $columns) {
            $byColumns[$columns][] = $name;
        }

        foreach ($byColumns as $columns => $names) {
            if (count($names) > 1) {
                sort($names);
                $duplicates[] = $table.'('.$columns.'): '.implode(' + ', $names);
            }
        }
    }

    sort($duplicates);

    expect($duplicates)->toBe([]);
});

it('keeps the index the cohort bridge actually reads', function (): void {
    /*
    | ⛔ **النصفُ الموجِب، وبدونَه يمرُّ التوكيدُ فوقَه على شجرةٍ بلا فهارسَ
    | إطلاقاً.** `PlanReach::naming()` هي `whereIn('workspace_id') +
    | whereIn('coverage_uuid')` حرفيّاً، وتُسأَلُ في كلِّ صفحةِ كورسٍ عامّةٍ
    | وكلِّ قائمةِ مجموعاتٍ يفتحُها طالب.
    */
    expect(indexColumnsOn('plans'))->toContain('workspace_id,coverage_uuid');
});
