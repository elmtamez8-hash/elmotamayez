<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * اسمُ مزوّدِ الدفعِ بالعربيّة، لشاشاتِ اللوحة.
 *
 * `payment_transactions.provider` نصٌّ لا enum: هو `identifier()` كلِّ مزوّدٍ
 * مسجَّل، ومزوّدٌ يُضافُ غداً يكتبُ اسمَه دونَ أن يمرَّ من هنا. فالاسمُ المجهولُ
 * يُعرَضُ كما هو بدلَ أن يختفي.
 */
final class PaymentProviderLabel
{
    /** @var array<string, string> */
    private const LABELS = [
        'manual' => 'تحويل يدوي',
    ];

    public static function for(mixed $provider): string
    {
        if (! is_string($provider) || $provider === '') {
            return '—';
        }

        return self::LABELS[$provider] ?? $provider;
    }
}
