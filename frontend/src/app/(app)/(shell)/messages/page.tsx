"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Card } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { conversations, type Conversation } from "@/lib/conversations";
import { userMessage } from "@/lib/errors";
import { formatDateTime } from "@/lib/labels";

/**
 * Every thread this person is in (spec 010 · US2).
 *
 * ⚠️ NO «NEW CONVERSATION» BUTTON, AND THE ABSENCE IS THE DESIGN. A private
 * conversation is opened from the teacher's own page — the one place the student
 * has already chosen who they mean — and there is exactly one per teacher. A
 * picker here would be a second directory of teachers, and it would have to
 * answer «which of these may I write to» in a second voice.
 */
export default function MessagesPage() {
  const [rows, setRows] = useState<Conversation[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [problem, setProblem] = useState<string | null>(null);

  const load = useCallback(() => {
    setState("loading");
    setProblem(null);

    conversations
      .list()
      .then((response) => {
        setRows(response.data ?? []);
        setState("ready");
      })
      .catch((error: unknown) => {
        // ⚠️ Never `.catch(() => undefined)`: a swallowed reason is a permanently
        // blank screen with the answer sitting unread in the response.
        setProblem(userMessage(error));
        setState("error");
      });
  }, []);

  useEffect(load, [load]);

  if (state === "loading") return <RowsSkeleton count={4} />;
  if (state === "error") return <ErrorState onRetry={load} description={problem ?? undefined} />;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">الرسائل</h1>
        <p className="text-sm text-ink-muted">
          محادثاتك الخاصّة مع مدرّسيك ومن يعاونهم. تبقى المحادثة مقروءة بعد انتهاء دراستك،
          ويتوقّف الإرسال فيها.
        </p>
      </header>

      {rows.length === 0 ? (
        <Alert tone="info" title="لا محادثات بعد">
          تُفتح المحادثة من صفحة المدرّس. ولك محادثة واحدة مع كلّ مدرّس، تجمع كلّ ما دار
          بينكما.
        </Alert>
      ) : (
        <ul className="space-y-3">
          {rows.map((row) => (
            <li key={row.uuid}>
              <Link href={`/messages/${row.uuid}`} className="block">
                <Card>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h2 className="font-medium text-ink">{row.student_name ?? "محادثة"}</h2>
                      <p className="truncate text-sm text-ink-muted">
                        {row.last_message === null
                          ? "لا رسائل بعد"
                          : `${row.last_message.sender_name ?? "—"}: ${row.last_message.body}`}
                      </p>
                    </div>
                    <span className="shrink-0 text-xs text-ink-muted">
                      {formatDateTime(row.last_message?.created_at ?? row.updated_at)}
                    </span>
                  </div>
                </Card>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
