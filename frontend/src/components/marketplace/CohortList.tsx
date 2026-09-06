import { Button } from "@/components/ui/Button";
import { TONE_CLASSES } from "@/lib/labels";
import type { CohortSummary } from "@/lib/public-api";

/**
 * The groups of one course, as a visitor deciding when to study reads them.
 *
 * ⚠️ EVERY COLOUR IS A TOKEN THAT EXISTS. There is no `success` colour in
 * `@theme` — the tone name lives only in `TONE_CLASSES`, which maps it onto
 * `bg-secondary/15 text-secondary-ink`. Tailwind v4 emits no rule at all for a
 * token it has never seen, so `bg-success` would paint nothing, silently, and a
 * test asserting on the label would pass over an invisible badge. That defect
 * has shipped four times in this tree.
 */
const STATUS: Record<CohortSummary["status"], { label: string; tone: keyof typeof TONE_CLASSES }> = {
  open: { label: "مفتوحة", tone: "success" },
  full: { label: "ممتلئة", tone: "warning" },
  closed: { label: "مغلقة", tone: "neutral" },
};

/**
 * How many places are left.
 *
 * ⚠️ ASKED WITH `=== undefined`, NEVER FALSY. A group with one seat left sends
 * `1`; a group with none sends `0` — and `!seats_left` swallows the zero, so the
 * one group a visitor most needs to be told about would render as «غير محدود».
 */
function seats(count: number | undefined): string | null {
  if (count === undefined) return null;
  if (count === 0) return "لا مقاعد متاحة";
  if (count === 1) return "مقعد واحد متبقٍ";
  if (count === 2) return "مقعدان متبقّيان";
  if (count <= 10) return `${count.toLocaleString("ar-QA")} مقاعد متبقّية`;

  return `${count.toLocaleString("ar-QA")} مقعداً متبقّياً`;
}

/**
 * The subscribe invitation on every joinable group (027 · FR-001 · FR-002).
 *
 * ⚠️ ABSENT ON A GROUP THAT CANNOT BE JOINED — NOT DISABLED. A greyed-out button
 * is a promise the product will not keep: it says «this is for you, later»,
 * while a closed or archived group is not opening again and a full one needs a
 * different group rather than patience. The card already says «ممتلئة» or
 * «مغلقة»; a dead control beside that word adds nothing and invites a press.
 *
 * ⚠️ AND THE PREDICATE IS `is_joinable` FROM THE SERVER, never `status === "open"`
 * rebuilt here. That is the same value the purchase route asks, so the card and
 * the door cannot disagree.
 */
export function CohortList({ courseUuid, cohorts }: { courseUuid: string; cohorts: CohortSummary[] }) {
  return (
    <ul className="grid grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] gap-4">
      {cohorts.map((cohort) => {
        const status = STATUS[cohort.status];
        const remaining = seats(cohort.seats_left);

        return (
          <li
            key={cohort.uuid}
            className="flex flex-col gap-3 rounded-2xl border border-line bg-surface-raised p-5 transition duration-200 ease-out hover:-translate-y-1 hover:border-primary hover:shadow-md"
          >
            <div className="flex items-start justify-between gap-3">
              <h3 className="text-sm font-bold leading-snug text-ink">
                {cohort.name}
              </h3>
              <span
                className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${TONE_CLASSES[status.tone]}`}
              >
                {status.label}
              </span>
            </div>

            {cohort.description && (
              <p className="text-xs leading-relaxed text-ink-muted">
                {cohort.description}
              </p>
            )}

            {cohort.schedule.length > 0 ? (
              <ul className="flex flex-wrap gap-2">
                {cohort.schedule.map((slot) => (
                  <li
                    key={slot}
                    className="rounded-lg bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary-ink"
                  >
                    {slot}
                  </li>
                ))}
              </ul>
            ) : (
              // Never an empty gap: a section with nothing in it reads as a
              // broken page rather than as an answer.
              <p className="text-xs text-ink-muted">لم تُجدول حصص بعد</p>
            )}

            {remaining && (
              <p className="mt-auto text-xs font-semibold text-ink-muted">
                {remaining}
              </p>
            )}

            {cohort.is_joinable && (
              <Button
                href={`/subscribe?course=${encodeURIComponent(courseUuid)}&cohort=${encodeURIComponent(cohort.uuid)}`}
                size="sm"
                fullWidth
              >
                اشترك في هذه المجموعة
              </Button>
            )}
          </li>
        );
      })}
    </ul>
  );
}
