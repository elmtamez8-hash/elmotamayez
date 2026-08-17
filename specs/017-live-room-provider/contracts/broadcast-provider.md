# Contract — `LiveKitBroadcastProvider`

**الواجهة لا تتغيّر.** `BroadcastProviderInterface` بدوالّه السبع هي العقد المشحون منذ
‎٠٠٥‎، وهذا الملفّ يترجم كلّ دالّة إلى نداء المكتبة الذي يفي بها — **وما لا تفي به**.

⚠️ **ولا مسار API جديد ولا حمولة جديدة**: `POST /class-sessions/{session}/join` و
`/host/{action}` و`/presence` مشحونة، و`JoinTicketResource` بحقولها الخمسة كما هي.

---

## أ — الدوالّ السبع

### 1. `identifier(): string`

```php
return 'livekit';
```

القيمة المُطابِقة لذراع `match` في `LiveSessionsServiceProvider` ولـ`config('sessions.provider')`
(`FR-001`). تُخزَّن في `broadcast_provider` و**لا تُرسَل في أيّ Resource**.

### 2. `capabilities(): BroadcastCapabilities`

```php
liveMedia: true · screenShare: true · recording: true · hostControls: true
maxParticipants: $settings->maxParticipants()      // من platform_settings — FR-003
```

⚠️ **والأربعة `true` وعدٌ يُحاسِب عليه `BroadcastProviderContractTest`** (`FR-004`): قدرةٌ
مُعلَنة ولا تعمل تُسقط البناء. ولذلك `maxParticipants` **يُمرَّر إلى المزوّد** أيضاً
(§R14) — سقفٌ يُعلَن ولا يصل الغرفة زخرفةٌ لا قدرة.

### 3. `createRoom(ClassSession): RoomHandle`

```
RoomServiceClient::createRoom(
  RoomCreateOptions
    ->setName("session-{$session->uuid}")            // حتميّ ⇒ مُتماثل الأثر
    ->setMaxParticipants($settings->maxParticipants())
    ->setEmptyTimeout($window + $duration + $window) // §R5 — الفخّ
    ->setDepartureTimeout(نفسها)
    ->setEgress(RoomEgress ->setAutomatic(true) ->setFile(EncodedFileOutput → تخزيننا))
)
→ RoomHandle(providerRoomId: اسم الغرفة, joinBaseUrl: config wss URL)
```

**ثلاثة عقودٍ تُستوفى بهذه المكالمة الواحدة**: التماثل (‏اسمٌ مشتقّ من الـuuid، فالاستدعاء
مرّتين لا يصنع غرفتين)، و`FR-011` (‏التسجيل يبدأ **مع فتح الغرفة**)، و`FR-003`.

⚠️ **و`joinBaseUrl` يصير عنوان `wss`** لأن العميل يتّصل به. وهو ثالث أثرٍ لقرار الاستضافة
(§R12): على نطاقنا يبقى `SC-005` حرفياً صحيحاً.

### 4. `issueTicket(ClassSession, User, ParticipantRole): JoinTicket`

```php
(new AccessToken($key, $secret))
  ->init((new AccessTokenOptions)
      ->setIdentity($user->uuid)                     // FR-006 — من الـuuid لا الاسم
      ->setTtl($settings->ticketTtlMinutes() * 60))  // FR-007 — ★ الافتراضي 6 ساعات!
  ->setGrant((new VideoGrant)
      ->setRoomJoin()
      ->setRoomName("session-{$session->uuid}")      // FR-006 — تلك الغرفة وحدها
      ->setCanPublish(true)                          // الطالبة ترفع يدها فتتكلّم — US1
      ->setRoomAdmin($role === ParticipantRole::Host))  // FR-010 — الدور داخل التذكرة
  ->toJwt();
```

🚫 **بلا أيّ نداءٍ شبكيّ. مطلقاً.** `presence` يُعيد تشغيل `IssueJoinTicket` عند كلّ نبضة
(‏كلّ ‎٣٠‎ ثانية لكلّ مشارك)، فنداءٌ واحد هنا يجعل انقطاعَ واجهة LiveKit البرمجية يُرجع
‎٤٠٣‎ لكلّ نبضة **فيُحتسب الصفّ كلّه غائباً** — نقضاً مباشراً لـ`Q5` و`FR-015` (§R3).
وبالتحديد: **لا `ensureRoom` هنا**.

🚫 **ولا يُقرأ الدور من الطلب**: `IssueJoinTicket::roleFor()` القائمة هي التي تقرّره من
صلاحية `SESSIONS_HOST` أو من مقعدٍ قائم. المُهايئ يستقبل الدور ولا يجتهد فيه.

### 5. `hostAction(ClassSession, HostAction, ?User): void`

| الفعل | النداء | ملاحظة |
|---|---|---|
| `Mute` | `getParticipant($room, $uuid)` → لكلّ مسار صوت: `mutePublishedTrack(..., true)` | `trackSid` غير موجودٍ في التوقيع، ويُحلّ **داخل المُهايئ** (§R8) |
| `Remove` | `removeParticipant($room, $uuid)` | يفصل، ولا يُبطل التذكرة (§R9) |
| `End` | **لا يصل هنا أصلاً** | `PerformHostAction` يحوّله إلى `CloseBroadcastRoom` قبل الواجهة |

