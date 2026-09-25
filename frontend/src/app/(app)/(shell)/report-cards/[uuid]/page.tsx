"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/ui/PageHeader";
import { StatTile } from "@/components/ui/StatTile";
import { ArrowUpIcon, ProgressIcon } from "@/components/icons";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { arabicDecimal, arabicNumber } from "@/lib/numerals";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { cardPeriodLabel, GRADE_COMPONENTS, reportCards, type ReportCard } from "@/lib/reviews";

/**
 * One cumulative card: each teacher's contribution, separately (FR-041, FR-051).
 *
 * ⚠️ NO NUMBER ON THIS PAGE IS COMPUTED HERE. The percentages and the weights
 * arrive already re-weighted, because the card is a snapshot (FR-052): a browser
 * that recomputed the total from the components would show a different figure the
 * moment the teacher changed their weighting — on a document the family has
 * already read, with nothing to say it had changed.
 *
 * ⚠️ AND A COMPONENT MISSING FROM `components` IS ABSENT, NOT ZERO. The page
 * renders whatever the server sent and never fills the gaps in with zeros: a
 * student who was set no homework is not a student who scored nothing on it.
 */
export default function ReportCardPage() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const [card, setCard] = useState<ReportCard | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");

    reportCards
      .show(uuid)
      .then((response) => {
        setCard(response);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, [uuid]);

  useEffect(load, [load]);

  if (state === "error") return <ErrorState onRetry={load} />;
  if (state === "loading" || card === null) return <RowsSkeleton />;

  /*
   * ⚠️ FETCH THE FILE TOO, never navigate to it. Both the URL request AND the
   * file route sit behind `auth:sanctum`, and the token lives in `localStorage`,
   * so `window.location` / `<a href>` reach the file with no `Authorization`
   * header and are answered `401` — which is what this button did for every
   * student and guardian until 2026-09-25. `api.download()` sends the bearer.
   * The URL is signed and lasts five minutes, so it is minted per click.
   */
  async function openFile() {
    setDownloading(true);
    setDownloadError(null);

    try {
      const { url } = await reportCards.fileUrl(card!.uuid);
      await api.download(url, `report-card-${card!.uuid}.pdf`);
    } catch (error) {
      setDownloadError(userMessage(error));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={ProgressIcon}
        title={cardPeriodLabel(card)}
        description="كشف التقديرات التراكمي"
      />

      <div className="grid grid-cols-2 gap-4">
        <StatTile
          label="التقدير العام"
          value={card.overall_pct === null ? "—" : `${arabicDecimal(card.overall_pct)}٪`}
          Icon={ProgressIcon}
          emphasis
        />
        {/* «—», never «٠٪»: no teacher rated improvement is a different
            statement from «did not improve», and this is the number a parent
            reads first. */}
        <StatTile
          label="مؤشّر التحسّن"
          value={
            card.improvement_index === null
              ? "—"
              : `${arabicDecimal(card.improvement_index)}٪`
          }
          Icon={ArrowUpIcon}
        />
      </div>

      {card.has_file ? (
        <div className="space-y-2">
          <Button variant="secondary" loading={downloading} onClick={openFile}>
            تنزيل الملفّ
          </Button>

          {/* Never the raw error: `userMessage()` is what turns one into a
              sentence, and «nothing happened» would be worse than either. */}
          {downloadError !== null && (
            <p role="alert" className="text-sm text-danger-ink">
              {downloadError}
            </p>
          )}
        </div>
      ) : (
        <p className="text-sm text-ink-muted">الملفّ قيد التجهيز — حدّث الصفحة بعد قليل.</p>
      )}

      {(card.segments ?? []).length === 0 ? (
        <EmptyState
          title="لا توجد درجات في هذه الفترة"
          description="يظهر هنا تقدير كلّ مدرّس فور تسجيل درجاته."
        />
      ) : (
        <ul className="space-y-4">
          {(card.segments ?? []).map((segment) => (
            <li key={segment.uuid}>
              <Card as="article" interactive>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <h3 className="font-semibold text-ink">{segment.teacher_name ?? "مدرّس"}</h3>
                  <p className="text-lg font-bold text-ink">
                    {segment.segment_pct === null
                      ? "—"
                      : `${arabicDecimal(segment.segment_pct)}٪`}
                  </p>
                </div>

                <dl className="mt-4 space-y-2 text-sm">
                  {GRADE_COMPONENTS.filter(
                    (component) => segment.components[component.key] !== undefined,
                  ).map((component) => {
                    const row = segment.components[component.key];

                    return (
                      <div key={component.key} className="flex items-baseline justify-between gap-3">
                        <dt className="text-ink-muted">
                          {component.label}
                          {/* The weight AS RE-WEIGHTED, so the shown shares add
                              up to 100 and the reader can check the arithmetic
                              that produced the grade above them. */}
                          <span className="ms-2 text-xs">(نسبته من التقدير {arabicNumber(Math.round(row.weight))}٪)</span>
                        </dt>
                        <dd className="font-semibold text-ink">{arabicDecimal(row.pct)}٪</dd>
                      </div>
                    );
                  })}
                </dl>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <p className="text-xs text-ink-muted">
        إن لم يُقيَّم أحد الجوانب في هذه الفترة فلا يُحسب صفراً، بل تُوزَّع نسبته على الجوانب الأخرى.
      </p>
    </div>
  );
}
