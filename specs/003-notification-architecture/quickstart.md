# Quickstart — التحقّق من بنية الإشعارات

**Feature**: `003-notification-architecture`

دليل تشغيل يثبت أن الميزة تعمل من طرف إلى طرف. الكيانات في [data-model.md](./data-model.md)،
والعقود في [contracts/](./contracts/).

---

## المتطلّبات

```bash
cd backend
cp .env.example .env && php artisan key:generate     # أول مرة فقط
php artisan migrate:fresh --seed                     # يشمل NotificationTemplateSeeder
```

Redis **غير مطلوب محلياً**: اضبط `QUEUE_CONNECTION=sync` لترى التسليم فورياً. للتحقّق من السلوك
المطبور (التأجيل، إعادة المحاولة) شغّل Redis واضبط `QUEUE_CONNECTION=redis` ثم:

```bash
php artisan queue:work --queue=notifications-high,notifications,default
```

الواجهة:

```bash
cd frontend && npm run dev      # :3000 — يوجّه /api/* إلى :8000
```

---

## البوابات الأربع (SC-018)

```bash
cd backend
php vendor/bin/pest
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
cd ../frontend && npx tsc --noEmit
```

الأربع خضراء شرط قبول. **يُمنع** baseline جديد أو `@phpstan-ignore` لتمريرها.

---

## السيناريو ١ — القناة تُضاف بملف وسطر (SC-001 · SC-003)

```bash
php vendor/bin/pest tests/Feature/Notifications/ChannelContractTest.php
```

**المتوقّع**: `FakeChannel` مسجَّلة في بيئة الاختبار وحدها تستقبل **كل** أنواع الإشعارات، بلا
تعديل سطر واحد في أي مستمع أو `Action`. وحالة ثانية تُفشل قناة وتتحقّق من أن الأخرى سُلِّمت —
عزل الفشل (FR-006).

**الفحص المرافق**:

```bash
php vendor/bin/pest tests/Feature/Notifications/ProviderAgnosticTest.php
```

يمسح كل `Actions/` بحثاً عن ذكر قناة أو مزوّد ويفشل عند أي تطابق (SC-002).

---

## السيناريو ٢ — مركز الإشعارات (SC-004 · SC-008 · SC-009)

```bash
php artisan tinker
```

```php
$student = App\Models\User::where('email', 'student@example.com')->first();
$enrollment = $student->enrollments()->first();
event(new App\Modules\Learning\Events\EnrollmentCreated($enrollment));
```

ثم:

```bash
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/notifications
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/notifications/unread-count
```

**المتوقّع**: **صفّ واحد** في `notifications` مهما تعدّدت قنواته (FR-007)، وصفّ في
`notification_deliveries` لكل قناة. `unread_count = 1`.

في المتصفّح: `http://localhost:3000/notifications` — القائمة عربية RTL، والجرس في الترويسة
يحمل العدّاد.

**قياس الأداء (SC-008)**:

```bash
php vendor/bin/pest tests/Feature/Notifications/NotificationCenterTest.php --filter="10,000"
```

يبذر ١٠٬٠٠٠ إشعار ويؤكّد أن الصفحة الأولى والعدّاد ضمن ٣٠٠ مللي ثانية.

**التسريب (SC-009)**: حالة في نفس الملف تؤكّد أن مستخدماً آخر يحصل على `404` على
`/notifications/{uuid}` لإشعار ليس له.

---

## السيناريو ٣ — أولياء الأمور والأوصياء (SC-010 · SC-011)

```bash
php vendor/bin/pest tests/Feature/Notifications/GuardianDeliveryTest.php
```

**المتوقّع**:
- طالب له وليّ أمر ووصيَّان مخوَّلون ⇒ **ثلاثة** سجلات إشعار (واحد لكل مستلم)، وكلٌّ منها سجلّ
  واحد لا يتكرّر بعدد قنواته (SC-010).
- وصيّ بلا صلاحية `payments` **لا** يستقبل `payment_reminder` (SC-011 · FR-022).
- علاقة أُلغيت **أثناء وجود الوظيفة في الطابور** ⇒ لا تسليم بعد الإلغاء.

**يدوياً**: `http://localhost:3000/family` — إضافة وصيّ، ضبط صلاحياته، إلغاء العلاقة.

**حارس المدرّس (NFR-001أ)**:

```bash
php vendor/bin/pest tests/Feature/Notifications/PlatformOwnershipTest.php
```

يؤكّد أمرين: مدرّس **لا** يقرأ علاقات طالب غير مسجَّل عنده، والطالب يرى **تفضيلاً واحداً
وعلاقةً واحدة وتدفّقاً واحداً** عبر ثلاثة مدرّسين — لا ثلاث نسخ (NFR-001ب).

---

## السيناريو ٤ — التفضيلات (SC-012 · SC-013)

```bash
curl -X PUT -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"preferences":[{"type":"attendance_alert","channels":[]}]}' \
  http://localhost:8000/api/v1/notifications/preferences
```

