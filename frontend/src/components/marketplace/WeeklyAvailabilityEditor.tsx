"use client";

import { Select } from "@/components/ui/Field";

/**
 * الجدولُ الأسبوعيُّ الذي يُحجَزُ فيه المدرّس، بهجاءٍ واحدٍ لبابَيه.
 *
 * ⚠️ بابانِ يكتبانِ الصفوفَ نفسَها: الخطوةُ الرابعةُ من معالجِ الانضمام، وبطاقةُ
 * «مواعيدي الأسبوعيّة» في `‎/settings/profile` بعدَ الاعتماد. ونسختانِ من محرّرٍ
 * واحدٍ تفترقانِ عندَ أوّلِ تعديل — وقاعدةُ التحقّقِ خلفَهما واحدةٌ
 * (`AvailabilityRules`)، فبابٌ يقبلُ ما يرفضُه الآخرُ بلا أن يفشلَ شيء.
 *
 * ⚠️ والمكوّنُ لا يعرفُ UTC إطلاقاً: يقرأُ ويكتبُ ساعةَ الحائطِ عندَ المدرّس،
 * والتحويلُ في `lib/availability.ts` عندَ الحفظِ والتحميلِ وحدَهما. تحويلٌ هنا
 * وتحويلٌ عندَ المُنادي هجاءانِ لقاعدةٍ واحدة — وهي القاعدةُ التي كلّفتْ إصلاحاً
 * في ٢٠٢٦-٠٩-٠٢ حينَ خزَّنَ المعالجُ ساعةَ الحائطِ وقرأَها خمسةُ قرّاءٍ UTC.
 */

export const DAYS = ["الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];

const DAY_END = 23 * 60 + 59;

export interface Slot {
  day_of_week: number;
  start_time: string;
  end_time: string;
}

function toMinutes(time: string): number {
  const [hours, minutes] = time.split(":").map(Number);

  return (hours || 0) * 60 + (minutes || 0);
}

function toTime(minutes: number): string {
  const capped = Math.min(Math.max(minutes, 0), DAY_END);

  return `${String(Math.floor(capped / 60)).padStart(2, "0")}:${String(capped % 60).padStart(2, "0")}`;
}

/**
 * The next row of the weekly timetable, derived from the LAST one.
 *
 * ⚠️ A HARD-CODED `{day: 1, 16:00–18:00}` WAS THE OLD ANSWER, and it is wrong
 * for everybody: a teacher who works 09:00–11:00 retyped both times on every
 * row, and the day landed on Monday however far down the week they had got.
 * Availability is the same hours repeated across days far more often than it is
 * seven different ones, so the previous row is the best guess there is.
 *
 * `% 7` is the whole of the wrap: the row after Saturday is Sunday, not day 7 —
 * which the `<select>` has no option for and the API rejects as `between:0,6`.
 */
export function nextDaySlot(previous: Slot): Slot {
  return { ...previous, day_of_week: (previous.day_of_week + 1) % 7 };
}

/**
 * A second period on the SAME day — an afternoon after a morning.
 *
 * ⚠️ IT STARTS WHERE THE PREVIOUS ONE ENDED, and that is not a nicety.
 * `SetAvailability` rejects two overlapping slots on one day, so copying the
 * previous row's times verbatim here would produce a row that can only ever be
 * refused — an "add" button whose output is invalid on arrival. The comparison
 * there is strict, so back-to-back (`end === next start`) passes.
 *
 * The new period keeps the previous one's LENGTH, and both ends are clamped to
 * 23:59: a two-hour slot added after 23:00 would otherwise roll past midnight
 * into a number `date_format:H:i` refuses.
 */
export function sameDaySlot(previous: Slot): Slot {
  const start = toMinutes(previous.end_time);
  const length = Math.max(toMinutes(previous.end_time) - toMinutes(previous.start_time), 30);

  return {
    day_of_week: previous.day_of_week,
    start_time: toTime(start),
    end_time: toTime(start + length),
  };
}

export function WeeklyAvailabilityEditor({
  slots,
  onChange,
  controlClassName,
  disabled = false,
}: {
  slots: Slot[];
  onChange: (slots: Slot[]) => void;
  /**
   * ⚠️ مطلوبٌ لا اختياريّ: `Select` هو السهمُ ولا شيءَ غيرَه، وكلُّ حدٍّ وخلفيّةٍ
   * ولونِ نصٍّ يأتي من هنا. الموقعانِ يختلفانِ فعلاً — المعالجُ استمارةٌ عامّةٌ
   * بضوابطِه، والإعداداتُ تستعملُ `CONTROL` من الطقم.
   */
  controlClassName: string;
  disabled?: boolean;
}) {
  const update = (index: number, patch: Partial<Slot>) =>
    onChange(slots.map((slot, i) => (i === index ? { ...slot, ...patch } : slot)));

  return (
    <>
      <ul className="space-y-3">
        {slots.map((slot, index) => (
          <li key={index} className="flex flex-wrap items-end gap-2">
            <label className="flex-1">
              <span className="sr-only">اليوم</span>
              <Select
                value={slot.day_of_week}
                onChange={(e) => update(index, { day_of_week: Number(e.target.value) })}
                className={controlClassName}
                disabled={disabled}
              >
                {DAYS.map((day, dayIndex) => (
                  <option key={day} value={dayIndex}>
                    {day}
                  </option>
                ))}
              </Select>
            </label>

            <label>
              <span className="sr-only">من</span>
              <input
                type="time"
                value={slot.start_time}
                onChange={(e) => update(index, { start_time: e.target.value })}
                className={controlClassName}
                disabled={disabled}
              />
            </label>

            <label>
              <span className="sr-only">إلى</span>
              <input
                type="time"
                value={slot.end_time}
                onChange={(e) => update(index, { end_time: e.target.value })}
                className={controlClassName}
                disabled={disabled}
              />
            </label>

            {/* ⚠️ لا زرَّ حذفٍ على الصفِّ الأخير: الخادمُ يرفضُ أسبوعاً فارغاً
                (`min:1`)، فزرٌّ يُفرِغُه زرٌّ جوابُه الرفضُ دائماً. */}
            {slots.length > 1 && (
              <button
                type="button"
                onClick={() => onChange(slots.filter((_, i) => i !== index))}
                disabled={disabled}
                className="rounded-xl border border-line px-3 py-2.5 text-sm text-danger-ink"
              >
                حذف
                <span className="sr-only"> فترة {DAYS[slot.day_of_week]}</span>
              </button>
            )}
          </li>
        ))}
      </ul>

      {/* Two buttons, because they answer two different questions: «the same
          hours on another day» and «another hour on this day». One button doing
          both would have to guess which, and a guess wrong half the time is two
          corrections instead of one click. */}
      <div className="mt-3 flex flex-wrap gap-4">
        <button
          type="button"
          onClick={() => onChange([...slots, nextDaySlot(slots[slots.length - 1])])}
          disabled={disabled}
          className="text-sm font-semibold text-primary-ink underline"
        >
          إضافة يوم
        </button>

        <button
          type="button"
          onClick={() => onChange([...slots, sameDaySlot(slots[slots.length - 1])])}
          disabled={disabled}
          className="text-sm font-semibold text-primary-ink underline"
        >
          فترة أخرى في نفس اليوم
        </button>
      </div>
    </>
  );
}
