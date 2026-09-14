<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Requests;

use App\Modules\Learning\Actions\JoinWaitlist;
use Illuminate\Foundation\Http\FormRequest;

/**
 * التسجيلُ في دَورِ كورس (٠٣٤ · FR-026أ).
 *
 * ⚠️ **ولا `exists:users,uuid` هنا.** تلكَ القاعدةُ استعلامٌ خامٌّ يجيبُ «نعم» عن
 * أيِّ حسابٍ على المنصّة، فتُفرِّقُ في الجوابِ بينَ معرِّفٍ حقيقيٍّ وآخرَ مخترَع —
 * وهو عرّافٌ يقولُ للسائلِ أيُّ المعرِّفاتِ يخصُّ إنساناً. والملكيّةُ تُسأَلُ في
 * {@see JoinWaitlist}، وجوابُها **واحدٌ** لمعرِّفٍ
 * لا وجودَ له ولمعرِّفِ طالبٍ ليسَ ابنَ الضاغط.
 *
 * ⚠️ **ولا حقلَ موضعٍ ولا أولويّة** (FR-027): الدَّورُ لا يحجزُ مقعداً ولا يَعِدُ
 * به، وحقلٌ يقبلُ رقماً من العميلِ هو الوعدُ نفسُه مكتوباً في الحمولة.
 */
class JoinWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'student_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }
}
