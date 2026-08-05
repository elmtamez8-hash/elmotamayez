"use client";

import { use, useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { ErrorState } from "@/components/ui/states/ErrorState";

interface AttemptResult {
  status: string;
  score: number;
  max_score: number;
  passed: boolean;
}

export default function ExamResultPage({
  params,
}: {
  params: Promise<{ uuid: string }>;
}) {
  const { uuid } = use(params);
  const [result, setResult] = useState<AttemptResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<AttemptResult>(`/attempts/${uuid}`)
      .then(setResult)
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  useEffect(load, [load]);

  if (loading) return <RowsSkeleton count={2} />;
  if (failed || !result) return <ErrorState onRetry={load} />;

  const percent = result.max_score > 0 ? Math.round((result.score / result.max_score) * 100) : 0;

  return (
    <div className="mx-auto max-w-md space-y-6 text-center">
      <div
        role="status"
        className={`rounded-2xl p-8 text-white ${
          result.passed ? "bg-secondary" : "bg-danger"
        }`}
      >
        <h2 className="mb-2 text-3xl font-bold">
          {result.passed ? "ناجح" : "لم تجتز الاختبار"}
        </h2>
        <p className="text-6xl font-bold">
          <bdi>{percent}%</bdi>
        </p>
        <p className="mt-2 text-sm">
          الدرجة <bdi>{result.score}</bdi> من <bdi>{result.max_score}</bdi>
        </p>
      </div>

      <Button href="/exams" variant="secondary">
        عُد إلى الاختبارات
      </Button>
    </div>
  );
}
