# خطُّ الأساسِ الأخضر — قبلَ استخراجِ `LessonGate` (T004)

**التاريخ**: 2026-08-27 · **الفرع**: `master` · **قبلَ أيِّ تعديلٍ في `Enrollment::accessTo()`**

هذا الملفُّ شبكةُ الأمانِ لـT005–T007. الاستخراجُ **يجبُ** أن يُعيدَ الأرقامَ نفسَها **بلا تعديلِ حرفٍ في أيِّ ملفِّ اختبار**. تعديلُ اختبارٍ قائمٍ لإرضائِه يعني أنّ الاستخراجَ غيّرَ معنًى — وهو الطريقُ الوحيدُ لفتحِ درسٍ في المنتَجِ كلِّه.

## المدى — لا `tests/Feature/Learning` وحدَها

`accessTo()` / `canAccessLesson()` تُقرَأُ من **أحدَ عشرَ ملفَّ اختبارٍ في أربعِ وحدات**، فقصرُ الشبكةِ على `Learning` يتركُ بوّابةَ الامتحانِ وشجرةَ الترتيبِ وتسجيلَ الحصّةِ خارجَها:

```
tests/Feature/Learning/LessonAccessTest.php
tests/Feature/Learning/LessonPlayerWithoutWorkspaceTest.php
tests/Feature/Learning/RecordingProgressTest.php
tests/Feature/Courses/ExamGateTest.php
tests/Feature/Courses/DraftExposureTest.php
tests/Feature/Courses/LessonTypeTest.php
tests/Feature/Courses/TreeOrderingTest.php
tests/Feature/Courses/StructureConcurrencyTest.php
tests/Feature/LiveSessions/RecordingAccessTest.php
tests/Feature/Compliance/RecordingAccessTest.php
tests/Feature/Compliance/OffboardingContentAccessTest.php
```

## الأرقام

```bash
php vendor/bin/pest tests/Feature/Learning
# tests: 17 · passed: 17 · assertions: 46
```

```bash
php vendor/bin/pest tests/Feature/Learning tests/Feature/Courses \
    tests/Feature/LiveSessions/RecordingAccessTest.php \
    tests/Feature/Compliance/RecordingAccessTest.php \
    tests/Feature/Compliance/OffboardingContentAccessTest.php
# tests: 138 · passed: 138 · assertions: 420
```

**الرقمُ المُلزِمُ في T007 هو الثاني**: ١٣٨ ناجحاً و٤٢٠ تأكيداً.
