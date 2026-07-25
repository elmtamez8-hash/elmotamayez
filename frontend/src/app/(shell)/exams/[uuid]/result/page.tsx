"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { use } from "react";

export default function ExamResultPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const [result, setResult] = useState<{ status: string; score: number; max_score: number; passed: boolean } | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get<{ status: string; score: number; max_score: number; passed: boolean }>(`/attempts/${uuid}`)
      .then(setResult)
      .finally(() => setLoading(false));
  }, [uuid]);

  if (loading) return <div className="text-gray-400">Loading...</div>;
  if (!result) return <div className="text-red-500">Result not found.</div>;

  return (
    <div className="mx-auto max-w-md space-y-6 text-center">
      <div className={`rounded-xl p-8 text-white ${result.passed ? "bg-gradient-to-br from-green-400 to-emerald-600" : "bg-gradient-to-br from-red-400 to-rose-600"}`}>
        <h2 className="mb-2 text-4xl font-bold">{result.passed ? "Passed!" : "Failed"}</h2>
        <p className="text-6xl font-bold">{Math.round(result.score)}%</p>
        <p className="mt-2 text-sm opacity-90">Score: {result.score} / {result.max_score}</p>
      </div>
      <a href="/exams" className="inline-block rounded-lg bg-indigo-600 px-6 py-2.5 font-medium text-white transition hover:bg-indigo-700">
        Back to exams
      </a>
    </div>
  );
}
