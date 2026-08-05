# Contract: عقد قناة الإشعار

**Feature**: `003-notification-architecture`

نقطة الانعكاس في هذه المرحلة. الطبقات العليا (`Actions/`، المستمعون) تعتمد على هذا التجريد،
و**يُمنع** أن تعرف ما وراءه (FR-002 · SC-002). يقتدي حرفياً بـ
`Payments\Contracts\PaymentProviderInterface` — النمط نفسه مطبَّق ومُثبَت في هذا المستودع.

---

## `App\Modules\Notifications\Contracts\NotificationChannelInterface`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Support\NotificationChannel;

/**
 * Abstraction over a delivery channel (in-app, WhatsApp, email, Telegram, SMS, push).
 *
 * A new channel implements this interface and gets tagged 'notification.channels'
 * in NotificationsServiceProvider::register(). Nothing else changes: no notification
 * class, no listener, and no action is touched. That is the addendum's constraint —
 * "No business logic may depend on a specific notification provider" — expressed as
 * a type.
 */
interface NotificationChannelInterface
{
    /** Which channel this implements. One class per enum case. */
    public function channel(): NotificationChannel;

    /**
     * Whether the channel can run right now: credentials present, provider reachable
     * enough to try. A channel that returns false is skipped as `skipped`, never
     * `failed` — a disabled channel is a configuration state, not an incident.
     */
    public function isEnabled(): bool;

    /**
     * Whether this recipient can be reached on this channel at all — a verified phone
     * for WhatsApp, a verified email for mail. Checked before dispatch so an
     * unreachable recipient never occupies a queue slot.
     */
    public function canReach(NotificationEnvelope $envelope): bool;

    /**
     * Deliver. Returns on success.
     *
     * @throws PermanentDeliveryException  the failure will not be fixed by retrying
     *                                     (invalid number, unapproved template,
     *                                     recipient blocked). NOT retried.
     * @throws \Throwable                  anything else is treated as transient and
     *                                     retried with escalating backoff (FR-009).
     */
    public function send(NotificationEnvelope $envelope): void;
}
```

### لماذا `void` لا نتيجة؟

الحالة تُكتب في `notification_deliveries` من الوظيفة لا من القناة — فالقناة تنجح أو ترمي، ولا
تلمس قاعدة البيانات. هذا يبقيها اختباريةً بلا قاعدة بيانات، ويمنع قناةً من كتابة حالة تناقض ما
تراه الوظيفة.

### لماذا `canReach()` منفصل عن `isEnabled()`؟

`isEnabled()` عن **القناة** (هل واتساب مهيّأ أصلاً؟)، و`canReach()` عن **المستلم** (هل لهذا
المستخدم رقم مُتحقَّق منه؟). دمجهما يعني أن مستخدماً بلا رقم يُقرأ كعطل في القناة.

---

## `App\Modules\Notifications\Data\NotificationEnvelope`

كل ما تحتاجه القناة، ولا شيء غيره. DTO يرث `DataTransferObject`.

```php
final class NotificationEnvelope extends DataTransferObject
{
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationType $type,
        public readonly string $titleAr,
        public readonly string $bodyAr,
        public readonly ?string $actionUrl,
        /** @var array<string, mixed> */
        public readonly array $payload,
        public readonly string $notificationUuid,
    ) {}
}
```

**يُمنع** أن يحمل الظرف نموذج `Notification` أو `NotificationDelivery` — القناة لا تكتب حالة،
وتمريرها النموذج دعوة لأن تفعل.

---

## `App\Modules\Notifications\Channels\ChannelRegistry`

```php
final class ChannelRegistry
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    /** @param iterable<NotificationChannelInterface> $channels */
    public function __construct(iterable $channels)
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->channel()->value] = $channel;
        }
    }

    public function has(NotificationChannel $channel): bool;

    /** @throws ChannelNotImplementedException */
    public function get(NotificationChannel $channel): NotificationChannelInterface;

    /** @return array<int, NotificationChannel> القنوات المُنفَّذة والمفعّلة */
    public function available(): array;
}
```

التسجيل — **السطر الوحيد** الذي تضيفه قناة جديدة:

```php
// NotificationsServiceProvider::register()
$this->app->tag([InAppChannel::class], 'notification.channels');

