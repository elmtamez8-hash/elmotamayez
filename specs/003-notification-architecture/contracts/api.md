# Contract: نقاط النهاية

**Feature**: `003-notification-architecture` | **Base**: `/api/v1`

كلها خلف `auth:sanctum` (لا مسار عام في هذه المرحلة). المفاتيح المكشوفة `uuid` حصراً
(الدستور VI). كل استجابة عبر API Resource.

---

## مركز الإشعارات — `Modules/Notifications/routes/api.php`

### `GET /notifications`

قائمة إشعارات المستخدم، من الأحدث.

| المُعامل | النوع | ملاحظات |
|---|---|---|
| `page` · `per_page` | int | الافتراضي ٢٠، الأقصى ٥٠ |
| `unread` | bool | غير المقروء فقط |
| `workspace` | uuid | **مُرشِّح اختياري** لا نطاق إجباري (research §R5) |
| `type` | string | نوع واحد |

```json
{
  "data": [{
    "uuid": "…", "type": "enrollment_created",
    "type_label": "تسجيل في كورس",
    "title": "تم تسجيلك في كورس", "body": "…",
    "action_url": "/courses/…",
    "subject": { "uuid": "…", "name": "…" },
    "workspace": { "uuid": "…", "name": "…" },
    "read_at": null, "created_at": "2026-08-05T12:00:00+03:00"
  }],
  "meta": { "current_page": 1, "last_page": 12, "total": 231, "unread_count": 7 }
}
```

`subject` و`workspace` قد يكونان `null` (إشعار على مستوى المنصة، أو بلا طالب).
**الحارس**: `recipient_user_id === $user->id` — بلا استثناء (FR-016 · SC-009).

### `GET /notifications/unread-count`

```json
{ "unread_count": 7 }
```

مسار منفصل لأن الجرس في الترويسة يستدعيه على كل صفحة ولا يحتاج الحمولة. يخدمه الفهرس المركّب
`(recipient_user_id, read_at, id)` (SC-008).

### `POST /notifications/{uuid}/read` · `POST /notifications/read-all`

عديم الأثر عند التكرار (FR-014): إشعار مقروء سلفاً يعود `200` بلا تغيير `read_at`.

```json
{ "unread_count": 6 }
```

`404` إن كان الإشعار لغير الطالب — **لا** `403`، فوجود الصفّ نفسه معلومة.

### `GET /notifications/types`

بيانات وصفية للواجهة: الأنواع بتسمياتها العربية، والقنوات **المُنفَّذة** وحدها (FR-030 — قناة
غير مُنفَّذة لا تظهر أصلاً).

```json
{
  "channels": [{ "key": "in_app", "label": "داخل المنصة" }],
  "types": [{
    "key": "payment_reminder", "label": "تذكير دفع",
    "default_channels": ["in_app"], "is_mandatory": true
  }]
}
```

### `GET /notifications/preferences` · `PUT /notifications/preferences`

```json
{ "preferences": [{ "type": "attendance_alert", "channels": ["in_app"], "digest_window_minutes": null }] }
```

`422` عند محاولة تعطيل نوع إلزامي (FR-029) أو اختيار قناة غير مُنفَّذة (FR-030) — الرسالة عربية
من `backend/lang/ar/` وتبيّن السبب.

### `PUT /notifications/quiet-hours`

```json
{ "quiet_hours_start": "22:00", "quiet_hours_end": "07:00", "timezone": "Asia/Qatar" }
```

`null` للحقلين معاً = بلا نافذة. النافذة قد تعبر منتصف الليل — تُحسب على ذلك.

---

## أولياء الأمور والأوصياء — `Modules/Identity/routes/api.php`

### `GET /family/relations`

يعود بما يخصّ الطالب أو الوصيّ حسب دور المُستدعي:

```json
{
  "data": [{
    "uuid": "…", "relation_type": "guardian", "relation_type_label": "وصيّ",
    "status": "active",
    "guardian": { "uuid": "…", "name": "…" },
    "student": { "uuid": "…", "name": "…" },
    "permissions": ["attendance", "results"]
  }]
}
```

**الحارس** (`ParentStudentRelationPolicy`):

| المُستدعي | يرى |
|---|---|
| الوصيّ / وليّ الأمر | صفوفه هو |
| الطالب | من يرتبط به |
| المدرّس أو مساعده | **فقط** إن كان الطالب يملك تسجيلاً نشطاً في كورس داخل مساحة عمله (NFR-001أ) |
| غيرهم | `403` |

