"use client";

import { useEffect, useState } from "react";
import {
  DurationIcon,
  EveningIcon,
  type IconProps,
  MorningIcon,
  NightIcon,
  NoonIcon,
  WeekIcon,
} from "@/components/icons";
import { toLocalSlot } from "@/lib/availability";
import type { AvailabilityItem } from "@/lib/public-api";

const DAYS = ["الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];

/**
 * Which part of the day a slot belongs to.
 *
 * ⚠️ IT IS READ FROM THE **START** TIME, and that is a decision rather than an
 * accident: a lesson running 17:00–21:00 is an evening lesson to the person
 * deciding whether they can attend after school, not a night one. Reading the
 * end would relabel the same slot the moment a teacher extends it by an hour.
 */
export function partOfDay(startTime: string): {
  label: string;
  Icon: (props: IconProps) => React.ReactElement;
} {
  const hour = Number(startTime.split(":")[0]);

  if (hour < 12) return { label: "صباحاً", Icon: MorningIcon };
  if (hour < 17) return { label: "ظهراً", Icon: NoonIcon };
  if (hour < 21) return { label: "مساءً", Icon: EveningIcon };

  return { label: "ليلاً", Icon: NightIcon };
}

/** A slot's length in minutes. A negative span is clamped to zero. */
export function slotMinutes(slot: Pick<AvailabilityItem, "start_time" | "end_time">): number {
  const toMinutes = (time: string) => {
    const [hours, minutes] = time.split(":").map(Number);

    return (hours || 0) * 60 + (minutes || 0);
  };

  return Math.max(toMinutes(slot.end_time) - toMinutes(slot.start_time), 0);
}

/**
 * «ساعتان ونصف», not «2.5 h».
 *
 * ⚠️ ARABIC HAS A DUAL, so «٢ ساعة» is wrong in a way «2 hours» never is — and
 * the half hour is a word rather than a decimal point: a reader scanning seven
 * cards takes «ساعة ونصف» in faster than they parse «1.5».
 */
export function formatDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  /*
   * ⚠️ ARABIC COUNTS IN FOUR BANDS, NOT TWO. One is «ساعة», two is the dual
   * «ساعتان», three-to-ten take the plural «٣ ساعات» — and **eleven and above go
   * back to the SINGULAR**: «١٢ ساعة», never «١٢ ساعات». The last band is the one
   * an English-shaped `n === 1 ? … : …` always gets wrong, and it is exactly the
   * band a weekly total lands in.
   */
  const hoursLabel =
    hours === 0
      ? ""
      : hours === 1
        ? "ساعة"
        : hours === 2
          ? "ساعتان"
          : hours <= 10
            ? `${hours} ساعات`
            : `${hours} ساعة`;

  if (rest === 0) return hoursLabel || `${minutes} دقيقة`;
  if (rest === 30) return hoursLabel ? `${hoursLabel} ونصف` : "نصف ساعة";

  return hoursLabel ? `${hoursLabel} و${rest} دقيقة` : `${rest} دقيقة`;
}

/** The same four bands as `formatDuration`, for days. A week never reaches 11. */
export function formatDays(days: number): string {
  if (days === 1) return "يوم واحد";
  if (days === 2) return "يومان";

  return `${days} أيام`;
}

