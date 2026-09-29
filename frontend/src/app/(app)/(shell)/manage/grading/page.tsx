"use client";

import { useCallback, useEffect, useState } from "react";

import {
  AutoGradedIcon,
  ClockIcon,
  DocumentIcon,
  EyeIcon,
  EyeOffIcon,
  GradingIcon,
  UserIcon,
} from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { RecordList, RecordRow } from "@/components/ui/RecordList";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatStrip } from "@/components/ui/StatStrip";
import { StatTile } from "@/components/ui/StatTile";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { grading, type GradingQueueRow } from "@/lib/grading";
import { counted, formatDate, formatDateTime } from "@/lib/labels";
import { can, P } from "@/lib/permissions";
import { arabicNumber } from "@/lib/numerals";

/** «٣ أوراق» — a paper is feminine. */
const PAPERS = { one: "ورقة واحدة", two: "ورقتان", few: "أوراق", many: "ورقة", other: "ورقة" };

/** «٣ أسئلة تنتظر» — the questions on one paper that no machine could mark. */
const WAITING_QUESTIONS = {
  one: "سؤال واحد ينتظر",
  two: "سؤالان ينتظران",
  few: "أسئلة تنتظر",
  many: "سؤالاً ينتظر",
  other: "سؤال ينتظر",
};

/**
 * What is waiting on a person (FR-027).
 *
 * ⚠️ THE AUTO-SCORE IS LABELLED, NEVER SHOWN BARE. It is the machine-marked half
 * of an unfinished paper; in a column called "الدرجة" it reads as a result, and a
 * teacher glancing down the list would conclude half the class failed a paper
 * nobody has marked yet. So its meta label is VISIBLE, never `labelHidden`.
 *
 * ⚠️ AND THE ORDER IS THE SERVER'S. Oldest first is the one property that keeps
 * a queue from starving whoever has waited longest; re-sorting in the browser
 * would silently undo it on every page after the first.
 *
 * ⚠️ THE STRIP SHOWS ONLY WHAT THE QUEUE STATES: `meta.total`, and the first
 * row's hand-in time (oldest first, so it IS the longest wait). There is no
 * «graded» count on the wire — the queue is one status — so there is no tile
 * and no chip for one, and nothing is summed off a single page.
 */