🚫 **ويُمنع توسيع التوقيع بـ`trackSid`**: يُسرّب مفردات LiveKit إلى العقد وإلى
`PerformHostAction` و`BroadcastController` — أي يُلغي `FR-002` في اللحظة التي يُفترض أن
يُثبته فيها.

### 6. `closeRoom(ClassSession): void`

```php
RoomServiceClient::deleteRoom("session-{$session->uuid}");   // يفصل الجميع ويوقف التصدير
```

مُتماثل الأثر: حذف غرفةٍ محذوفة **لا يرمي** (‏يُبتلَع خطأ «‏غير موجودة» وحده، لا كلّ خطأ).

⚠️ **وما لا يفعله**: لا يُبطل تذكرةً صدرت قبله. `FR-009` يُستوفى بـ`room.auto_create: false`
على الخادم، ويهبط إلى حارسنا وحده على Cloud (§R2 · جدول Deferred Verification).

### 7. `recording(ClassSession): ?RecordingArtifact`

```php
EgressServiceClient::listEgress(roomName: "session-{$session->uuid}")
  → أوّل عنصرٍ حالته EGRESS_COMPLETE → RecordingArtifact(downloadUrl, sizeBytes, durationSeconds, mimeType)
  → غير ذلك: null
```

🚫 **`null` لا استثناء** (`FR-013`): «‏لم يكتمل بعد» هو الجواب المتوقَّع في أوّل نداءٍ بعد
كلّ حصّة، لا خطأ. وأيّ استثناءٍ هنا يحوّل كلّ محاولة استيعاب إلى فشلٍ مُسجَّل.

🚫 **و`downloadUrl` لا يصل مستخدماً أبداً** (`FR-012`): تقرأه وظيفة الاستيعاب على الخادم،
ويصير الملفّ أصلَ ‎٠٠٤‎ بمنحة تشغيلٍ وعلامةٍ مائية. المدخل الوحيد للطالب هو تلك المنحة.

---

## ب — ما لا يُنفَّذ، بقرار

| ما رُفض | لماذا |
|---|---|
| `WebhookReceiver` ومسارٌ عامّ لأحداث المزوّد | `FR-013`/`FR-014` يصفان سحباً؛ وحلقة الاستيعاب سحّابٌ قائم. الخطّاف يشتري «‏أسرع بدقائق» بسطح هجومٍ عامّ جديد وبتحقّقٍ من توقيع، ويكسر بساطة `SC-006` (§R6) |
| أيّ قراءةٍ للحضور من المزوّد | `FR-015` صراحةً. الواجهة نفسها لا تحمل `participantJoined` — وهو قرار ‎٠٠٥‎ المكتوب في تعليقها |
| تخزين التذكرة | `FR-008` يوجب مراجعة الأهليّة عند كلّ إصدار |
| تعديل `BroadcastProviderInterface` أو أيٍّ من الـDTOs الأربعة | الدستور §VI. المرحلة تملأ تنفيذاً ولا تُعيد تصميماً |

---

## ج — العقد الثاني: `RetryPendingRecordingsJob`

وظيفة الاستيعاب لها **مُرسِلٌ واحد** اليوم (`SessionCompleted`)، فحلقة المحاولات الموصوفة
في `FR-014` غير موجودة أصلاً (§R7). هذا العقد يُنشئها:

```text
مجدولة: كلّ ‎١٥‎ دقيقة، طابور maintenance، withoutOverlapping()
تلتقط:  class_sessions حيث recording_status = 'pending'
        AND recording_attempts < recording_max_attempts
        AND room_closed_at خلال آخر ‎٤٨‎ ساعة        ← سقفٌ زمنيّ، لا مسحٌ للتاريخ كلّه
تفعل:   IngestSessionRecordingJob::dispatch($id)     ← لا منطق مكرَّراً
لا تفعل: WorkspaceContext::set()                     ← الدستور §I: forWorkspace() حصراً
```

**ولماذا مسحٌ لا `dispatch()->delay()`**: فشلُ التسجيل هو **غياب** حدث — لا شيء يُطلَق حين
لا يصل ملفّ. وهو حرفياً المنطق المكتوب فوق `ReleasePendingUnitsJob` في `routes/console.php`،
والاتّساق معه مقصود. ووظيفةٌ ضاعت من الطابور تترك حصّةً عالقة بلا شيء يلاحظها — وهو ما
يمنعه `SC-004`.

**وأثره خارج الوحدة**: `Settlement\Support\PackageCompletion` يحجز استحقاق المدرّس ما لم
تبلغ الحالة نهايتها. فهذه الوظيفة هي ما يمنع «‏أجرٌ محجوز إلى الأبد» — ولا سطر في
`Settlement/` يتغيّر (`FR-016`).
