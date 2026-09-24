"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { useAuth } from "@/lib/auth-context";
import { conversations } from "@/lib/conversations";
import { userMessage } from "@/lib/errors";
import { P, can } from "@/lib/permissions";

/**
 * «راسِل» — opens (or reopens) the private thread with one student and takes the
 * teacher into it.
 *
 * ⛔ `POST /conversations` HAS TAKEN A `student` SINCE IT SHIPPED, AND NOTHING ON A
 * TEACHER'S SCREEN EVER SENT IT. The only caller was the student's own
 * enrolments page, so a teacher could answer a thread and never begin one — the
 * feature existed on one side of the relationship only.
 *
 * ⚠️ OFFERED ON `chat.reply`, THE SAME NAME THE DOOR ASKS. `ConversationPolicy`
 * lets the teacher's side in on membership AND that permission, so an assistant
 * without it would press a button that answers 403; a student holds no
 * permissions and never sees it. Whether THIS student is theirs is the server's
 * question (an active enrolment in the workspace), and its refusal is shown here
 * as a sentence rather than the button pretending to know.
 */
export function MessageStudentButton({
  studentUuid,
  studentName,
}: {
  studentUuid: string;
  /** Read by a screen reader only: a column of «راسِل» says what, never whom. */
  studentName: string;
}) {
  const router = useRouter();
  const { user } = useAuth();
  const [opening, setOpening] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  if (!can(user, P.chatReply)) return null;

  const open = async () => {
    setOpening(true);
    setProblem(null);

    try {
      const thread = await conversations.openWithStudent(studentUuid);
      router.push(`/messages/${thread.uuid}`);
    } catch (error: unknown) {
      setProblem(userMessage(error));
      setOpening(false);
    }
  };

  return (
    <span className="inline-flex flex-col items-start gap-1">
      <Button size="sm" variant="secondary" loading={opening} loadingLabel="جارٍ الفتح…" onClick={() => void open()}>
        راسِل
        {" "}<span className="sr-only">{studentName}</span>
      </Button>
      {problem !== null && (
        <span role="alert" className="text-xs text-danger-ink">
          {problem}
        </span>
      )}
    </span>
  );
}
