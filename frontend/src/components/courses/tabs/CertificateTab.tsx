"use client";

import { CertificateIcon } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { ProgressBar } from "@/components/ui/ProgressBar";
import type { Certificate } from "@/lib/types";
import { formatDate } from "@/lib/labels";

/**
 * The certificate for this course — or, when there is none, THE CONDITION FOR
 * EARNING IT IN WORDS (US2 · FR-020).
 *
 * ⚠️ THE SECOND HALF IS THE REQUIREMENT. An empty state saying «لا شهادة» is a
 * dead end: the student is looking at this tab precisely because they want to
 * know what is left to do, and the answer exists — a certificate is issued when
 * the course is complete, or when its exam is passed. Both conditions are
 * derived from numbers this page already holds, so nothing is re-computed here:
 * the denominator is `CourseProgress`, the same one `MarkLessonComplete` and the
 * publish preview use, and a second one invented for a message is how a student
 * gets shown a percentage nothing else in the product agrees with (SC-018).
 */
export function CertificateTab({
  certificate,
  completedCount,
  countableCount,
  progressPct,
}: {
  certificate: Certificate | null;
  completedCount: number;
  countableCount: number;
  progressPct: number;
}) {
  if (certificate !== null) {
    return (
      <Card as="article">
        <div className="flex flex-wrap items-start gap-4">
          <span
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-secondary/15 text-secondary-ink"
            aria-hidden
          >
            <CertificateIcon />
          </span>

          <div className="min-w-0 flex-1">
            <h3 className="mb-1 font-semibold text-ink">شهادتك في هذه المادّة</h3>
            <p className="mb-1 text-sm text-ink-muted">
              صدرت في <bdi>{formatDate(certificate.issued_at)}</bdi>
            </p>
            <p className="mb-4 text-sm text-ink-muted">
              رقم الشهادة <bdi>{certificate.certificate_number}</bdi>
            </p>

            <Button href={`/certificates`} size="sm">
              افتح شهادتك
            </Button>
          </div>
        </div>
      </Card>
    );
  }

  const remaining = Math.max(0, countableCount - completedCount);

  return (
    <Card as="article">
      <h3 className="mb-2 font-semibold text-ink">لم تصدر شهادتك بعد</h3>

      <p className="mb-4 text-sm leading-relaxed text-ink-muted">
        {/*
          The sentence names what to go and do, and it names a NUMBER the rest of
          the page agrees with — the same counts the progress bar above the tabs
          is drawn from.
        */}
        {countableCount === 0 ? (
          "تصدر الشهادة تلقائياً حين تكتمل المادّة أو تجتاز اختبارها."
        ) : remaining === 0 ? (
          "أتممتَ المادّة — تصدر شهادتك تلقائياً، وقد تستغرق دقائق."
        ) : (
          <>
            تصدر الشهادة تلقائياً حين تُتِمّ المادّة أو تجتاز اختبارها. بقي لك{" "}
            <bdi>{remaining}</bdi> من <bdi>{countableCount}</bdi>.
          </>
        )}
      </p>

      <ProgressBar value={progressPct} label="تقدّمك نحو الشهادة" />
    </Card>
  );
}
