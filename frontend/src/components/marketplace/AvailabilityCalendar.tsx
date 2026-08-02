"use client";

import { useEffect, useState } from "react";
import type { AvailabilityItem } from "@/lib/public-api";

const DAYS = ["الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];

/**
 * Weekly availability, converted from the stored UTC values to the visitor's own
 * timezone (FR-029).
 *
 * The conversion runs in an effect rather than during render because the server
 * does not know the visitor's zone; rendering UTC first and correcting on mount
 * keeps the times crawlable while still being right for the reader. The zone is
 * always named — an unlabelled 16:00 is worse than useless to someone in Cairo.
 */
function toLocal(item: AvailabilityItem): AvailabilityItem {
  const [hours, minutes] = item.start_time.split(":").map(Number);
  const [endHours, endMinutes] = item.end_time.split(":").map(Number);

  // Any Sunday works as an anchor; only the weekday offset and clock time matter.
  const start = new Date(Date.UTC(2024, 0, 7 + item.day_of_week, hours, minutes));
  const end = new Date(Date.UTC(2024, 0, 7 + item.day_of_week, endHours, endMinutes));

  const format = (date: Date) =>
    `${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;

  return {
    day_of_week: start.getDay(),
    start_time: format(start),
    end_time: format(end),
  };
}

export function AvailabilityCalendar({ slots }: { slots: AvailabilityItem[] }) {
  const [localised, setLocalised] = useState(slots);
  const [zone, setZone] = useState("UTC");

  useEffect(() => {
    setLocalised(slots.map(toLocal));
    setZone(Intl.DateTimeFormat().resolvedOptions().timeZone);
  }, [slots]);

  if (slots.length === 0) {
    return (
      <p className="rounded-2xl border border-dashed border-line p-8 text-center text-sm text-ink-muted">
        لم يحدّد هذا المدرّس أوقات توفّره بعد.
      </p>
    );
  }

  const byDay = DAYS.map((_, day) =>
    localised.filter((slot) => slot.day_of_week === day),
  );

  return (
    <div>
      <p className="mb-4 text-sm text-ink-muted">
        الأوقات معروضة بتوقيتك المحلي (<span className="font-medium text-ink">{zone}</span>).
      </p>

      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {byDay.map((daySlots, day) => (
          <li key={DAYS[day]} className="rounded-xl border border-line p-4">
            <h3 className="mb-2 text-sm font-bold text-ink">{DAYS[day]}</h3>
            {daySlots.length === 0 ? (
              <p className="text-xs text-ink-muted">غير متاح</p>
            ) : (
              <ul className="space-y-1.5">
                {daySlots.map((slot) => (
                  <li
                    key={`${slot.start_time}-${slot.end_time}`}
                    className="rounded-lg bg-secondary/12 px-2 py-1 text-xs font-medium text-secondary-ink"
                  >
                    <bdi>{slot.start_time}</bdi> – <bdi>{slot.end_time}</bdi>
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
