<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Lesson;

/*
| ⚠️ اختبارُ **نصِّ SQL**، وهو استثناءٌ مقصودٌ في شجرةٍ تقيسُ السلوك.
|
| `ReferenceIntegrity::apply()` كان يبني `$exists->select(1)`، ولارافيل يعاملُ
| وسيطَ `select()` كاسمِ عمودٍ فيلفُّه بعلاماتٍ خلفيّة: `select `1``.
|
| **MySQL يرُدُّ `1054 Unknown column '1' in 'field list'`؛ وSQLite يقبلُ
| معرِّفاً مقتبَساً لا يطابقُ عموداً ويعاملُه كنصّ.** وكلُّ اختبارٍ هنا يعملُ على
| SQLite في الذاكرة — فلا فحصٌ سلوكيٌّ واحدٌ يستطيعُ رؤيةَ هذا العطل، مهما بلغَ
| عددُه. ظهرَ في أوّلِ MySQL حقيقيّ، عندَ بذرِ الإنتاج.
|
| والثمنُ يستحقُّ الحارس: الدالّةُ تعملُ داخلَ `progressEligible`، أي في **مقامِ
| نسبةِ الإنجاز** وفي قراءاتِ الطالبِ الثلاث. فمادّةٌ فيها امتحانٌ أو حصّةٌ ترُدُّ
| ٥٠٠، ونسبةُ التقدُّمِ لا تُحسَبُ، و`CourseCompleted` لا يُطلَقُ، ولا تصدرُ شهادةٌ
| لأحدٍ أبداً — عائلةُ العطلِ التي يُسمّيها `CLAUDE.md` الأسوأَ في المنتَج.
|
| لذلك يُقاسُ ما يُرسَلُ إلى المحرّكِ لا ما يعودُ منه.
*/

it('يبني الرقمَ ١ قيمةً حرفيّةً لا معرِّفاً مقتبَساً', function (): void {
    $sql = Lesson::query()->progressEligible()->toSql();

    // ما يقتلُ MySQL: الرقمُ ملفوفاً كاسمِ عمود.
    expect($sql)->not->toContain('select `1`')
        ->and($sql)->not->toContain('select "1"')
        ->and($sql)->not->toContain("select '1'");

    // وما يجبُ أن يكونَ هناك: وجودُ الفحصَين أصلاً، وإلّا مرَّ الاختبارُ فارغاً.
    expect($sql)->toContain('exists')
        ->and($sql)->toContain('exams')
        ->and($sql)->toContain('class_sessions')
        ->and($sql)->toContain('select 1');
});

/*
| والاتّجاهُ السلوكيّ إلى جانبِه: الاستعلامُ يُنفَّذُ فعلاً. يمرُّ على SQLite في
| الحالتَين، فهو لا يحرسُ شيئاً وحدَه — لكنّ سقوطَه يعني أنّ الحارسَ الأعلى
| يقيسُ استعلاماً لم يعُدْ يعمل.
*/
it('الاستعلامُ ينفَّذُ', function (): void {
    expect(Lesson::query()->progressEligible()->count())->toBeInt();
});
