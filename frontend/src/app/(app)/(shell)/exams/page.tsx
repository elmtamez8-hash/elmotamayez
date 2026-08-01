"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Exam } from "@/lib/types";
import Link from "next/link";

export default function ExamsPage() {
  const [exams, setExams] = useState<Exam[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<{ data: Exam[] }>("/exams")
      .then((res) => setExams(res.data ?? []))
      .catch((err: unknown) => setError(errorMessage(err, "Could not load exams")))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Exams</h2>
        <Link href="/exams/new" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
          + New Exam
        </Link>
      </div>

      {exams.length === 0 ? (
        <div className="rounded-xl bg-white p-12 text-center shadow-sm ring-1 ring-gray-200">
          <p className="text-gray-500">No exams available.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
          {exams.map((exam) => (
            <div key={exam.uuid} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-200">
              <h3 className="mb-2 font-semibold">{exam.title}</h3>
              <p className="mb-4 line-clamp-2 text-sm text-gray-500">{exam.description}</p>
              <div className="mb-4 flex flex-wrap gap-2 text-xs">
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{exam.duration_minutes} min</span>
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">Pass: {exam.passing_score}%</span>
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">Max: {exam.max_attempts} attempts</span>
                {exam.questions_count !== undefined && (
                  <span className="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600">{exam.questions_count} questions</span>
                )}
              </div>
              {exam.is_published ? (
                <div className="flex gap-2">
                  <Link
                    href={`/exams/${exam.uuid}/take`}
                    className="flex-1 rounded-lg bg-indigo-600 py-2 text-center text-sm font-medium text-white transition hover:bg-indigo-700"
                  >
                    Start exam
                  </Link>
                  <Link
                    href={`/exams/${exam.uuid}/manage`}
                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50"
                  >
                    Manage
                  </Link>
                </div>
              ) : (
                <Link
                  href={`/exams/${exam.uuid}/manage`}
                  className="block rounded-lg border border-gray-300 py-2 text-center text-sm font-medium text-gray-600 transition hover:bg-gray-50"
                >
                  Manage exam
                </Link>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
