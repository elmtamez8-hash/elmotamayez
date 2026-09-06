"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import type { Certificate } from "@/lib/types";
import { certificateReasonLabel, formatDate } from "@/lib/labels";
import { Button } from "@/components/ui/Button";
import {
  CertificateIcon,
  ChevronEndIcon,
  IssuedDateIcon,
  StudentIcon,
  SubjectIcon,
  VerifiedIcon,
} from "@/components/icons";
import { CertificateCardSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

/**
 * «شهاداتي» — what the student earned, as cards they can read at a glance.
 *
 * ⚠️ THE CARD DOES NOT DRAW THE CERTIFICATE, and that is a measurement rather
 * than an omission. The artwork needs `design`, which costs a query — and an API
 * Resource runs ONCE PER ROW, so putting it on the shared `CertificateResource`
 * makes this list an N+1 by construction, fifteen extra reads a page. It lives on
 * `PublicCertificateResource` alone, which answers one certificate at a time.
 * The details below are every field the list payload actually carries.
 */
export default function CertificatesPage() {
  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Certificate[] }>("/certificates")
      .then((res) => setCertificates(res.data ?? []))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  return (
    <div className="space-y-6">
      <header>
        <h2 className="text-2xl font-bold text-ink">شهاداتي</h2>
        <p className="mt-1 text-sm text-ink-muted">
          كل شهادة هنا موثّقة برمز تحقّق دائم — افتحها لترى الشهادة نفسها كما تُطبع.
        </p>
      </header>

      {loading ? (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          <CertificateCardSkeleton />
          <CertificateCardSkeleton />
          <CertificateCardSkeleton />
        </div>
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : certificates.length === 0 ? (
        <EmptyState
          title="لم تحصل على شهادة بعد"
          description="أكمل كورساً لتحصل على أولى شهاداتك."
          action={<Button href="/enrollments">تابع كورساتك</Button>}
        />
      ) : (
        <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {certificates.map((cert, index) => (
            <li key={cert.uuid}>
              <CertificateCard certificate={cert} index={index} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function CertificateCard({ certificate, index }: { certificate: Certificate; index: number }) {
  return (
    <article
      /*
        ⚠️ `animate-float-in` IS THE EXISTING CLASS, not a new keyframe — it
        already carries `animation-fill-mode: both`, which a staggered list cannot
        do without: a delayed element with no fill mode is painted, hidden the
        instant its animation starts, and faded back in. And the reduced-motion
        block in `globals.css` zeroes the DELAY as well as the duration, so a
        reader who asked for less motion gets the card immediately rather than an
        invisible one for the length of the stagger.
      */
      className="group animate-float-in flex h-full flex-col overflow-hidden rounded-2xl border border-line bg-surface-raised transition duration-200 hover:-translate-y-1 hover:border-primary hover:shadow-md motion-reduce:transition-none motion-reduce:hover:translate-y-0"
      // Capped: the twentieth card must not arrive a second and a half late.
      style={{ animationDelay: `${Math.min(index, 7) * 60}ms` }}
    >
      {/*
        The one place a brand fill carries a whole block. `--color-accent` was
        darkened to #956d2f precisely so it can carry white, which is what
        `--color-accent-foreground` is — no literal colour anywhere here.
      */}
      <div className="flex items-center gap-3 bg-accent p-5 text-accent-foreground">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-accent-foreground/15">
          <CertificateIcon className="h-6 w-6" />
        </span>
        <div className="min-w-0">
          <p className="text-xs opacity-90">رقم الشهادة</p>
          {/* `bdi` because an ASCII code inside an RTL paragraph reorders itself. */}
          <p className="truncate text-sm font-semibold">
            <bdi>{certificate.certificate_number}</bdi>
          </p>
        </div>
      </div>

      <div className="flex flex-1 flex-col gap-4 p-5">
        <h3 className="font-semibold text-ink">{certificate.course_title ?? "—"}</h3>

        <dl className="space-y-2.5 text-sm">
          <Detail
            icon={<IssuedDateIcon />}
            label="تاريخ المنح"
            value={formatDate(certificate.issued_at)}
          />
          <Detail
            icon={<SubjectIcon />}
            label="سبب الإصدار"
            value={certificateReasonLabel(certificate.issue_reason)}
          />
          {/*
            ⚠️ The name FROZEN on the certificate, not the account's name today.
            A student who changes their name still holds a document that says what
            it said the day they earned it, and seeing the two differ here is the
            only place that is ever explained.
          */}
          <Detail
            icon={<StudentIcon />}
            label="الاسم على الشهادة"
            value={certificate.student_name ?? "—"}
          />
        </dl>

        <Link
          href={`/certificates/verify/${certificate.verification_code}`}
          className="mt-auto inline-flex items-center gap-1 rounded text-sm font-medium text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          <VerifiedIcon />
          افتح الشهادة وتحقّق منها
          {/*
            ⚠️ A LOGICAL MARGIN, never `translate-x`. The page is RTL and the
            chevron already points at the reading end by its NAME (`ChevronEndIcon`);
            a physical translate would nudge it backwards here and forwards in a
            language nobody on this platform reads.
          */}
          <ChevronEndIcon className="transition-[margin] duration-200 group-hover:ms-1 motion-reduce:transition-none" />
        </Link>
      </div>
    </article>
  );
}

function Detail({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-start gap-2">
      <span className="mt-0.5 shrink-0 text-ink-muted">{icon}</span>
      <div className="min-w-0">
        <dt className="text-xs text-ink-muted">{label}</dt>
        <dd className="truncate text-ink">{value}</dd>
      </div>
    </div>
  );
}
