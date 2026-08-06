"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Course, Enrollment, Certificate } from "@/lib/types";
import { useAuth } from "@/lib/auth-context";
import Link from "next/link";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

export default function DashboardPage() {
  const { user } = useAuth();
  const [enrollments, setEnrollments] = useState<Enrollment[]>([]);
  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([
      api.get<{ data: Enrollment[] }>("/enrollments"),
      api.get<{ data: Certificate[] }>("/certificates"),
      api.get<{ data: Course[] }>("/courses"),
    ])
      .then(([enr, cert, crs]) => {
        setEnrollments(enr.data ?? []);
        setCertificates(cert.data ?? []);
        setCourses(crs.data ?? []);
      })
      // Swallowing the rejection used to render an empty dashboard on a dead
      // backend, which reads as "you have nothing" rather than "we could not
      // load this" (FR-018).
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton />;
  if (failed) return <ErrorState onRetry={load} />;

  const activeEnrollments = enrollments.filter((e) => e.status === "active");
  const completedCount = enrollments.filter((e) => e.status === "completed").length;

  return (
    <div className="space-y-8">
      <div>
        <h2 className="text-2xl font-bold text-ink">أهلاً بعودتك، {user?.first_name}</h2>
        <p className="text-ink-muted">هذه نظرة عامة على تقدّمك الدراسي.</p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="كورسات جارية" value={activeEnrollments.length} />
        <StatCard label="كورسات مكتملة" value={completedCount} />
        <StatCard label="الشهادات" value={certificates.length} />
        <StatCard label="كورسات متاحة" value={courses.length} />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Panel title="أكمل ما بدأته" href="/enrollments">
          {activeEnrollments.length === 0 ? (
            <p className="text-sm text-ink-muted">
              لا كورسات جارية.{" "}
              <Link
                href="/courses"
                className="text-primary-ink underline underline-offset-4"
              >
                تصفّح الكورسات
              </Link>
            </p>
          ) : (
            <div className="space-y-3">
              {activeEnrollments.slice(0, 5).map((enr) => (
                <div
                  key={enr.uuid}
                  className="flex items-center justify-between gap-4 rounded-lg border border-line p-3"
                >
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-ink">
                      <Link
                        href={`/enrollments/${enr.course_uuid}`}
                        className="rounded hover:text-primary-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                      >
                        {enr.course_title}
                      </Link>
                    </p>
                    <p className="text-xs text-ink-muted">
                      سُجِّل في {new Date(enr.enrolled_at).toLocaleDateString("ar")}
                    </p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <div
                      className="h-2 w-24 overflow-hidden rounded-full bg-line"
                      role="progressbar"
                      aria-valuenow={enr.progress_pct}
                      aria-valuemin={0}
                      aria-valuemax={100}
                      aria-label="نسبة الإنجاز"
                    >
                      <div
                        className="h-full bg-primary"
                        style={{ width: `${enr.progress_pct}%` }}
                      />
                    </div>
                    <span className="text-xs font-medium text-ink-muted">
                      <bdi>{enr.progress_pct}%</bdi>
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Panel>

        <Panel title="أحدث الشهادات" href="/certificates">
          {certificates.length === 0 ? (
            <p className="text-sm text-ink-muted">
              لم تحصل على شهادة بعد. أكمل كورساً لتحصل على أولى شهاداتك.
            </p>
          ) : (
            <div className="space-y-3">
              {certificates.slice(0, 5).map((cert) => (
                <div key={cert.uuid} className="rounded-lg border border-line p-3">
                  <p className="text-sm font-medium text-ink">{cert.course_title}</p>
                  <p className="text-xs text-ink-muted">
                    <bdi>{cert.certificate_number}</bdi> · صدرت في{" "}
                    {new Date(cert.issued_at).toLocaleDateString("ar")}
                  </p>
                </div>
              ))}
            </div>
          )}
        </Panel>
      </div>
    </div>
  );
}

function Panel({
  title,
  href,
  children,
}: {
  title: string;
  href: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-xl border border-line bg-surface-raised p-6">
      <div className="mb-4 flex items-center justify-between gap-4">
        <h3 className="font-semibold text-ink">{title}</h3>
        <Link
          href={href}
          className="rounded text-sm text-primary-ink underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          عرض الكل
        </Link>
      </div>
      {children}
    </section>
  );
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-line bg-surface-raised p-5">
      <div className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-primary">
        <span className="text-lg font-bold text-white">
          <bdi>{value}</bdi>
        </span>
      </div>
      <p className="text-sm font-medium text-ink-muted">{label}</p>
    </div>
  );
}
