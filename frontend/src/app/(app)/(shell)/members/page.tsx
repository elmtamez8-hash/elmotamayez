"use client";

import { useCallback, useEffect, useState } from "react";
import { api, fieldErrors } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { roleLabel } from "@/lib/labels";
import { P, can } from "@/lib/permissions";
import { useAuth } from "@/lib/auth-context";
import type { Workspace } from "@/lib/types";
import { Card } from "@/components/ui/Card";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { TextField, SelectField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import { CloseIcon } from "@/components/icons";

interface Member {
  uuid: string;
  name: string;
  email: string;
  role: string;
  role_label: string | null;
  is_owner: boolean;
}

const ROLE_OPTIONS = [
  { value: "teacher", label: "مدرّس" },
  { value: "assistant-teacher", label: "مدرّس مساعد" },
  { value: "student", label: "طالب" },
];

/** The workspace the API is acting in, falling back to the only membership. */
function pickCurrent(workspaces: Workspace[] | undefined): Workspace | undefined {
  return workspaces?.find((w) => w.is_current) ?? workspaces?.[0];
}

export default function MembersPage() {
  const { user } = useAuth();
  const [members, setMembers] = useState<Member[]>([]);
  const [workspaceUuid, setWorkspaceUuid] = useState<string | null>(null);
  const [savingUuid, setSavingUuid] = useState<string | null>(null);
  const [roleError, setRoleError] = useState("");
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const [email, setEmail] = useState("");
  const [role, setRole] = useState("student");
  const [inviting, setInviting] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const [invite, setInvite] = useState<{ email: string; link: string } | null>(null);
  const [copied, setCopied] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Workspace[] }>("/workspaces")
      .then(async (res) => {
        const current = pickCurrent(res.data);
        if (!current) return setMembers([]);

        setWorkspaceUuid(current.uuid);

        const detail = await api.get<{ data: Member[] }>(
          `/workspaces/${current.uuid}/members`,
        );
        setMembers(detail.data ?? []);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const submitInvite = async (e: React.FormEvent) => {
    e.preventDefault();
    setInviting(true);
    setError("");
    setFields({});

    try {
      const ws = await api.get<{ data: Workspace[] }>("/workspaces");
      const current = pickCurrent(ws.data);
      if (!current) throw new Error("no workspace");

      const { token } = await api.post<{ token: string }>(
        `/workspaces/${current.uuid}/invitations`,
        { email, role },
      );

      // No mail is sent yet — hand the inviter the link to pass on themselves.
      setInvite({ email, link: `${window.location.origin}/invitations/${token}` });
      setEmail("");
      load();
    } catch (err: unknown) {
      const found = fieldErrors(err);
      if (Object.keys(found).length > 0) setFields(found);
      else setError(userMessage(err));
    } finally {
      setInviting(false);
    }
  };

  /*
   * ⛔ THE CONTROL `members.update` WAS NAMED AFTER AND NEVER BUILT.
   *
   * Until now the only way to change somebody's role was to remove them and
   * invite them again — they lose their membership record and get a fresh
   * invitation in their inbox for a job they already hold. The permission was on
   * the roles screen the whole time, reading as a capability the product had.
   *
   * ⚠️ The list is reloaded from the server rather than patched in place: the
   * role lives in two tables on the other side (the membership row and spatie's
   * team-scoped roles) and the server is the only thing that has seen both.
   */
  const changeRole = async (member: Member, next: string) => {
    if (!workspaceUuid || next === member.role) return;

    setSavingUuid(member.uuid);
    setRoleError("");

    try {
      await api.patch(`/workspaces/${workspaceUuid}/members/${member.uuid}`, { role: next });
      load();
    } catch (err: unknown) {
      // The owner's row and an expired two-factor grace both land here, each
      // with its own sentence from the server. Never raw.
      setRoleError(userMessage(err));
    } finally {
      setSavingUuid(null);
    }
  };

  const canChangeRoles = can(user, P.membersUpdate);

  const columns: Column<Member>[] = [
    {
      key: "name",
      header: "الاسم",
      render: (m) => <span className="font-medium">{m.name}</span>,
    },
    {
      key: "email",
      header: "البريد الإلكتروني",
      render: (m) => (
        <span className="text-ink-muted">
          <bdi>{m.email}</bdi>
        </span>
      ),
    },
    {
      key: "role",
      header: "الدور",
      /*
       * ⚠️ THE OWNER KEEPS THE BADGE, AND THE SERVER IS WHY. Their role may not
       * change — nothing writes `owner_user_id`, so demoting them would leave the
       * person who owns the place holding a student's permissions inside it. The
       * answer comes from the payload (`is_owner`) rather than being re-derived
       * here, so the control is offered exactly where the server would say yes.
       */
      render: (m) =>
        canChangeRoles && !m.is_owner ? (
          <SelectField
            id={`role_${m.uuid}`}
            // Named after the person, not after the column: a screen reader
            // meets this control with no header row for context, and "الدور"
            // repeated down a table says which field but never whose.
            label={`دور ${m.name}`}
            labelHidden
            value={m.role}
            onChange={(next) => void changeRole(m, next)}
            options={ROLE_OPTIONS}
            disabled={savingUuid === m.uuid}
          />
        ) : (
          <Badge tone="info">{roleLabel(m.role, m.role_label)}</Badge>
        ),
    },
  ];

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold text-ink">فريقك</h2>

      <Card as="section" padding="sm">
        <form onSubmit={submitInvite} className="flex flex-wrap items-end gap-3">
          {error && (
            <div className="w-full">
              <Alert tone="danger" title={error} />
            </div>
          )}

          <div className="min-w-56 flex-1">
            <TextField
              id="invite_email"
              label="البريد الإلكتروني"
              type="email"
              value={email}
              onChange={setEmail}
              error={fields.email}
              placeholder="member@example.com"
              required
            />
          </div>

          <div className="min-w-40">
            <SelectField
              id="invite_role"
              label="الدور"
              value={role}
              onChange={setRole}
              options={ROLE_OPTIONS}
              error={fields.role}
            />
          </div>

          <Button type="submit" loading={inviting} loadingLabel="جارٍ الإرسال…">
            أرسل الدعوة
          </Button>
        </form>
      </Card>

      {invite && (
        <Card as="section" padding="sm">
          <div className="mb-3 flex items-start justify-between gap-4">
            <div>
              <p className="font-medium text-ink">
                الدعوة جاهزة لـ <bdi>{invite.email}</bdi>
              </p>
              <p className="text-sm text-ink-muted">
                أرسل له هذا الرابط — تنتهي صلاحيته بعد سبعة أيام ولا يعمل إلا لهذا البريد.
              </p>
            </div>
            <button
              type="button"
              onClick={() => setInvite(null)}
              aria-label="إغلاق"
              className="rounded p-1 text-ink-muted transition hover:text-ink focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              <CloseIcon className="h-4 w-4" />
            </button>
          </div>

          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 overflow-x-auto rounded-lg bg-surface px-3 py-2 text-xs text-ink">
              <bdi>{invite.link}</bdi>
            </code>
            <Button
              size="sm"
              variant="secondary"
              onClick={() => {
                navigator.clipboard.writeText(invite.link);
                setCopied(true);
                setTimeout(() => setCopied(false), 2000);
              }}
            >
              {copied ? "نُسِخ" : "انسخ"}
            </Button>
          </div>
        </Card>
      )}

      {roleError !== "" && <Alert tone="danger" title={roleError} />}

      <Table
        columns={columns}
        rows={members}
        rowKey={(m) => m.uuid}
        caption="فريقك وأدوارهم"
        state={loading ? "loading" : failed ? "error" : "ready"}
        onRetry={load}
        emptyTitle="لا أحد في فريقك غيرك"
        emptyDescription="ادعُ مدرّساً أو مساعداً أو طالباً من النموذج أعلاه."
      />
    </div>
  );
}
