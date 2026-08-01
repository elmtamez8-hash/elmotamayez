"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Enrollment } from "@/lib/types";

interface EnrollmentWithCourse extends Enrollment {
  course?: { title: string; uuid: string };
}

export default function EnrollmentsPage() {
  const [enrollments, setEnrollments] = useState<EnrollmentWithCourse[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<{ data: EnrollmentWithCourse[] }>("/enrollments")
      .then((res) => setEnrollments(res.data ?? []))
      .catch((err: unknown) => setError(errorMessage(err, "Could not load your enrollments")))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">My Learning</h2>

      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      {enrollments.length === 0 ? (
        <div className="rounded-xl bg-white p-12 text-center shadow-sm ring-1 ring-gray-200">
          <p className="text-gray-500">You haven&apos;t enrolled in any courses yet.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {enrollments.map((enr) => (
            <div key={enr.uuid} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="font-semibold">{enr.course?.title ?? `Course #${enr.course_id}`}</h3>
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${
                  enr.status === "active" ? "bg-green-100 text-green-700" :
                  enr.status === "completed" ? "bg-blue-100 text-blue-700" :
                  "bg-gray-100 text-gray-600"
                }`}>
                  {enr.status}
                </span>
              </div>
              <div className="mb-3">
                <div className="mb-1 flex justify-between text-xs text-gray-500">
                  <span>Progress</span>
                  <span>{enr.progress_pct}%</span>
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-gray-200">
                  <div className={`h-full ${enr.status === "completed" ? "bg-green-500" : "bg-indigo-500"}`} style={{ width: `${enr.progress_pct}%` }} />
                </div>
              </div>
              <p className="text-xs text-gray-500">Enrolled {new Date(enr.enrolled_at).toLocaleDateString()}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
