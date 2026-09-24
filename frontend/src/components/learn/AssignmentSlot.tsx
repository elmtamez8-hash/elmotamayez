"use client";

import { useCallback, useEffect, useState } from "react";

import { AssignmentCard } from "@/components/assignments/AssignmentCard";
import { Alert } from "@/components/ui/Alert";
import { assignments, type Assignment } from "@/lib/assignments";
import type { AssignmentReference } from "@/lib/courses";
import { userMessage } from "@/lib/errors";

/**
 * The homework an assignment item places — read, and handed in, in place.
 *
 * ⚠️ READ FROM `/assignments/{uuid}`, NOT FROM THE LESSON PAYLOAD. The item's
 * `reference` is the homework as everybody sees it; this student's own
 * submission, their mark and their extension live on a row only its owner may
 * read, and the same endpoint is the one the hand-in answers to. Copying those
 * onto the lesson payload would be a second read of a private row beside the
 * door that already guards it.
 *
 * ⚠️ AND NO «mark done» BUTTON ANYWHERE NEAR IT. The item completes when the
 * work is handed in (`CompleteAssignmentLessonOnSubmission`); the page's own
 * sentence under this says so.
 */
export function AssignmentSlot({
  reference,
  onSubmitted,
}: {
  reference: AssignmentReference;
  /** Told after a hand-in, so the page can re-read the item's completion. */
  onSubmitted?: () => void;
}) {
  const [homework, setHomework] = useState<Assignment | null>(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    try {
      const result = await assignments.show(reference.uuid);

      setHomework(result.data);
      setError("");
    } catch (err: unknown) {
      // Never swallowed: a blank slot under «واجب» is the silent failure this
      // codebase keeps recording. The reason becomes a sentence.
      setError(userMessage(err));
    }
  }, [reference.uuid]);

  useEffect(() => {
    void load();
  }, [load]);

  if (error !== "") {
    return (
      <Alert tone="danger" title="تعذّر تحميل الواجب">
        {error}
      </Alert>
    );
  }

  if (homework === null) {
    return <p className="text-sm text-ink-muted">جارٍ تحميل الواجب…</p>;
  }

  return (
    <AssignmentCard
      assignment={homework}
      cohortName={null}
      onSubmitted={() => {
        void load();
        onSubmitted?.();
      }}
    />
  );
}
