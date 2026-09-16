<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\DataCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One catalogue entry as the consent screen reads it.
 *
 * ⚠️ `table_name` AND `column_name` ARE ABSENT, AND THEIR ABSENCE IS THE POINT.
 * They exist so `SC-002` can compare the catalogue against the live schema — they
 * are OUR bookkeeping, not the reader's, and shipping them would hand every
 * visitor a map of the database with a personal column named on each line.
 *
 * @mixin DataCategory
 */
class DataCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'purpose' => $this->purpose,
            'audience' => $this->audience,
            /*
            | ⚠️ عن مَن هذه الفئة — والحقلُ فوقَه عن مَن يراها. سؤالانِ مختلفانِ
            | ولا يُغني أحدُهما عن الآخر: `audience` نصٌّ حرٌّ بالعربيّةِ يُقرَأُ
            | ولا يُرشَّحُ به، وهذا قائمةُ أدوارٍ من مفرداتِ `PlatformRole`.
            |
            | ويُرسَلُ من بابٍ **عامّ** بلا حرج: هو وصفُ ما تجمعُه المنصّةُ لا
            | خبرٌ عن أحد. صفحةُ السياسةِ العامّةُ تعرضُ الثلاثةَ والثلاثينَ
            | مقسَّمةً به، وشاشةُ «خصوصيّتي» تعرضُ ما يخصُّ قارئَها وحدَه.
            */
            'subject_roles' => $this->subject_roles,
            // ⚠️ THE REQUIRED/OPTIONAL SPLIT IS NEVER HIDDEN (FR-004). A screen
            // that showed one undifferentiated list would be asking for consent to
            // things that cannot be refused as though they could.
            'is_required' => $this->is_required,
            'owning_module' => $this->owning_module,
            'retain_days' => $this->retain_days,
            // Spelled out, because "1095" is not an answer a parent can read.
            'retention_label_ar' => $this->retentionLabel(),
            'expiry_behaviour' => $this->expiry_behaviour?->value,
        ];
    }

    private function retentionLabel(): string
    {
        $days = $this->retain_days;

        if ($days === null) {
            /*
            | ⚠️ SAID PLAINLY RATHER THAN LEFT BLANK. "No retention period" is a
            | real and significant answer — a certificate and a payment record are
            | both kept indefinitely, on purpose — and an empty cell reads as
            | missing information about the very categories that are kept longest.
            */
            return 'يُحفظ ما دام الحساب قائماً';
        }

        if ($days % 365 === 0) {
            $years = intdiv($days, 365);

            return $years === 1 ? 'سنة واحدة' : "{$years} سنوات";
        }

        return "{$days} يوماً";
    }
}
