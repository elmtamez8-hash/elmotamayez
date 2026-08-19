<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Channels\ChannelRegistry;

/**
 * Every channel the platform knows about, implemented or not.
 *
 * Listing the unimplemented ones is deliberate (FR-005): they are the values a
 * preference row, a template and a delivery log may legally hold, so the shape
 * of the data does not change the day WhatsApp lands — only a class appears.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Telegram = 'telegram';
    case Sms = 'sms';
    case Push = 'push';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'داخل المنصة',
            self::WhatsApp => 'واتساب',
            self::Email => 'البريد الإلكتروني',
            self::Telegram => 'تيليجرام',
            self::Sms => 'رسالة نصّية',
            self::Push => 'إشعار فوري',
        };
    }

    /**
     * Whether delivery leaves the platform and can reach the user while they are
     * not looking at it. Quiet hours apply to these and only these (FR-032): an
     * in-app notification wakes nobody at 3am.
     */
    public function isExternal(): bool
    {
        return $this !== self::InApp;
    }

    /**
     * Whether this channel's contact detail is a phone number (spec 020).
     *
     * Here rather than in the Action that normalises one, and that placement is
     * the point: ProviderAgnosticTest forbids Actions/ from naming a channel at
     * all, so "is this a phone?" has to be a property of the vocabulary rather
     * than a branch in business logic. The same reason isExternal() lives here.
     */
    public function isPhoneNumber(): bool
    {
        return $this === self::WhatsApp || $this === self::Sms;
    }

    /**
     * Asked of the registry rather than hardcoded, so "implemented" means exactly
     * one thing: a class for it is tagged in the container. A constant here would
     * drift the first time someone writes the class and forgets to flip it.
     */
    public function isImplemented(): bool
    {
        return app(ChannelRegistry::class)->has($this);
    }
}
