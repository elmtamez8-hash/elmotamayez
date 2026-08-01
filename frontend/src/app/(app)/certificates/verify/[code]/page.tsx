"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { use } from "react";

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

export default function VerifyCertificatePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = use(params);
  const [data, setData] = useState<VerifyResponse | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get<VerifyResponse>(`/certificates/verify/${code}`)
      .then(setData)
      .catch(() => setData({ valid: false }))
      .finally(() => setLoading(false));
  }, [code]);

  if (loading) return <div className="flex min-h-screen items-center justify-center text-gray-400">Verifying...</div>;

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-md">
        {data?.valid && data.certificate ? (
          <div className="overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200">
            <div className="bg-gradient-to-br from-amber-400 to-orange-500 p-8 text-center text-white">
              <svg className="mx-auto mb-3 h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
              <h1 className="text-2xl font-bold">Certificate Verified</h1>
              <p className="mt-1 text-amber-100">This certificate is valid and authentic</p>
            </div>
            <div className="space-y-3 p-6">
              <Detail label="Certificate Number" value={data.certificate.certificate_number} />
              <Detail label="Student" value={data.certificate.student_name ?? "—"} />
              <Detail label="Course" value={data.certificate.course_title ?? "—"} />
              <Detail label="Issued" value={new Date(data.certificate.issued_at).toLocaleDateString()} />
              <Detail label="Reason" value={data.certificate.issue_reason.replace(/_/g, " ")} />
            </div>
          </div>
        ) : (
          <div className="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-200">
            <div className="mb-4 text-5xl">⚠️</div>
            <h1 className="mb-2 text-xl font-bold text-gray-900">Invalid Certificate</h1>
            <p className="text-gray-500">The verification code could not be found or is no longer valid.</p>
          </div>
        )}
      </div>
    </div>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between border-b border-gray-100 pb-2 text-sm">
      <span className="text-gray-500">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
