<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Models;

use App\Models\BaseModel;
use App\Modules\Marketplace\Actions\SetAvailability;
use App\Modules\Marketplace\Support\AvailabilityRules;
use App\Shared\Support\UserClock;
use App\Shared\Traits\BelongsToWorkspace;
use App\Shared\Traits\HasUuid;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Marketplace\AvailabilitySlotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring weekly window a teacher is bookable in — WALL-CLOCK time on the
 * teacher's own clock, with that clock named in `timezone` (2026-09-25).
 *
 * ⛔ NOT UTC ANY MORE, AND DAYLIGHT SAVING IS WHY. A weekly window stored in UTC
 * cannot express a zone that observes DST: Egypt moves an hour on 2026-10-29 and
 * the row did not, so a Cairo teacher's «Tuesday 17:00» became 16:00 on their
 * own clock every winter. Every reader converts PER DATE through the methods
 * below — never by comparing a UTC clock time against these columns.
 *
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property string|null $timezone
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
        'timezone',
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
     * `19:00` وصفٌّ فيه `19:00:00` ساعةٌ واحدةٌ بشكلَين، و`covers()` أدناهُ
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

    /** The clock this window is on — null only on a row nothing has stamped. */
    public function zone(): string
    {
        return is_string($this->timezone) && $this->timezone !== ''
            ? $this->timezone
            : UserClock::platformZone();
    }

    /** True when this window contains the given moment (any zone). */
    public function covers(DateTimeInterface $moment): bool
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->zone());

        if ((int) $local->format('w') !== $this->day_of_week) {
            return false;
        }

        $time = $local->format('H:i:s');

        return $time >= $this->start_time && $time < $this->end_time;
    }

    /**
     * True when the whole span `[$start, $end]` lies inside ONE occurrence of this
     * window — the private-session rule (FR-016ب): the whole lesson fits, not
     * merely its first minute.
     *
     * ⚠️ AND ON ONE LOCAL DATE. A window is a one-day shape, so a span that crosses
     * midnight on the teacher's clock is inside none; without that guard a lesson
     * at 23:30 would match a Tuesday-morning window on its clock times alone.
     */
    public function containsSpan(DateTimeInterface $start, DateTimeInterface $end): bool
    {
        $zone = $this->zone();
        $localStart = CarbonImmutable::instance($start)->setTimezone($zone);
        $localEnd = CarbonImmutable::instance($end)->setTimezone($zone);

        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            return false;
        }

        return (int) $localStart->format('w') === $this->day_of_week
            && $localStart->format('H:i:s') >= $this->start_time
            && $localEnd->format('H:i:s') <= $this->end_time;
    }

    /**
     * This window's occurrence on a DATE of the teacher's own calendar, as two
     * absolute instants — or null when that date is another weekday.
     *
     * ⚠️ THE DATE IS PARSED IN THE WINDOW'S ZONE, and that is the whole point:
     * «17:00 on 2026-10-27» and «17:00 on 2026-11-03» in Cairo are 14:00Z and
     * 15:00Z. A walk in UTC with one fixed time of day is what moved a lesson by
     * an hour at every DST change.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    public function occurrenceOn(string $localDate): ?array
    {
        $zone = $this->zone();
        $day = CarbonImmutable::parse($localDate, $zone);

        if ((int) $day->format('w') !== $this->day_of_week) {
            return null;
        }

        return [
            'starts_at' => CarbonImmutable::parse($localDate.' '.$this->start_time, $zone)->utc(),
            'ends_at' => CarbonImmutable::parse($localDate.' '.$this->end_time, $zone)->utc(),
        ];
    }

    /**
     * Rows whose window contains `$moment`, across every zone the table holds.
     *
     * ⚠️ ONE CLAUSE PER DISTINCT ZONE, NEVER `CONVERT_TZ()` — that returns NULL on
     * any MySQL without the zone tables loaded (the managed-MySQL common case), so
     * the filter would match nothing and «متاح الآن» would be empty with no error.
     * In practice the zones are a handful (Doha, Cairo), so this is a handful of
     * ORed conditions on the indexed `day_of_week`.
     *
     * @param  Builder<AvailabilitySlot>  $query
     */
    public function scopeCovering(Builder $query, DateTimeInterface $moment): void
    {
        $zones = self::query()
            ->withoutWorkspaceScope()
            ->distinct()
            ->pluck('timezone')
            ->map(fn (mixed $zone): string => is_string($zone) && $zone !== '' ? $zone : UserClock::platformZone())
            ->unique()
            ->values();

        $query->where(function (Builder $any) use ($zones, $moment): void {
            if ($zones->isEmpty()) {
                $any->whereRaw('1 = 0');

                return;
            }

            foreach ($zones as $zone) {
                $local = CarbonImmutable::instance($moment)->setTimezone($zone);
                $time = $local->format('H:i:s');
                $isPlatform = $zone === UserClock::platformZone();

                $any->orWhere(function (Builder $one) use ($zone, $local, $time, $isPlatform): void {
                    $one->where(function (Builder $z) use ($zone, $isPlatform): void {
                        $z->where('timezone', $zone);

                        if ($isPlatform) {
                            $z->orWhereNull('timezone');
                        }
                    })
                        ->where('day_of_week', (int) $local->format('w'))
                        ->where('start_time', '<=', $time)
                        ->where('end_time', '>', $time);
                });
            }
        });
    }
}
