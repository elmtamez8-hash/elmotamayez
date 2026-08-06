# Broadcast Provider Contract

**Feature**: `005-live-sessions-attendance`

`App\Modules\LiveSessions\Contracts\BroadcastProviderInterface` — نقطة الانعكاس التي تجعل
اختيار مزوّد البث قراراً تجارياً لا معمارياً. نفس شكل `VideoProviderInterface` (004) و
`PaymentProviderInterface` (منذ الإطلاق)، وكلاهما شحن بتنفيذ واحد.

---

## الواجهة

```php
interface BroadcastProviderInterface
{
    /** 'null' · 'livekit' · 'daily' … — يُمنع ظهوره في أي حمولة أو في حزمة الواجهة. */
    public function identifier(): string;

    /**
     * ما يستطيعه هذا المزوّد فعلاً — مُعلَناً لا مفترَضاً.
     * اختبار العقد يُلزم التنفيذ بما يدّعيه فقط، وهو ما يجعل التأجيل آمناً لا متفائلاً.
     */
    public function capabilities(): BroadcastCapabilities;

    /** ينشئ الغرفة عند المزوّد. متماثل الأثر: النداء مرتين لا يُنشئ غرفتين. */
    public function createRoom(ClassSession $session): RoomHandle;

    /**
     * تذكرة دخول قصيرة العمر لهذا الشخص، بهذا الدور، لهذه الغرفة.
     * تُطلب عند كل دخول ولا تُخزَّن: الأهلية تُعاد تقييمها في كل مرة (FR-046).
     */
    public function issueTicket(ClassSession $session, User $user, ParticipantRole $role): JoinTicket;

    /** كتم أو إخراج أو إنهاء. يرمي UnsupportedCapability إن لم يُعلن hostControls. */
    public function hostAction(ClassSession $session, HostAction $action, ?User $target = null): void;

    /** يغلق الغرفة. بعده يُمنع الدخول بأي تذكرة سابقة (FR-015). متماثل الأثر. */
    public function closeRoom(ClassSession $session): void;

    /**
     * التسجيل إن كان جاهزاً، و null إن كان لم يكتمل بعد.
     * يُمنع الرمي عند عدم الجاهزية: «لم يكتمل» ليس خطأً، وهو الحالة المتوقّعة
     * في أول استدعاء بعد كل حصة.
     */
    public function recording(ClassSession $session): ?RecordingArtifact;
}
```

## القدرات المُعلَنة

```php
final class BroadcastCapabilities extends DataTransferObject
{
    public function __construct(
        public readonly bool $liveMedia,        // صوت وصورة فعليان
        public readonly bool $screenShare,
        public readonly bool $recording,        // شرط القصة الرابعة
        public readonly bool $hostControls,     // كتم · إخراج · إنهاء
        public readonly int $maxParticipants,
    ) {}
}
```

**قاعدة العقد**: التنفيذ يُلزَم بما **يدّعيه** فقط. `NullBroadcastProvider` يعلن
`liveMedia: false` و`recording: false` و`hostControls: false` — فلا يفشل اختبار العقد على
ما لا يزعم فعله، ولا يمرّ اختبار قصة تعتمد على قدرة غير معلنة.

| التنفيذ | `liveMedia` | `recording` | `hostControls` | الاستعمال |
|---|---|---|---|---|
| `NullBroadcastProvider` | ✗ | ✗ | ✗ | التطوير المحلي |
| `FakeBroadcastProvider` | ✓ | ✓ | ✓ | الاختبارات — يعلن الكل وينفّذه في الذاكرة |
| مزوّد تجاري | ✓ | ✓ | ✓ | الإنتاج، بعد التوقيع |

`FakeBroadcastProvider` يعلن كل شيء لأن NFR-011 تمنع النداء الشبكي الحقيقي: هو ما يجعل
القصة الرابعة قابلة للاختبار كاملةً قبل أي عقد. الفارق بين «مُختبَر بمزيّف» و«مُثبَت في
الإنتاج» مكتوب في جدول *Deferred Verification* في `plan.md`، لا مطويّ تحت بوابة خضراء.

---

## أشكال البيانات

```php
RoomHandle       { providerRoomId, joinBaseUrl }
JoinTicket       { roomUrl, token, expiresAt, role }   // بلا مفتاح ولا سرّ (FR-019)
RecordingArtifact{ downloadUrl, sizeBytes, durationSeconds, mimeType }
ParticipantRole  { Host, Participant }
HostAction       { Mute, Remove, End }
```

`JoinTicket` هي الشيء الوحيد الذي يعبر إلى المتصفّح، وهي **قصيرة العمر** — فالمسرَّب منها
ينتهي وحده، والدخول به يُرفض بعد إغلاق الغرفة أياً كانت صلاحيته.

---

## ما لا يعرفه المزوّد

**الحضور لا يمرّ به.** لا `participant_joined` ولا `participant_left` ولا webhook. مصدر
الحضور نبض يصل إلى مسارنا، والخادم يجمع (research §R3). ثلاثة نتائج:

1. القصة الثالثة كاملةً — السُّلَّم الزمني والعتبة والتجميع والكشف — تُبنى وتُختبر **قبل** أي
   عقد بثّ.
2. رسالة مزوّد ضائعة لا تُنتج طالباً «داخل الغرفة إلى الأبد» في كشف يذهب لوليّ أمره.
3. تبديل المزوّد لا يعيد كتابة احتساب الحضور — وهو أضخم منطق في المرحلة.

حين يوقَّع مزوّد، إشاراته تُستعمل **للمطابقة** لا كمصدر: تناقضها مع النبض يُدرَج للمراجعة،
ولا يقلب حالة حضور.
