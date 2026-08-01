"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Course, Enrollment, Certificate } from "@/lib/types";
import { useAuth } from "@/lib/auth-context";
import Link from "next/link";

export default function DashboardPage() {
  const { user } = useAuth();
  const [enrollments, setEnrollments] = useState<Enrollment[]>([]);
  const [certificates, setCertificates] = useState<Certificate[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api.get<{ data: Enrollment[] }>("/enrollments").catch(() => ({ data: [] })),
      api.get<{ data: Certificate[] }>("/certificates").catch(() => ({ data: [] })),
      api.get<{ data: Course[] }>("/courses").catch(() => ({ data: [] })),
    ]).then(([enr, cert, crs]) => {
      setEnrollments(enr.data ?? []);
      setCertificates(cert.data ?? []);
      setCourses(crs.data ?? []);
      setLoading(false);
    });
  }, []);

  if (loading) return <div className="text-gray-400">Loading...</div>;

  const activeEnrollments = enrollments.filter((e) => e.status === "active");
  const completedCount = enrollments.filter((e) => e.status === "completed").length;

  return (
    <div className="space-y-8">
      <div>
        <h2 className="text-2xl font-bold">Welcome back, {user?.first_name}!</h2>
        <p className="text-gray-600">Here&apos;s an overview of your learning progress.</p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Active Courses" value={activeEnrollments.length} color="bg-indigo-500" />
        <StatCard label="Completed" value={completedCount} color="bg-green-500" />
        <StatCard label="Certificates" value={certificates.length} color="bg-amber-500" />
        <StatCard label="Available Courses" value={courses.length} color="bg-blue-500" />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
          <div className="mb-4 flex items-center justify-between">
            <h3 className="font-semibold">Continue Learning</h3>
            <Link href="/enrollments" className="text-sm text-indigo-600 hover:underline">View all</Link>
          </div>
          {activeEnrollments.length === 0 ? (
            <p className="text-sm text-gray-500">No active enrollments. <Link href="/manage/courses" className="text-indigo-600 hover:underline">Browse courses</Link></p>
          ) : (
            <div className="space-y-3">
              {activeEnrollments.slice(0, 5).map((enr) => (
                <div key={enr.uuid} className="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                  <div>
                    <p className="text-sm font-medium">Course #{enr.course_id}</p>
                    <p className="text-xs text-gray-500">Enrolled {new Date(enr.enrolled_at).toLocaleDateString()}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="h-2 w-24 overflow-hidden rounded-full bg-gray-200">
                      <div className="h-full bg-indigo-500" style={{ width: `${enr.progress_pct}%` }} />
                    </div>
                    <span className="text-xs font-medium text-gray-600">{enr.progress_pct}%</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
          <div className="mb-4 flex items-center justify-between">
            <h3 className="font-semibold">Recent Certificates</h3>
            <Link href="/certificates" className="text-sm text-indigo-600 hover:underline">View all</Link>
          </div>
          {certificates.length === 0 ? (
            <p className="text-sm text-gray-500">No certificates yet. Complete a course to earn one!</p>
          ) : (
            <div className="space-y-3">
              {certificates.slice(0, 5).map((cert) => (
                <div key={cert.uuid} className="rounded-lg border border-gray-100 p-3">
                  <p className="text-sm font-medium">{cert.course_title}</p>
                  <p className="text-xs text-gray-500">{cert.certificate_number} · Issued {new Date(cert.issued_at).toLocaleDateString()}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function StatCard({ label, value, color }: { label: string; value: number; color: string }) {
  return (
    <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
      <div className={`mb-3 inline-flex h-10 w-10 items-center justify-center rounded-lg ${color}`}>
        <span className="text-lg font-bold text-white">{value}</span>
      </div>
      <p className="text-sm font-medium text-gray-600">{label}</p>
    </div>
  );
}