$this->app->singleton(
    ChannelRegistry::class,
    fn ($app) => new ChannelRegistry($app->tagged('notification.channels')),
);
```

---

## عقد الإضافة (SC-001)

إضافة قناة **يجب** أن تقتصر على:

1. صنف واحد ينفّذ `NotificationChannelInterface` في `Channels/`.
2. اسمه في وسم `notification.channels`.
3. صفوف قوالب لتلك القناة (بيانات، لا كود).

و**يُمنع** أن تتطلّب: تعديل `DispatchNotification`، أو أي مستمع، أو أي `Action` في أي وحدة،
أو أي نوع إشعار.

**الإثبات**: `ChannelContractTest` يسجّل `FakeChannel` في بيئة الاختبار ويتحقّق من وصول **كل**
أنواع الإشعارات إليها بلا تعديل سطر واحد من الكود المُطلِق.

---

## الفحص الآلي (SC-002)

`ProviderAgnosticTest` يمسح `app/Modules/*/Actions/**/*.php` بحثاً عن:

```
whatsapp · telegram · twilio · vonage · firebase · sms · mail · InAppChannel
Notification::route · ->notify(
```

ويفشل عند أي تطابق. المستثنى الوحيد `app/Modules/Notifications/Channels/` — موضع التنفيذ.

---

## ما الجاهز لواتساب اليوم (FR-005)

واتساب — كالبريد وتيليجرام والرسائل القصيرة والإشعار الفوري — **قيمة معروفة معلَّمة غير
مُنفَّذة**. جاهزيتها ليست وعداً بل بنية قائمة تُختبَر في هذه المرحلة:

| الجاهز الآن | الموضع |
|---|---|
| قيمة `whatsapp` في التعداد، `isExternal() = true` | `Support/NotificationChannel.php` |
| لا تظهر في `GET /notifications/types`، واختيارها `422` | FR-030 · `PreferencesTest` |
| القوالب تحمل `provider_approval_status` — واتساب يشترط اعتماد القوالب مسبقاً | `message_templates` |
| مسار التحقّق من رقم الهاتف مبنيّ ومُختبَر وبلا مستهلك | `contact_verifications` · §R12 |
| أوقات الهدوء تسري على القنوات الخارجية وحدها، ومُثبَتة بقناة خارجية مزيّفة | FR-032 · `QuietHoursTest` |
| عزل الفشل وإعادة المحاولة بالتراجع التصاعدي | FR-006 · FR-009 |
| طابور `notifications` منفصل عن `default` | NFR-009 |

**ما يتبقّى عند تنفيذها لاحقاً** — وهو كل شيء:

1. `WhatsAppChannel implements NotificationChannelInterface` — ملف واحد.
2. اسمه في وسم `notification.channels` — سطر واحد.
3. صفوف قوالب لقناة `whatsapp` — بيانات من اللوحة، لا كود.
4. بيانات اعتماد المزوّد في البيئة (FR-011) — **يُمنع** وجودها في المستودع.

**صفر تعديل** في `DispatchNotification` أو أي مستمع أو أي `Action` أو أي نوع إشعار. هذا ما
يقيسه SC-001، وهو مُختبَر الآن بـ `FakeChannel` قبل وجود واتساب — فالادّعاء مُثبَت لا موعود.

---

## `PermanentDeliveryException`

```php
final class PermanentDeliveryException extends RuntimeException
{
    public static function invalidRecipient(string $reason): self;
    public static function templateNotApproved(string $key): self;
    public static function blockedByRecipient(): self;
}
```

الفرق عن أي استثناء آخر هو **قرار إعادة المحاولة وحده**: هذا يُسجَّل `failed` فوراً، وغيره
يُعاد حتى خمس محاولات بتراجع `[30, 120, 300, 900]` ثانية (FR-009 · SC-006).
