"use client";

import { useCallback, useEffect, useState, type ComponentType } from "react";

import {
  ArrowUpIcon,
  AssignmentIcon,
  CheckIcon,
  MessagesIcon,
  ProgressIcon,
  type IconProps,
} from "@/components/icons";
import { AxisScore } from "@/components/reviews/AxisScore";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { family, type GuardianRelation } from "@/lib/notifications";
import { arabicDecimal } from "@/lib/numerals";
import { PERIODIC_AXES, periodLabel, reviews, type PeriodicReview } from "@/lib/reviews";

/**
 * An icon per axis, and only where one is honest.
 *
 * ⚠️ FROM THE EXISTING SET, NEVER A NEW GLYPH INVENTED FOR THE OCCASION. Four
 * near-misses would be worse than four numbers: a reader who has to decode the
 * picture reads the label anyway, and has been slowed down for nothing.
 * «المشاركة» is speaking up in the room, «الواجبات» is what was handed in, and
 * «التحسّن» is the direction of travel — each of the three has an exact icon
 * already in the product, and «الالتزام» takes the tick that means «did what was
 * asked».
 */
const AXIS_ICONS: Record<string, ComponentType<IconProps>> = {
  commitment: CheckIcon,
  participation: MessagesIcon,
  homework: AssignmentIcon,
  improvement: ArrowUpIcon,
};

/**
 * The student's own periodic assessments — and a guardian's view of a child's
 * (spec 010 · US4 · FR-029).
 *
 * ⚠️ ONE SCREEN FOR TWO READERS, WHICH IS WHY THE NOTIFICATION HAS ONE `actionUrl`.
 * A student opens their own; a guardian picks a child and the same list arrives
 * for them, gated on the platform by `childrenOf(..., Results)` — the relation AND
 * the consent, never the relation alone. Without the picker the guardian half of
 * FR-029 would be an endpoint with no door: the message would arrive, the link
 * would work, and the page would be empty.
 *
 * ⚠️ THE LIST IS WHATEVER THE SERVER RETURNS, WITH NO FILTER OF OUR OWN. Drafts
 * never arrive: the endpoint refuses them, and a client-side `is_published` check
 * would put the guard in the one place a reader can edit. The same goes for
 * whose rows these are — `student_user_id` is filtered on the server, because
 * `WorkspaceScope` is inert for a student and there is nothing else between this
 * screen and every assessment on the platform.
 */
export default function MyReviewsPage() {
  const { user } = useAuth();
  const isGuardian = user?.platform_role === "parent";

  const [rows, setRows] = useState<PeriodicReview[]>([]);
  const [children, setChildren] = useState<GuardianRelation[]>([]);
  const [child, setChild] = useState<string>("");
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");

  useEffect(() => {
    if (!isGuardian) return;

    family
      .list()
      .then((response) => {
        /*
         * ⚠️ ONLY ROWS THAT CARRY A `student_uuid`. The resource sends it for the
         * guardian on the row and for nobody else, and a relation added by NAME —
         * a child who has not signed up yet — has no account to show a list for.
         * `student_has_account` and this field move together.
         */
        const linked = (response.data ?? []).filter(
          (relation) => relation.status === "active" && relation.student_uuid !== undefined,
        );

        setChildren(linked);
        setChild(linked[0]?.student_uuid ?? "");
      })
      .catch(() => setChildren([]));
  }, [isGuardian]);

  const load = useCallback(() => {
    // A guardian with no linked child has nothing to ask about — asking anyway
    // would send `?student=` empty and read the guardian's own (empty) list,
    // which looks like «your child has no assessments».
    if (isGuardian && child === "") {
      setRows([]);
      setState("ready");

      return;
    }

    setState("loading");

    reviews
      .mine(isGuardian ? child : undefined)
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, [isGuardian, child]);

  useEffect(load, [load]);

  if (state === "error") return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h2 className="flex items-center gap-2 text-2xl font-bold text-ink">
          <ProgressIcon className="h-6 w-6 text-primary-ink" />
          {isGuardian ? "التقييمات الدورية" : "تقييماتي الدورية"}
        </h2>
        <p className="mt-1 text-sm text-ink-muted">
          {isGuardian
            ? "ما كتبه المدرّسون عن التزام ابنك ومشاركته وواجباته وتحسّنه."
            : "ما كتبه مدرّسوك عن التزامك ومشاركتك وواجباتك وتحسّنك."}
        </p>
      </header>

      {isGuardian && children.length > 1 && (
        <SelectField
          id="child"
          label="الابن/الابنة"
          value={child}
          onChange={setChild}
          options={children.map((relation) => ({
            value: relation.student_uuid ?? "",
            label: relation.student_name,
          }))}
        />
      )}

      {isGuardian && children.length === 0 ? (
        <EmptyState
          title="لا يوجد ابن مرتبط بحساب"
          description="اربط ابنك بحسابه من صفحة المرتبطين ليظهر تقييمه هنا."
        />
      ) : state === "loading" ? (
        <RowsSkeleton />
      ) : rows.length === 0 ? (
        <EmptyState
          title="لا توجد تقييمات بعد"
          description="يظهر هنا تقييم مدرّسك الدوري فور نشره."
        />
      ) : (
        <ul className="space-y-4">
          {rows.map((review, index) => (
            /*
              ⚠️ THE STAGGER IS CAPPED AND THE FILL MODE IS `both` — the timetable's
              rule, and for its reason: `banner-rise` covers the delay as well as
              the animation, so a card is not painted, hidden when its turn comes
              and repainted. The cap stops a long history making the last card
              arrive a second and a half after the first, and the reduced-motion
              block in `globals.css` zeroes all of it.
            */
            <li
              key={review.uuid}
              className="banner-rise"
              style={{ animationDelay: `${Math.min(index, 6) * 60}ms` }}
            >
              <Card as="article">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-semibold text-ink">{periodLabel(review)}</p>
                    <p className="mt-0.5 text-xs text-ink-muted">
                      {review.teacher_name ?? "مدرّسك"}
                    </p>
                  </div>

                  {/*
                    ⚠️ THE AVERAGE IS THE ONE NUMBER THAT DESERVES SIZE. It was a
                    clause at the end of a muted sentence, in the same weight as
                    the teacher's name — so the figure the whole card exists to
                    deliver was the least visible thing on it.
                  */}
                  <div className="shrink-0 rounded-2xl bg-primary-soft px-3 py-1.5 text-center">
                    <p className="text-lg font-bold leading-none text-primary-ink">
                      <bdi>{arabicDecimal(review.average)}</bdi>
                    </p>
                    <p className="mt-1 text-[11px] text-ink-muted">من ٥</p>
                  </div>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {PERIODIC_AXES.map((axis, axisIndex) => (
                    <AxisScore
                      key={axis.key}
                      label={axis.label}
                      value={review[axis.key]}
                      Icon={AXIS_ICONS[axis.key] ?? ProgressIcon}
                      // Each card's dots follow its own card in, rather than every
                      // card animating against the clock of the first.
                      delayMs={Math.min(index, 6) * 60 + axisIndex * 30}
                    />
                  ))}
                </div>

                {review.note !== null && (
                  <p className="animate-float-in mt-4 rounded-2xl bg-primary-soft/60 p-3 text-sm text-ink">
                    {review.note}
                  </p>
                )}
              </Card>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