export default function GradingQueuePage() {
  const { user } = useAuth();
  const [rows, setRows] = useState<GradingQueueRow[]>([]);
  const [total, setTotal] = useState(0);
  const [state, setState] = useState<"ready" | "loading" | "error">("loading");
  // Read from the queue's meta, never off `rows[0]`: an empty queue has no row.
  const [anonymous, setAnonymous] = useState<boolean | null>(null);

  const load = useCallback(() => {
    setState("loading");

    grading
      .queue()
      .then((response) => {
        setRows(response.data ?? []);
        setTotal(response.meta?.total ?? 0);
        setAnonymous(response.meta?.anonymous ?? null);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const oldest = rows[0]?.submitted_at ?? null;

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={GradingIcon}
        title="لوحة التصحيح"
        description="أوراقٌ فيها أسئلة مقالية سُلّمت وتنتظر قراءتك. الأقدم أولاً، ولا تصل النتيجة الطالبَ قبل أن تنتهي منها."
      />

      {state === "ready" && (
        <StatStrip label="ملخّص التصحيح" columns={2}>
          <StatTile label="أوراق بانتظار التصحيح" value={total} Icon={GradingIcon} emphasis={total > 0} />
          <StatTile
            label="أقدم ورقة تنتظر منذ"
            value={oldest === null ? "—" : formatDate(oldest)}
            Icon={ClockIcon}
          />
        </StatStrip>
      )}

      {anonymous !== null && (
        <AnonymityControl
          anonymous={anonymous}
          canChange={can(user, P.settingsUpdate)}
          onChanged={(now) => {
            setAnonymous(now);
            load();
          }}
        />
      )}

      <section aria-labelledby="grading-queue" className="space-y-4">
        <SectionHeading
          id="grading-queue"
          Icon={DocumentIcon}
          title="الأوراق المنتظرة للتصحيح"
          description={state === "ready" && total > 0 ? `${counted(total, PAPERS)} بانتظار التصحيح.` : undefined}
        />

        {state === "loading" && <RowsSkeleton count={3} />}

        {state === "error" && <ErrorState onRetry={load} />}

        {state === "ready" &&
          (rows.length === 0 ? (
            <EmptyState
              Icon={GradingIcon}
              title="لا شيء ينتظر التصحيح"
              description="كلّ ورقةٍ فيها سؤال مقاليّ قُرئت. تظهر هنا الأوراق الجديدة فور تسليمها."
            />
          ) : (
            <RecordList labelledBy="grading-queue">
              {rows.map((row) => {
                // The word, not an empty title: a blank reads as data that failed
                // to arrive, and the grader needs to know the omission is deliberate.
                const who = row.is_anonymous ? "مُخفى" : (row.student?.name ?? "—");

                return (
                  <RecordRow
                    key={row.uuid}
                    level={4}
                    Icon={row.is_anonymous ? EyeOffIcon : UserIcon}
                    tone={row.is_anonymous ? "neutral" : "info"}
                    title={who}
                    href={`/manage/grading/${row.uuid}`}
                    status={<Badge tone="warning">{counted(row.pending_count, WAITING_QUESTIONS)}</Badge>}
                    description={row.exam_title ?? "—"}
                    meta={[
                      {
                        key: "submitted",
                        label: "سُلّمت",
                        Icon: ClockIcon,
                        value: formatDateTime(row.submitted_at),
                      },
                      {
                        key: "auto",
                        label: "المصحَّح آلياً",
                        Icon: AutoGradedIcon,
                        value: `${arabicNumber(Math.round(row.auto_score))}٪`,
                      },
                    ]}
                    actions={
                      <Button
                        size="sm"
                        variant="secondary"
                        href={`/manage/grading/${row.uuid}`}
                        iconStart={<GradingIcon className="h-4 w-4" />}
                      >
                        صحّح <span className="sr-only">{`ورقة ${who}`}</span>
                      </Button>
                    }
                  />
                );
              })}
            </RecordList>
          ))}
      </section>
    </div>
  );
}

/**
 * Names on or off for the whole workspace (FR-033).
 *
 * ⚠️ THE OWNER'S SWITCH, NOT EVERY GRADER'S. The route asks `settings.update`,
 * which a plain teacher does not hold — so they read the state and are told who
 * can change it, rather than meeting a 403 on a button.
 *
 * ⚠️ AND TURNING IT OFF ASKS FIRST, IN A WINDOW. Anonymity is a promise made to
 * the students; withdrawing it is audited server-side, and one stray press should
 * not be. It is a setting changed while nothing else is happening — the moment a
 * `Modal` is for (`docs/design/manage-pages.md` §8) — not a row action.
 */
function AnonymityControl({
  anonymous,
  canChange,
  onChanged,
}: {
  anonymous: boolean;
  canChange: boolean;
  onChanged: (anonymous: boolean) => void;
}) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const send = async (next: boolean) => {
    setBusy(true);
    setError("");

    try {
      const response = await grading.setAnonymous(next);
      onChanged(response.data.anonymous);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
      setAsking(false);
    }
  };

  return (
    <Card as="section" labelledBy="anonymous-grading">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SectionHeading
          id="anonymous-grading"
          Icon={anonymous ? EyeOffIcon : EyeIcon}
          title="التصحيح بلا أسماء"
          description={
            <>
              {anonymous
                ? "مفعَّل: تُخفى أسماء الطلاب عن كلّ من يصحّح أوراق طلابك."
                : "غير مفعَّل: يظهر اسم الطالب على ورقته أثناء التصحيح."}
              {!canChange && " يغيّره المدرّس صاحب الحساب."}
            </>
          }
        />
        {canChange && (
          <Button
            variant={anonymous ? "ghost" : "secondary"}
            size="sm"
            loading={busy}
            loadingLabel="جارٍ الحفظ…"
            iconStart={anonymous ? <EyeIcon className="h-4 w-4" /> : <EyeOffIcon className="h-4 w-4" />}
            onClick={() => (anonymous ? setAsking(true) : void send(true))}
          >
            {anonymous ? "أظهِر الأسماء" : "أخفِ الأسماء"}
          </Button>
        )}
      </div>

      {error !== "" && (
        <div className="mt-3">
          <Alert tone="danger" title={error} />
        </div>
      )}

      <Modal
        open={asking}
        title="إظهار أسماء الطلاب"
        message="ستظهر الأسماء على كلّ الأوراق لكلّ من يصحّح أوراق طلابك، ويُسجَّل هذا التغيير باسمك."
        confirmLabel="أظهِر الأسماء"
        busy={busy}
        onConfirm={() => void send(false)}
        onCancel={() => setAsking(false)}
      />
    </Card>
  );
}
