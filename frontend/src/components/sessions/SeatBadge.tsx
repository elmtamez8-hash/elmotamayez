import { Badge } from "@/components/ui/Badge";
import type { ClassSession } from "@/lib/class-sessions";

/**
 * How many seats are left.
 *
 * The number is always spelled out next to the tone, never carried by colour
 * alone — a full session and a nearly-full one must be distinguishable by
 * someone who cannot separate the two colours.
 */
export function SeatBadge({ seats }: { seats: ClassSession["seats"] }) {
  if (seats.available === 0) {
    return <Badge tone="danger">اكتملت المقاعد</Badge>;
  }

  return (
    <Badge tone={seats.available <= 2 ? "warning" : "success"}>
      <bdi>
        {seats.available} من {seats.total}
      </bdi>{" "}
      مقعد متاح
    </Badge>
  );
}