**المتوقّع**: `200`. ثم إطلاق حدث من ذلك النوع ⇒ **لا** تسليم، بينما الأنواع الأخرى تُسلَّم
(SC-012).

```bash
curl -X PUT … -d '{"preferences":[{"type":"payment_reminder","channels":[]}]}'
```

**المتوقّع**: `422` برسالة عربية تبيّن أن النوع إلزامي (SC-013 · FR-029).

```bash
curl -X PUT … -d '{"preferences":[{"type":"exam_result","channels":["whatsapp"]}]}'
```

**المتوقّع**: `422` — القناة غير مُنفَّذة (FR-030). وهي لا تظهر أصلاً في
`GET /notifications/types`.

---

## السيناريو ٥ — أوقات الهدوء (SC-014)

> يحتاج قناة **خارجية**، وكلها غير مُنفَّذة عند الإطلاق — فيُثبَت بقناة خارجية مزيّفة
> (research §R8).

```bash
php vendor/bin/pest tests/Feature/Notifications/QuietHoursTest.php
```

**المتوقّع**: إشعار غير إلزامي داخل النافذة ⇒ `deferred_until` مضبوط والوظيفة مؤجَّلة، ثم
**يُسلَّم** بعد انتهاء النافذة (لا يُسقَط — FR-033). وإشعار إلزامي في الوقت نفسه ⇒ يُسلَّم فوراً
بلا تأجيل ولا تجميع (FR-035).

---

## السيناريو ٦ — القوالب وسجلّ التسليم (SC-015)

```bash
php artisan serve      # /admin
```

في اللوحة: **الإشعارات ← القوالب** ← عدّل نصّ `enrollment_created.in_app` ← احفظ. أطلق الحدث
مرة أخرى ⇒ الرسالة الجديدة تحمل النصّ المعدَّل **بلا نشر كود** (SC-015)، والإشعارات القديمة
تبقى بنصّها الأصلي.

**الإشعارات ← سجلّ التسليم**: لكل محاولة المستلم والنوع والقناة والقالب والحالة وعدد المحاولات
وسبب الفشل. مستخدم بلا `notifications.logs.view` ⇒ المورد غير ظاهر له أصلاً (FR-039).

**التحقّق من الخصوصية (FR-040)**: لا عمود هاتف ولا بريد في السجلّ — غير مخزَّن أصلاً.

---

## السيناريو ٧ — الترحيل (SC-016)

```bash
php vendor/bin/pest tests/Feature/Notifications/MigrationBackfillTest.php
```

**المتوقّع**:
- كل صفّ في `parent_child_links` انتقل إلى `parent_student_relations` بـ `relation_type=parent`
  والصلاحيات الخمس و`status=active` — **صفر فقد** (FR-024).
- `session_alerts=false` انتقل إلى تفضيلات فارغة على `attendance_alert` و`appointment_reminder`
  (FR-031).
- من لم يغيّر تفضيلاته ⇒ **بلا صفوف**، والافتراضي يسري (FR-028).

---

## السيناريو ٨ — العزل والأداء تحت الفشل (SC-005 · SC-006 · SC-007)

```bash
php vendor/bin/pest tests/Feature/Notifications/DeliveryLifecycleTest.php
```

**المتوقّع**:
- سلسلة الأحداث الأربعة بترتيبها لكل إرسال (SC-007).
- خطأ عابر ⇒ ٥ محاولات بتراجع تصاعدي · `PermanentDeliveryException` ⇒ **صفر** إعادة (SC-006).
- قناة تنام ثانيتين **لا** تزيد زمن العملية المُطلِقة (SC-005) — لأن الإرسال في وظيفة مطبورة.

---

## المسارات الحرجة (الدستور IV)

ملفّان قائمان يعتمدان على البنية القديمة، لا ثلاثة:

```bash
php vendor/bin/pest tests/Feature/Marketplace/TeacherApplicationTest.php
php vendor/bin/pest tests/Feature/Payments/PaymentTest.php
```

- `TeacherApplicationTest` — ٣ مواضع `Notification::assertSentTo`.
- `PaymentTest:127` — يقرأ `DatabaseNotification` بمخطّط Laravel. **مسار حرج**
  (اعتماد الدفع ← إنشاء التسجيل).

**يجب** أن يبقيا أخضرين بتأكيدات محدَّثة على البنية الجديدة — **لا** بحذف التأكيد.

### تحقّق من أن المصادقة لم تنكسر (research §R14)

```bash
php vendor/bin/pest --filter="password|verif"
```

استعادة كلمة المرور وتوثيق البريد **يجب** أن تبقيا عاملتين على بريد الإطار رغم أن `email`
قناة إشعارات غير مُنفَّذة. `Notifiable` يبقى على `User`.

---

## تنظيف

```bash
cd backend && php artisan migrate:fresh --seed
```

> **تذكير**: **يُمنع** تشغيل `npm run build` وخادم `npm run dev` معاً — كلاهما يكتب في `.next/`.
