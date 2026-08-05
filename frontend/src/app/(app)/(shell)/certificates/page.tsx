"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import type { Certificate } from "@/lib/types";
import { formatDate } from "@/lib/labels";
import { Button } from "@/components/ui/Button";
import { CertificateIcon, ChevronEndIcon } from "@/components/icons";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { ErrorState } from "@/components/ui/states/ErrorState";

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
      <h2 className="text-2xl font-bold text-ink">شهاداتي</h2>

      {loading ? (
        <RowsSkeleton />
      ) : failed ? (
        <ErrorState onRetry={load} />
      ) : certificates.length === 0 ? (
        <EmptyState
          title="لم تحصل على شهادة بعد"
          description="أكمل كورساً لتحصل على أولى شهاداتك."
          action={<Button href="/enrollments">تابع كورساتك</Button>}
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {certificates.map((cert) => (
            <article
              key={cert.uuid}
              className="overflow-hidden rounded-2xl border border-line bg-surface-raised"
            >
              {/* The one place a brand fill carries the whole block. accent's
                  paired foreground is dark, not white — white lands at 2.4:1. */}
              <div className="bg-accent p-6 text-accent-foreground">
                <CertificateIcon className="mb-2 h-10 w-10" />
                <p className="text-sm font-medium">
                  <bdi>{cert.certificate_number}</bdi>
                </p>
              </div>
              <div className="p-5">
                <h3 className="mb-1 font-semibold text-ink">{cert.course_title}</h3>
                <p className="mb-3 text-xs text-ink-muted">
                  صدرت في {formatDate(cert.issued_at)}
                </p>
                <Link
                  href={`/certificates/verify/${cert.verification_code}`}
                  className="inline-flex items-center gap-1 rounded text-sm text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  تحقّق من الشهادة
                  <ChevronEndIcon />
                </Link>
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
