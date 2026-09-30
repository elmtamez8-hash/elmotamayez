"use client";

import { useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { assistants, type AssistantAssignment } from "@/lib/assistants";
import { useAuth } from "@/lib/auth-context";

/**
 * «تعمل على: …» — the courses a CONFINED assistant works on, in this team.
 *
 * ⚠️ `/assistants/me` HAD NO CALLER. The server has answered «where am I an
 * assistant, and on what?» since spec 010, and nothing on the assistant's side
 * asked it — so a confined assistant met their confinement only as 403s on
 * courses they could not tell apart from their own.
 *
 * ⚠️ FILTERED TO THE TEAM ON SCREEN. The endpoint answers across every team the
 * reader assists (`ListOwnAssignments` drops the workspace scope on purpose), and
 * a list of another teacher's courses here would name courses this screen does
 * not show. And `is_confined` is the server's word, never `courses.length`: an
 * empty list means every course (see `lib/assistants.ts`).
 *
 * A failed read renders nothing: this is a hint beside a list that works without
 * it, and an error box over a working list would read as the list failing.
 */
export function AssistantScopeNotice() {
  const { user } = useAuth();
  const workspace = user?.current_workspace?.uuid ?? null;
  const [assignment, setAssignment] = useState<AssistantAssignment | null>(null);

  useEffect(() => {
    if (workspace === null) return;

    let live = true;

    assistants
      .mine()
      .then((res) => {
        if (!live) return;
        setAssignment(
          (res.data ?? []).find((row) => row.workspace?.uuid === workspace && row.is_confined) ?? null,
        );
      })
      .catch(() => {
        if (live) setAssignment(null);
      });

    return () => {
      live = false;
    };
  }, [workspace]);

  if (assignment === null) return null;

  const titles = assignment.courses.map((course) => course.title);

  return (
    <Alert tone="info" title="تعمل مساعداً على كورسات محدّدة">
      {titles.length > 0
        ? `تعمل على: ${titles.join(" · ")}. لا يمكنك تعديل غيرها في هذا الفريق.`
        : "حُذفت الكورسات التي حُدّدت لك. اطلب من المدرّس أن يحدّد لك كورسات أخرى."}
    </Alert>
  );
}
