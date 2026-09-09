<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Modules\Marketplace\Actions\SetAvailability;
use App\Modules\Marketplace\Support\AvailabilityRules;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Database\Factories\Modules\Marketplace\AvailabilitySlotFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring weekly window a teacher is bookable in. Times are stored in UTC.
 *
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 */
class AvailabilitySlot extends BaseModel
{
    /** @use HasFactory<AvailabilitySlotFactory> */
    use BelongsToWorkspace, HasFactory, HasUuid;

    protected $fillable = [
        'workspace_id',
        'teacher_profile_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    /**
     * الوقتُ يُسوّى عندَ الكتابة، لأنّ العمودَ يُقرَأُ بالمقارنةِ النصّيّة.
     *
     * ⚠️ أربعةُ كتّابٍ لهذا الجدول — {@see SetAvailability}
     * وبذرتانِ ومصنع — وقاعدةُ التحقّقِ تقبلُ `H:i` و`H:i:s` كليهما. فصفٌّ فيه
     * `19:00` وصفٌّ فيه `19:00:00` ساعةٌ واحدةٌ بشكلَين، و`coversUtc()` أدناهُ
     * و`RequestPrivateSession` يُقارنانِ نصّاً ضدّ `H:i:s` دائماً: فطلبٌ ينتهي
     * عندَ الحافّةِ بالضبطِ يُرفَضُ عندَ الأوّلِ ويُقبَلُ عندَ الثاني. الحارسُ هنا
     * لا عندَ كلِّ كاتب، فالكاتبُ التالي لن يقرأَ هذه القاعدة.
     *
     * ⚠️ وMySQL يُسوّي عمودَ `time` من تلقاءِ نفسِه فيُخفي الفرقَ في الإنتاج،
     * وSQLite يحفظُ ما أُعطِيَ حرفيّاً — أي أنّ البيئةَ الوحيدةَ التي تكشفُه هي
     * التي تدورُ فيها كلُّ اختباراتِ هذه الحزمة.
     *
     * @return Attribute<string, string>
     */
    protected function startTime(): Attribute
    {
        return Attribute::set(fn (string $value): string => AvailabilityRules::seconds($value));
    }

    /** @return Attribute<string, string> */
    protected function endTime(): Attribute
    {
        return Attribute::set(fn (string $value): string => AvailabilityRules::seconds($value));
    }

    /** @return BelongsTo<TeacherProfile, $this> */
    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    /**
     * True when this window contains the given UTC moment.
     */
    public function coversUtc(\DateTimeInterface $moment): bool
    {
        if ((int) $moment->format('w') !== $this->day_of_week) {
            return false;
        }

        $time = $moment->format('H:i:s');

        return $time >= $this->start_time && $time < $this->end_time;
    }
}
