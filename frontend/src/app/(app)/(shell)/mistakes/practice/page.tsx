"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { PracticeRunner } from "@/components/practice/PracticeRunner";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { mistakes, type MistakeFilters } from "@/lib/mistakes";
import type { PracticePaper } from "@/lib/practice";

/**
 * A revision paper built from what the student still gets wrong.
 *
 * ⚠️ THE PAPER IS BUILT ON MOUNT AND HELD IN MEMORY, deliberately. A practice
 * attempt is not something anybody returns to: reloading builds a fresh one from
 * whatever is still standing, which is the right answer to "what should I revise
 * now" rather than a replay of an hour-old list.
 *
 * ⚠️ IT READS THE NOTEBOOK'S FILTER OUT OF THE URL, AND THE TEACHER IS THE HALF
 * IT CANNOT DO WITHOUT. A paper belongs to one teacher — one bank, one
 * withholding rule — while the notebook now spans every teacher the reader
 * studies with, so the server refuses to guess when several are possible. The
 * notebook's own button passes what the reader was looking at.
 *
 * ⚠️ READ FROM `location` IN AN EFFECT, NOT WITH `useSearchParams`. That hook
 * opts the page out of static prerendering unless it sits inside a `<Suspense>`
 * boundary — the same reason the course page's tab parameter is read this way.
 *
 * Sitting it is {@link PracticeRunner}'s job — the same component the
 * self-generated paper uses, because they are the same paper from two doors.
 */
export default function MistakePracticePage() {
  const [paper, setPaper] = useState<PracticePaper | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [round, setRound] = useState(0);

  useEffect(() => {
    setLoading(true);
    setError("");

    const params = new URLSearchParams(window.location.search);
    const filters: MistakeFilters = {};

    for (const key of ["teacher", "course", "exam", "concept", "lesson"] as const) {
      const value = params.get(key);

      if (value !== null && value !== "") filters[key] = value;
    }

    mistakes
      .practice(filters)
      .then((response) => setPaper(response.data))
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, [round]);

  if (loading) return <RowsSkeleton count={4} />;

  if (paper === null) {
    return (
      <div className="mx-auto max-w-2xl">
        <Alert tone="danger" title={error === "" ? "تعذّر بناء الورقة." : error}>
          <Button href="/mistakes" variant="secondary" size="sm">
            عُد إلى دفتر الأخطاء
          </Button>
        </Alert>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-ink">اختبرني في أخطائي</h1>
        <p className="text-sm text-ink-muted">
          أسئلة أخطأت فيها ولم تُصلحها بعد. لا تُحتسب هذه الورقة في درجاتك.
        </p>
      </div>

      {/* A new round rebuilds from what is STILL standing — the questions
          answered correctly a moment ago are gone from it by construction. */}
      <PracticeRunner paper={paper} onRestart={() => setRound((value) => value + 1)} />
    </div>
  );
}
