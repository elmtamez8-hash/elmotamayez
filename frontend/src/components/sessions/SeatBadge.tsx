import { Badge } from "@/components/ui/Badge";
import type { ClassSession } from "@/lib/class-sessions";
import { counted, NOUNS } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";

/**
 * How many seats are left.
 *
 * The number is always spelled out next to the tone, never carried by colour
 * alone — a full session and a nearly-full one must be distinguishable by
 * someone who cannot separate the two colours.
 *
 * ⚠️ It read «5 من 5 مقعد متاح» on production (2026-09-24): Western digits, and
 * a noun that agreed with neither number. The count goes through `counted()`
 * and the total follows it, so the noun agrees with the number it sits beside.
 */
export function SeatBadge({ seats }: { seats: ClassSession["seats"] }) {
  if (seats.available === 0) {
    return <Badge tone="danger">اكتملت المقاعد</Badge>;
  }

  return (
    <Badge tone={seats.available <= 2 ? "warning" : "success"}>
      {counted(seats.available, NOUNS.seatsAvailable)} من <bdi>{arabicNumber(seats.total)}</bdi>
    </Badge>
  );
}
