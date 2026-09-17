<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthSession */
class AuthSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            // ⚠️ `currentTokenId()`، لا `currentAccessToken()?->getKey()`:
            // الطلبُ المُوثَّقُ بالكوكي يحملُ `TransientToken` ولا `getKey()` فيه.
            //
            // ⚠️ **ومقارنتانِ لا واحدة** (٠٣٧ · قصّة ٢): صفٌّ من الـAPI يُعرَفُ
            // برمزِه، وصفٌّ من اللوحةِ يُعرَفُ بمعرِّفِ جلستِه — و`token_id` فيه
            // فارغٌ دائماً، فمقارنةُ الرمزِ وحدَها تُعلِّمُ **كلَّ** جلسةِ لوحةٍ
            // بأنّها ليست الحاليّة. والطلبُ الآتي من نفسِ المتصفّحِ يحملُ كوكيَّ
            // الجلسةِ على كلِّ حال (أصلٌ واحدٌ)، فالمقارنةُ ممكنةٌ وصادقة.
            //
            // وأن يظهرَ صفّانِ «حاليّان» لشخصٍ فتحَ اللوحةَ والواجهةَ معاً وصفٌ
            // دقيقٌ لا عطل: هما جلستانِ حيّتانِ فعلاً — على **جهازٍ واحد**، لأنّ
            // البصمةَ تجمعُهما في صفِّ `devices` واحد.
            'is_current' => ($this->token_id !== null
                && $this->token_id === $request->user()?->currentTokenId())
                || ($this->session_id !== null
                    && $request->hasSession()
                    && $this->session_id === $request->session()->getId()),
            /*
            | ⚠️ **مُشتَقٌّ لا مخزَّن، ولولاه لصارَ الصفُّ الجديدُ ضرراً.** اللوحةُ
            | والواجهةُ على الجهازِ نفسِه تتقاسمانِ بصمةً واحدةً — وهو المقصودُ:
            | جهازٌ واحدٌ يُعَدُّ مرّةً. لكنّه يعني أنّ صاحبَهما يرى **صفَّينِ
            | باسمِ الجهازِ نفسِه**، ويضغطُ «أنهِ» على أحدِهما بلا أن يعرفَ أيَّ
            | بابٍ يُغلِق. وشاشةٌ تعرضُ خيارَينِ لا يُفرَّقُ بينَهما أسوأُ من شاشةٍ
            | تُخفي أحدَهما.
            |
            | ولا عمودَ له: `session_id` يقولُ من أيِّ بابٍ دخلَ الصفُّ بالفعل،
            | وعمودٌ ثانٍ يُجيبُ السؤالَ نفسَه هو الجوابُ الذي يتخلّفُ عن الأوّل.
            */
            'surface' => $this->session_id !== null ? 'panel' : 'app',
            'device' => [
                'uuid' => $this->device->uuid,
                'label' => $this->device->label,
            ],
            'ended_reason' => $this->ended_reason?->value,
            'last_active_at' => $this->last_active_at,
            'ended_at' => $this->ended_at,
            'created_at' => $this->created_at,
        ];
    }
}
