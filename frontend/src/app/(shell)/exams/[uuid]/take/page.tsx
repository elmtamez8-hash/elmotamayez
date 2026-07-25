"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { useRouter } from "next/navigation";
import { use } from "react";

interface AttemptResponse {
  attempt: { uuid: string; status: string };
  questions: Array<{
    id: number;
    type: string;
    content: string;
    points: number;
    options: Array<{ id: number; content: string }>;
  }>;
}

export default function TakeExamPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const router = useRouter();
  const [data, setData] = useState<AttemptResponse | null>(null);
  const [answers, setAnswers] = useState<Record<number, number[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.post<AttemptResponse>(`/exams/${uuid}/attempts`)
      .then((res) => {
        setData(res);
        const initial: Record<number, number[]> = {};
        res.questions.forEach((q) => { initial[q.id] = []; });
        setAnswers(initial);
      })
      .catch((err) => setError(err.message ?? "Failed to start exam"))
      .finally(() => setLoading(false));
  }, [uuid]);

  const toggleOption = (questionId: number, optionId: number) => {
    setAnswers((prev) => {
      const current = prev[questionId] ?? [];
      return { ...prev, [questionId]: current.includes(optionId) ? current.filter((id) => id !== optionId) : [...current, optionId] };
    });
  };

  const handleSubmit = async () => {
    setSubmitting(true);
    setError("");
    try {
      const payload = Object.entries(answers).map(([qId, optionIds]) => ({
        question_id: parseInt(qId),
        selected_option_ids: optionIds,
      }));
      const result = await api.post<{ uuid: string; status: string; score: number; passed: boolean }>(
        `/attempts/${data!.attempt.uuid}/submit`,
        { answers: payload },
      );
      router.push(`/exams/${result.uuid}/result`);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Submission failed";
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return <div className="text-gray-400">Starting exam...</div>;
  if (error && !data) return <div className="text-red-500">{error}</div>;
  if (!data) return null;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h2 className="text-2xl font-bold">Exam in Progress</h2>
        <p className="text-gray-600">Answer all questions and submit when ready.</p>
      </div>

      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="space-y-6">
        {data.questions.map((q, idx) => (
          <div key={q.id} className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <div className="mb-4 flex items-start gap-3">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-medium text-indigo-600">
                {idx + 1}
              </span>
              <div className="flex-1">
                <p className="font-medium">{q.content}</p>
                <p className="mt-1 text-xs text-gray-400">{q.points} point{q.points !== 1 ? "s" : ""}</p>
              </div>
            </div>
            <div className="space-y-2">
              {q.options.map((opt) => {
                const selected = (answers[q.id] ?? []).includes(opt.id);
                return (
                  <button
                    key={opt.id}
                    onClick={() => toggleOption(q.id, opt.id)}
                    className={`flex w-full items-center gap-3 rounded-lg border p-3 text-left text-sm transition ${
                      selected ? "border-indigo-500 bg-indigo-50" : "border-gray-200 hover:border-gray-300"
                    }`}
                  >
                    <div className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border ${
                      selected ? "border-indigo-500 bg-indigo-500" : "border-gray-300"
                    }`}>
                      {selected && (
                        <svg className="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                          <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                      )}
                    </div>
                    {opt.content}
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      <button
        onClick={handleSubmit}
        disabled={submitting}
        className="w-full rounded-lg bg-indigo-600 py-3 font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50"
      >
        {submitting ? "Submitting..." : "Submit Exam"}
      </button>
    </div>
  );
}