### `POST /family/relations`

```json
{ "student_email": "…", "relation_type": "guardian", "permissions": ["attendance", "results"] }
```

يُنشئ العلاقة `pending` حتى يقبلها الطالب أو وليّ أمره. `422` عند محاولة إضافة **وليّ أمر ثانٍ**
نشط لطالب له وليّ أمر (FR-019 — مفروضة في `LinkGuardian`، الدستور II).

### `PATCH /family/relations/{uuid}` · `DELETE /family/relations/{uuid}`

تعديل الصلاحيات · إلغاء العلاقة. الإلغاء `status = revoked` + `revoked_at` — **لا حذف**،
فالأرشيف يبقى (FR-023). الإبلاغ يتوقّف فوراً، بما في ذلك تسليم **موزَّع سلفاً في الطابور**:
`DeliverNotificationJob` يعيد فحص العلاقة قبل التسليم.

---

## التحقّق من وسيلة التواصل

### `POST /contact-verifications` · `POST /contact-verifications/{uuid}/confirm`

```json
{ "channel": "whatsapp", "contact_value": "+974…" }
{ "code": "123456" }
```

محدود بـ `throttle:contact-verification` — محدّد **مسمّى** (FR-043). **يُمنع** الحدّ السطري
`throttle:5,1`: مفتاحه `domain|ip` بلا مسار، فيتشارك عدّاداً واحداً مع تسجيل الدخول والتصفّح
العام — وهو العطل الموثّق في `AppServiceProvider::registerRateLimiters()`.

`422` بعد ٥ محاولات خاطئة أو انتهاء الصلاحية (١٠ دقائق). الرمز **مُجزَّأ** في التخزين، فلا
يعود في أي استجابة ولا يظهر في أي سجلّ.

> **بلا مستهلك عند الإطلاق**: القناة داخل المنصة لا تحتاج وسيلة مُتحقَّقاً منها. المسار يُبنى
> ويُختبَر ويبقى جاهزاً لأول قناة خارجية.

---

## لوحة الإدارة (Filament — لا API)

القوالب وسجلّ التسليم موارد Filament لا نقاط نهاية: مستهلكهما الوحيد اللوحة، وبناء API لهما
يضاعف سطح التفويض بلا مستهلك ثانٍ.

| المورد | الصلاحية |
|---|---|
| `MessageTemplateResource` | `Permissions::NOTIFICATIONS_TEMPLATES_MANAGE` |
| `NotificationDeliveryResource` (قراءة فقط) | `Permissions::NOTIFICATIONS_LOGS_VIEW` |

سجلّ التسليم **لا يعرض** رقم هاتف ولا بريداً (FR-040) — الوسيلة ليست مخزَّنة فيه أصلاً
(data-model §2).

---

## الثوابت الجديدة — `Tenancy\Support\Permissions`

```php
public const NOTIFICATIONS_LOGS_VIEW = 'notifications.logs.view';
public const NOTIFICATIONS_TEMPLATES_MANAGE = 'notifications.templates.manage';
public const RELATIONS_VIEW_STUDENT = 'relations.view.student';
```

`RELATIONS_VIEW_STUDENT` تُمنح لدور المدرّس والمساعد — و**لا تكفي وحدها**: حارس التسجيل
(NFR-001أ) شرط ثانٍ مستقلّ يُفحص في السياسة. الصلاحية تقول «هذا الدور يجوز له مبدئياً»،
والحارس يقول «عن هذا الطالب بالذات».

**يُمنع** أي اسم صلاحية نصّي في الكود (الدستور V).

---

## أخطاء موحّدة

| الحالة | متى | نصّ الواجهة |
|---|---|---|
| `401` | جلسة منتهية | من `errors.ts` القائم |
| `403` | حارس السياسة | «لا تملك صلاحية لهذا الإجراء.» |
| `404` | إشعار أو علاقة لغير المستخدم | «العنصر المطلوب غير موجود أو حُذف.» |
| `422` | نوع إلزامي · قناة غير مُنفَّذة · وليّ أمر ثانٍ · رمز خاطئ | عربي من `lang/ar/` تحت حقله |
| `429` | تجاوز محدّد المعدّل | «محاولات كثيرة في وقت قصير…» |

كل حقل `FormRequest` جديد **يجب** أن يُضاف إلى `attributes` في `backend/lang/ar/validation.php`
وإلا ظهر باسمه البرمجي للمستخدم.
