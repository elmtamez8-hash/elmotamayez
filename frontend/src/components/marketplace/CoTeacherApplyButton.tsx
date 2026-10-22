"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { api } from "@/lib/api";
import { userMessage } from "@/lib/errors";

/**
 * An invited co-teacher's door to a profile of their own (co-teacher journey
 * 2026-10-22). `POST /teacher/application` starts — or returns — a draft in the
 * current workspace at step two, and the signup wizard resumes it from there.
 */
export function CoTeacherApplyButton() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const start = async () => {
    setBusy(true);
    setError("");
    try {
      await api.post("/teacher/application");
      router.push("/signup/teacher");
    } catch (err: unknown) {
      setError(userMessage(err));
      setBusy(false);
    }
  };

  return (
    <div className="space-y-2">
      <Button onClick={start} loading={busy} loadingLabel="جارٍ الفتح…">
        قدّم على ملفّ تدريسي
      </Button>
      {error && <p className="text-sm text-danger-ink">{error}</p>}
    </div>
  );
}
