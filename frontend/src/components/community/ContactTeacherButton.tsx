"use client";

import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";

import { MessagesIcon } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Field";
import { teachesOnPlatform, useAuth } from "@/lib/auth-context";
import {
  contactTarget,
  conversations,
  type ContactOption,
  type ContactOptions,
} from "@/lib/conversations";
import { userMessage } from "@/lib/errors";

/**
 * «تواصل مع المدرّس» — on the public course page and the public teacher page
 * (owner decision 2026-09-28).
 *
 * - A VISITOR is sent to sign in, and `?next=` brings them back to this page
 *   (the `safeNext()` mechanism the subscribe button already uses).
 * - A TEACHER, an assistant or the teacher themself sees nothing: staff write to
 *   students from «راسِل», never from a public page.
 * - A STUDENT or a GUARDIAN reads `GET /conversations/contact-options`, which is
 *   derived from the conversation door's own predicate: the thread if it exists,
 *   the compose view if a first message may be sent, and otherwise a calm
 *   sentence — the teacher does not take new messages now, the cap is reached —
 *   in place of the button rather than an error after pressing it.
 * - A guardian of several children picks which child they are writing AS; one
 *   with no linked child is told why they cannot write at all.
 *
 * ⚠️ NOTHING HERE DECIDES WHO MAY WRITE. The button reads the server's answer
 * and the send is refused at the door regardless; what this avoids is offering a
 * control that answers «ممنوع» the moment it is pressed.
 */
export function ContactTeacherButton({
  workspaceUuid,
  contactName,
}: {
  workspaceUuid: string;
  /** The course's teacher with the academy in brackets — built on the server. */
  contactName: string;
}) {
  const { user, loading } = useAuth();
  const pathname = usePathname();

  const [state, setState] = useState<"idle" | "loading" | "ready" | "error">("idle");
  const [data, setData] = useState<ContactOptions | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [chosen, setChosen] = useState<string>("");

  const asks = user !== null && !teachesOnPlatform(user);

  useEffect(() => {
    if (!asks) return;

    let cancelled = false;
    setState("loading");

    conversations
      .contactOptions(workspaceUuid)
      .then((response) => {
        if (cancelled) return;
        setData(response.data ?? null);
        setState("ready");
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        setProblem(userMessage(error));
        setState("error");
      });

    return () => {
      cancelled = true;
    };
  }, [asks, workspaceUuid]);

  // Nothing is drawn while the session is being restored: a guest's link that
  // turns into a student's button a moment later is a flicker nobody needs.
  if (loading) return null;

  if (user === null) {
    return (
      <Button
        href={`/login?next=${encodeURIComponent(pathname ?? "/")}`}
        variant="secondary"
        iconStart={<MessagesIcon className="h-4 w-4" />}
      >
        سجّل الدخول لتتواصل مع {contactName}
      </Button>
    );
  }

  if (teachesOnPlatform(user)) return null;

  if (state === "loading" || state === "idle") return null;

  if (state === "error") {
    return (
      <p role="alert" className="text-sm text-danger-ink">
        {problem}
      </p>
    );
  }

  const options = data?.options ?? [];

  if (options.length === 0) {
    return <p className="text-sm text-ink-muted">{data?.note ?? "لا يمكن مراسلة هذا المدرّس الآن."}</p>;
  }

  if (options.length === 1) {
    return <ContactAction option={options[0]} workspaceUuid={workspaceUuid} contactName={contactName} />;
  }

  const picked = options.find((option) => option.student_uuid === chosen) ?? null;

  return (
    <div className="space-y-3">
      <SelectField
        id="contact-child"
        label="تكتب باسم أيّ ابن؟"
        value={chosen}
        onChange={setChosen}
        placeholder="اختر الابن"
        options={options.map((option) => ({
          value: option.student_uuid,
          label: option.student_name ?? "حسابي",
        }))}
      />
      {picked !== null && (
        <ContactAction option={picked} workspaceUuid={workspaceUuid} contactName={contactName} />
      )}
    </div>
  );
}

function ContactAction({
  option,
  workspaceUuid,
  contactName,
}: {
  option: ContactOption;
  workspaceUuid: string;
  contactName: string;
}) {
  const target = contactTarget(option, workspaceUuid, contactName);

  if ("reason" in target) {
    return <p className="text-sm text-ink-muted">{target.reason}</p>;
  }

  return (
    <Button href={target.href} variant="secondary" iconStart={<MessagesIcon className="h-4 w-4" />}>
      تواصل مع {contactName}
    </Button>
  );
}
