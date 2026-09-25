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
import { Modal } from "@/components/ui/Modal";
import { TextField, SelectField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import { PageHeader } from "@/components/ui/PageHeader";
import { CloseIcon, MembersIcon } from "@/components/icons";

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

  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  /*
   * ⚠️ THE LIST IS PAGED, AND «عرض المزيد» IS NOT DECORATION. The server answers
   * fifty members a page, because the workspace's members include every student
   * the teacher ever added. Without the button the list would stop at fifty
   * without saying so — a screen that lies rather than one that is short.
   * Page one REPLACES the list (every reload after a change starts there);
   * a later page is appended, so the rows being read are never swapped out.
   */
  const load = useCallback((target: number = 1) => {
    setLoading(true);
    setFailed(false);

    api
      .get<{ data: Workspace[] }>("/workspaces")
      .then(async (res) => {
        const current = pickCurrent(res.data);
        if (!current) return setMembers([]);

        setWorkspaceUuid(current.uuid);

        const detail = await api.get<{ data: Member[]; meta?: { last_page: number } }>(
          `/workspaces/${current.uuid}/members?page=${target}`,
        );
        const rows = detail.data ?? [];
        setMembers((before) => (target === 1 ? rows : [...before, ...rows]));
        setLastPage(detail.meta?.last_page ?? 1);
        setPage(target);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(1), [load]);

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

  /*
   * ⛔ `DELETE …/members/{member}` EXISTED AND NO SCREEN CALLED IT. Taking
   * somebody off the team meant asking the platform to do it by hand.
   *
   * ⚠️ A WINDOW, NOT A TWO-PRESS ARM: nothing else is happening on this screen,
   * and removal takes away everything the person could do here at once — the
   * `Modal` case, not the live-lesson `ConfirmButton` case. Offered on
   * `members.remove`, which is exactly what the door asks now, and never on the
   * owner's row (`is_owner`, from the payload): the server answers 422 there and
   * that refusal stays the guard, the button's absence only spares the question.
   */
  const canRemove = can(user, P.membersRemove);
  const [removing, setRemoving] = useState<Member | null>(null);
  const [removeBusy, setRemoveBusy] = useState(false);
  const [removeError, setRemoveError] = useState("");

  const confirmRemove = async () => {
    if (!workspaceUuid || removing === null) return;

    setRemoveBusy(true);
    setRemoveError("");

    try {
      await api.delete(`/workspaces/${workspaceUuid}/members/${removing.uuid}`);
      setRemoving(null);
      load();
    } catch (err: unknown) {
      setRemoving(null);
      setRemoveError(userMessage(err));
    } finally {
      setRemoveBusy(false);
    }
  };

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
    ...(canRemove
      ? [
          {
            key: "remove",
            header: "إجراء",
            render: (m: Member) =>
              m.is_owner ? null : (
                <Button size="sm" variant="ghost" onClick={() => setRemoving(m)}>
                  إزالة
                  {" "}<span className="sr-only">{m.name}</span>
                </Button>
              ),
          },
        ]
      : []),
  ];

  return (
    <div className="space-y-6">
      <PageHeader Icon={MembersIcon} title="فريقك" />

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
      {removeError !== "" && <Alert tone="danger" title={removeError} />}

      <Modal
        open={removing !== null}
        title={removing === null ? "" : `إزالة ${removing.name} من فريقك؟`}
        message="يفقد فوراً كلَّ ما كان يستطيع فعله هنا. ولإعادته تُرسَل له دعوةٌ جديدة."
        confirmLabel="أزِله"
        tone="danger"
        busy={removeBusy}
        onConfirm={() => void confirmRemove()}
        onCancel={() => setRemoving(null)}
      />

      <Table
        columns={columns}
        rows={members}
        rowKey={(m) => m.uuid}
        caption="فريقك وأدوارهم"
        state={loading ? "loading" : failed ? "error" : "ready"}
        onRetry={() => load(1)}
        emptyTitle="لا أحد في فريقك غيرك"
        emptyDescription="ادعُ مدرّساً أو مساعداً أو طالباً من النموذج أعلاه."
      />

      {page < lastPage && (
        <div className="flex justify-center">
          <Button
            variant="ghost"
            loading={loading}
            loadingLabel="جارٍ التحميل…"
            onClick={() => load(page + 1)}
          >
            عرض المزيد
          </Button>
        </div>
      )}
    </div>
  );
}
