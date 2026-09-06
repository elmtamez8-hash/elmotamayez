"use client";

import { use, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { formatDate } from "@/lib/labels";
import { AlertIcon, CertificateIcon } from "@/components/icons";

interface VerifyResponse {
  valid: boolean;
  certificate?: {
    certificate_number: string;
    verification_code: string;
    issue_reason: string;
    issued_at: string;
    course_title: string | null;
    student_name: string | null;
  };
}

const REASONS: Record<string, string> = {
  course_completed: "إتمام الكورس",
  exam_passed: "اجتياز الاختبار",
  manual: "إصدار يدوي",
};

export default function VerifyCertificatePage({
  params,
}: {
  params: Promise<{ code: string }>;
}) {
  const { code } = use(params);
  const [data, setData] = useState<VerifyResponse | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get<VerifyResponse>(`/certificates/verify/${code}`)
      .then(setData)
      // A failed lookup and an invalid code are the same answer to the person
      // checking: this certificate cannot be confirmed.
      .catch(() => setData({ valid: false }))
      .finally(() => setLoading(false));
  }, [code]);

  if (loading) {
    return (
      <main
        id="main"
        className="flex min-h-[60vh] items-center justify-center text-ink-muted"
      >
        جارٍ التحقّق…
      </main>
    );
  }

  return (
    <main id="main" className="flex min-h-[60vh] items-center justify-center px-4 py-10">
      <div className="w-full max-w-md">
        {data?.valid && data.certificate ? (
          <div className="overflow-hidden rounded-2xl border border-line bg-surface-raised">
            <div className="bg-secondary p-8 text-center text-white">
              <CertificateIcon className="mx-auto mb-3 h-16 w-16" />
              <h1 className="text-2xl font-bold">الشهادة موثّقة</h1>
              <p className="mt-1 text-sm">هذه الشهادة صحيحة وصادرة من المنصة.</p>
            </div>
            <dl className="space-y-3 p-6">
              <Detail label="رقم الشهادة" value={data.certificate.certificate_number} numeric />
              <Detail label="الطالب" value={data.certificate.student_name ?? "—"} />
              <Detail label="الكورس" value={data.certificate.course_title ?? "—"} />
              <Detail label="تاريخ الإصدار" value={formatDate(data.certificate.issued_at)} />
              <Detail
                label="سبب الإصدار"
                value={
                  REASONS[data.certificate.issue_reason] ?? data.certificate.issue_reason
                }
              />
            </dl>
          </div>
        ) : (
          <div
            role="alert"
            className="rounded-2xl border border-line bg-surface-raised p-8 text-center"
          >
            <AlertIcon className="mx-auto mb-4 h-12 w-12 text-danger-ink" />
            <h1 className="mb-2 text-xl font-bold text-ink">شهادة غير صالحة</h1>
            <p className="text-ink-muted">
              رمز التحقّق غير موجود أو لم يعد صالحاً. تأكّد من نسخ الرمز كاملاً.
            </p>
          </div>
        )}
      </div>
    </main>
  );
}

function Detail({
  label,
  value,
  numeric,
}: {
  label: string;
  value: string;
  numeric?: boolean;
}) {
  return (
    <div className="flex justify-between gap-4 border-b border-line pb-2 text-sm">
      <dt className="text-ink-muted">{label}</dt>
      <dd className="text-end font-medium text-ink">
        {numeric ? <bdi>{value}</bdi> : value}
      </dd>
    </div>
  );
}
