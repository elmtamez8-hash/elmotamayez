"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextareaField } from "@/components/ui/Field";
import { fieldErrors } from "@/lib/api";
import { conversations } from "@/lib/conversations";
import { userMessage } from "@/lib/errors";
import { counted } from "@/lib/labels";

/**
 * A first message to a teacher's side — or, from «راسِل», to a student
 * (owner decision 2026-09-28).
 *
 * ⛔ THE CONVERSATION DOES NOT EXIST UNTIL «إرسال». Pressing «راسل» used to insert
 * the thread at once, so an empty conversation stood in both sides' lists before
 * anybody had typed a word. Every entry point now lands here instead, and
 * `POST /conversations` creates the thread together with this message — or, if
 * one already exists, writes into it — then this view steps aside for the
 * thread itself.
 *
 * The query carries only identifiers and a display name: who may write is the
 * server's question, asked on the send, and its refusal is shown as a sentence.
 */
export default function ComposePage() {
  return (
    <Suspense fallback={null}>
      <Compose />
    </Suspense>
  );
}

function Compose() {
  const router = useRouter();
  const params = useSearchParams();

  const workspace = params.get("workspace");
  const student = params.get("student");
  const name = params.get("name");
  const remainingRaw = params.get("remaining");
  const remaining = remainingRaw === null ? null : Number.parseInt(remainingRaw, 10);

  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [bodyError, setBodyError] = useState<string | undefined>(undefined);

  if (workspace === null || workspace === "") {
    return (
      <div className="grid flex-1 place-items-center p-8 text-center">
        <p className="text-sm text-ink-muted">لم نعرف لمن هذه الرسالة. ارجع واضغط زرّ المراسلة من جديد.</p>
      </div>
    );
  }

  const send = async (event: React.FormEvent) => {
    event.preventDefault();

    if (body.trim() === "") {
      setBodyError("اكتب رسالتك أولاً.");

      return;
    }

    setSending(true);
    setProblem(null);
    setBodyError(undefined);

    try {
      const thread = await conversations.start(workspace, body.trim(), student);

      // The sidebar was fetched before this thread existed.
      window.dispatchEvent(new Event("conversations:changed"));
      router.replace(`/messages/${thread.uuid}`);
    } catch (error: unknown) {
      const fields = fieldErrors(error);

      if (fields.body !== undefined) setBodyError(fields.body);
      else setProblem(userMessage(error));

      setSending(false);
    }
  };

  return (
    <form onSubmit={(event) => void send(event)} className="flex flex-1 flex-col gap-4 p-4 md:p-6">
      <header className="space-y-1">
        <h1 className="text-lg font-semibold text-ink">
          {name !== null && name !== "" ? `رسالة إلى ${name}` : "رسالة جديدة"}
        </h1>
        <p className="text-sm text-ink-muted">تبدأ المحادثة حين ترسل رسالتك الأولى.</p>
      </header>

      {remaining !== null && Number.isFinite(remaining) && (
        <Alert tone="info" title="قبل ردّ المدرّس">
          {`يمكنك إرسال ${counted(remaining, {
            one: "رسالة واحدة",
            two: "رسالتين",
            few: "رسائل",
            many: "رسالة",
            other: "رسالة",
          })} قبل أن يردّ المدرّس، ثمّ تتابع بلا حدّ بعد ردّه.`}
        </Alert>
      )}

      {problem !== null && (
        <Alert tone="danger" title="لم تُرسَل الرسالة">
          {problem}
        </Alert>
      )}

      <TextareaField
        id="first-message"
        label="رسالتك"
        value={body}
        onChange={setBody}
        rows={6}
        placeholder="اكتب سؤالك أو ما تريد قوله…"
        error={bodyError}
        disabled={sending}
      />

      <div>
        <Button type="submit" loading={sending} loadingLabel="جارٍ الإرسال…">
          إرسال
        </Button>
      </div>
    </form>
  );
}
