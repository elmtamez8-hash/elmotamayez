# Quickstart: التحقّق من الحصة التجريبية لكل كورس

## المتطلبات

- الخادمان يعملان محلياً.
- حساب مدرّس يقرّر سعر كورس عامّ.
- البند ٢ يحتاج إعداد Bunny. محلياً تكفي الاختبارات الآلية بمزوّد مزيَّف.

## السيناريوهات

1. **التعليم والعرض (القصتان ٢ ثم ١)**
   - المدرّس يعلّم درساً مضمَّناً منشوراً، **غير معلَّم «مفتوح»**، حصةً تجريبية للكورس.
   - في نافذة خاصة:
     - كارت الكورس عليه شارة «حصة تجريبية مجانية».
     - صفحة الكورس فيها «شاهد حصة تجريبية مجاناً».
     - `/courses/{slug}/trial` يعرض الفيديو.

2. **المرفوع (القصة ١)**
   - درس فيديو على Bunny جاهز، ومعلَّم.
   - `GET /api/v1/marketplace/courses/{slug}/trial` يعيد `playback`.
   - `.../trial/stream` يعيد 302 إلى `*.b-cdn.net`، مع `Cache-Control: no-store`، و`expires` بعد حوالي ١٠ دقائق.
   - المشاهدة بلا علامة مائية، ولا طلب `renew` في الشبكة.

3. **الانتقال والإلغاء**
   - التعليم الثاني ينقل العلامة.
   - إلغاء من تبويب قديم يحمل `replacing` لدرس سابق لا يمسح الحصة الحالية.

4. **الرفض (القصتان ٢ و٦)**
   - 422 لكل من:
     - تسجيل حصة.
     - درس مجموعة.
     - درس عالي القيمة.
     - درس من كورس آخر.
     - فيديو محلي قديم.
   - 403 للمساعد.
   - `.../trial` لكورس بلا حصة يعيد 404 «غير متاح».

5. **صفحة المدرّس (القصتان ٣ و٤)**
   - كورس واحد بحصة: الزرّ يفتحها.
   - كورسان بحصة: قائمة.
   - لا حصص وفيديو تعريفي: «شاهد فيديو المدرّس»، ويعمل من تبويب «التقييمات».
   - لا شيء: «تصفّح كورسات المدرّس».

6. **لا تراجع (SC-004)**
   - `php vendor/bin/pest tests/Feature/Media` تنجح دون تعديل.
   - `npx vitest run src/components/player` تنجح دون تعديل اختبارات `VideoPlayer`.

7. **الحدّ**
   - ٣١ طلباً لـ`.../trial` من IP واحد في دقيقة: الأخير 429.
   - `/marketplace/teachers` من نفس IP لا يتأثّر.

## الاختبارات الآلية

- **الخادم**: `php vendor/bin/pest tests/Feature/Courses/CourseTrialLessonTest.php tests/Feature/Marketplace/CourseTrialDoorTest.php tests/Feature/Marketplace/PublicExposureTest.php tests/Feature/Marketplace/PublicPreviewLessonTest.php`
- **الواجهة**: `npx vitest run src/components/player src/components/marketplace src/components/courses`