export function AvailabilityCalendar({ slots }: { slots: AvailabilityItem[] }) {
  const [localised, setLocalised] = useState(slots);
  const [zone, setZone] = useState("UTC");
  /*
   * ⚠️ TODAY IS READ IN AN EFFECT, NEVER DURING RENDER. This page is prerendered
   * on the server, where `new Date()` is the SERVER's day — so one card would be
   * ringed «اليوم» in the HTML and a different one after hydration, which React
   * reports as a mismatch and a reader sees as a flicker. `null` until mounted is
   * the same shape the timezone above already uses, for the same reason.
   */
  const [today, setToday] = useState<number | null>(null);

  useEffect(() => {
    // ⚠️ Converted in the effect, not during render: the server does not know
    // the visitor's zone, so rendering UTC first and correcting on mount keeps
    // the times crawlable while still being right for the reader. The zone is
    // always named beside them — an unlabelled 16:00 is worse than useless to
    // someone in Cairo. The conversion itself lives in `lib/availability`, with
    // its inverse, so the calendar and the wizard cannot drift apart.
    setLocalised(slots.map((slot) => toLocalSlot(slot)));
    setZone(Intl.DateTimeFormat().resolvedOptions().timeZone);
    setToday(new Date().getDay());
  }, [slots]);

  if (slots.length === 0) {
    return (
      <p className="rounded-2xl border border-dashed border-line p-8 text-center text-sm text-ink-muted">
        لم يحدّد هذا المدرّس أوقات توفّره بعد.
      </p>
    );
  }

  const byDay = DAYS.map((_, day) => localised.filter((slot) => slot.day_of_week === day));
  const weeklyMinutes = localised.reduce((total, slot) => total + slotMinutes(slot), 0);
  const openDays = byDay.filter((daySlots) => daySlots.length > 0).length;

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
        <span className="inline-flex items-center gap-2 text-ink-muted">
          <WeekIcon />
          {formatDays(openDays)} متاحة أسبوعياً
        </span>
        <span className="inline-flex items-center gap-2 text-ink-muted">
          <DurationIcon />
          {formatDuration(weeklyMinutes)} في الأسبوع
        </span>
        <span className="text-ink-muted">
          بتوقيتك المحلي (<span className="font-medium text-ink">{zone}</span>)
        </span>
      </div>

      {/*
        | ⚠️ A FLOOR, NOT A COLUMN COUNT — the `StatBar` lesson reached from a
        | second direction. `xl:grid-cols-7` forced seven tracks whatever the
        | container could give them, and «19:00 – 15:00» spilled out of its own
        | chip at the width that produced. A breakpoint answers «how wide is the
        | screen»; the question here is «how wide must a DAY be», and the two stop
        | agreeing at the first longer time or narrower sidebar.
        |
        | 9.5rem holds `HH:MM – HH:MM` plus its icon on one line. Seven still fit
        | on a wide screen — there are only seven items and `auto-fit` collapses
        | the rest — and below that the week wraps to a second row rather than
        | squeezing.
      */}
      <ul className="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-3">
        {byDay.map((daySlots, day) => {
          const open = daySlots.length > 0;
          const isToday = today === day;

          return (
            <li
              key={DAYS[day]}
              className="animate-float-in"
              // Staggered 40ms apart, so the week deals itself out instead of
              // appearing as one block. The reduced-motion rule in `globals.css`
              // zeroes the DELAY as well as the duration — without that, a reader
              // who asked for less motion gets an invisible card, not a still one.
              style={{ animationDelay: `${day * 40}ms` }}
            >
              <div
                className={`group flex h-full flex-col rounded-2xl border p-3.5 transition duration-200 ease-out ${
                  open
                    ? "border-line bg-surface-raised hover:-translate-y-1 hover:border-primary hover:shadow-md motion-reduce:hover:translate-y-0"
                    : "border-dashed border-line"
                } ${isToday ? "ring-2 ring-primary/40" : ""}`}
              >
                <h3 className="mb-2 flex items-center justify-between gap-1 text-sm font-bold text-ink">
                  <span>{DAYS[day]}</span>
                  {isToday && (
                    <span className="rounded-full bg-primary-soft px-2 py-0.5 text-[11px] font-semibold text-primary-ink">
                      اليوم
                    </span>
                  )}
                </h3>

                {open ? (
                  <ul className="space-y-1.5">
                    {daySlots.map((slot) => {
                      const { label, Icon } = partOfDay(slot.start_time);

                      return (
                        <li
                          key={`${slot.start_time}-${slot.end_time}`}
                          className="rounded-xl bg-secondary/12 px-2.5 py-2 text-secondary-ink transition duration-200 group-hover:bg-secondary/20"
                        >
                          {/* `flex-wrap`: the times go to a second line rather
                              than out of the chip. A card can always be narrower
                              than its content on some screen, and a time that
                              overflows is unreadable in a way a wrapped one is
                              not. */}
                          <span className="flex flex-wrap items-center gap-x-1.5 text-xs font-semibold">
                            {/* Decoration: `partOfDay` returns the label too, and
                                that is the text a screen reader announces. */}
                            <span aria-hidden="true">
                              <Icon />
                            </span>
                            <bdi>{slot.start_time}</bdi>
                            <span aria-hidden="true">–</span>
                            <span className="sr-only">إلى</span>
                            <bdi>{slot.end_time}</bdi>
                          </span>
                          <span className="mt-0.5 block text-[11px] text-ink-muted">
                            {label} · {formatDuration(slotMinutes(slot))}
                          </span>
                        </li>
                      );
                    })}
                  </ul>
                ) : (
                  <p className="text-xs text-ink-muted">غير متاح</p>
                )}
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
