<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * إلى أينَ يُحوِّلُ المشتري، وبأيِّ اسم.
 *
 * ⛔ **المنتَجُ كانَ يطلبُ تحويلاً إلى مكانٍ لا يُسمّيه.** شاشةُ الاشتراكِ تقولُ
 * «حوِّلْ قيمة الباقة إلى حساب المنصّة، ثمّ ارفعْ صورة التحويل» — ولا تقولُ أيُّ
 * حساب. قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦: تسعةٌ وستّونَ صفّاً في `platform_settings`
 * ليسَ فيها اسمُ بنكٍ ولا آيبان ولا محفظة. بلاغُ مستخدِم.
 *
 * ⚠️ **والحقولُ كلُّها اختياريّةٌ والفارغُ يُسقَط.** منصّةٌ تُحوَّلُ إليها بالبنكِ
 * وحدَه لا تعرضُ سطرَ محفظةٍ فارغاً — وخانةٌ فارغةٌ تحتَ عنوانٍ تُقرَأُ بياناتٍ
 * لم تُحمَّل، وهي أسوأُ من غيابِ السطر.
 *
 * ⚠️ **و«مُعدَّةٌ» تعني أنّ فيها ما يُحوَّلُ إليه فعلاً** — حساباً أو محفظةً —
 * لا أنّ الخريطةَ موجودة. مشغِّلٌ كتبَ اسمَ البنكِ وحدَه ونسيَ الرقمَ يترُكُ
 * المشتريَ حيثُ كان، فالشاشةُ تحتاجُ أن تعرفَ الفرق.
 */
final class TransferInstructions
{
    /** @var list<string> */
    public const FIELDS = [
        'bank_name',
        'account_name',
        'account_number',
        'iban',
        'wallet_label',
        'wallet_number',
        'note',
    ];

    /**
     * ما كُتِبَ فعلاً، بلا الخانات الفارغة.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = PlatformSettings::get('billing.transfer', []);

        $clean = [];

        foreach (self::FIELDS as $field) {
            $value = trim((string) ($stored[$field] ?? ''));

            if ($value !== '') {
                $clean[$field] = $value;
            }
        }

        return $clean;
    }

    /**
     * هل فيها وجهةٌ يُحوَّلُ إليها؟
     *
     * ⚠️ الرقمُ لا الاسم: «بنك قطر الوطني» بلا آيبانٍ ولا رقمِ حسابٍ ولا محفظةٍ
     * ليسَ عنواناً يُحوَّلُ إليه، واسمُ البنكِ وحدَه يجعلُ الشاشةَ تبدو مكتملةً
     * وهي ليست كذلك.
     */
    public static function areSet(): bool
    {
        $set = self::all();

        return isset($set['iban'])
            || isset($set['account_number'])
            || isset($set['wallet_number']);
    }
}
