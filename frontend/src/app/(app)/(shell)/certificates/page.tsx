"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Certificate } from "@/lib/types";

export default function CertificatesPage() {
  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<{ data: Certificate[] }>("/certificates")
      .then((res) => setCertificates(res.data ?? []))
      .catch((err: unknown) => setError(errorMessage(err, "Could not load certificates")))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">My Certificates</h2>

      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      {certificates.length === 0 ? (
        <div className="rounded-xl bg-white p-12 text-center shadow-sm ring-1 ring-gray-200">
          <p className="text-gray-500">No certificates yet. Complete a course to earn your first certificate!</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {certificates.map((cert) => (
            <div key={cert.uuid} className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
              <div className="bg-gradient-to-br from-amber-400 to-orange-500 p-6 text-white">
                <svg className="mb-2 h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                <p className="text-sm font-medium">{cert.certificate_number}</p>
              </div>
              <div className="p-5">
                <h3 className="mb-1 font-semibold">{cert.course_title}</h3>
                <p className="mb-3 text-xs text-gray-500">Issued {new Date(cert.issued_at).toLocaleDateString()}</p>
                <a
                  href={`/certificates/verify/${cert.verification_code}`}
                  className="text-sm text-indigo-600 hover:underline"
                >
                  Verify certificate →
                </a>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
