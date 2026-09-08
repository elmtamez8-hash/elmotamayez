"use client";

import { useState } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { CheckboxField } from "@/components/ui/Field";
import { GUARDIAN_PERMISSIONS, type GuardianRelation } from "@/lib/notifications";

/**
 * One guardian link, drawn from whichever side is reading it.
 *
 * ⚠️ THE SIDE COMES FROM THE SERVER (`viewer_side`), NEVER FROM COMPARING UUIDS
 * HERE. The screen used to render every row from the guardian's angle — so a
 * student opening «المرتبطون» read THEIR OWN NAME with a cancel button beside it,
 * and the link waiting on them was invisible. Re-deriving the side in TypeScript
 * is the two-spellings defect `cohort_gate` paid for; so is re-deriving
 * `can_decide`, which is why the accept button reads the flag and nothing else.
 */
export function RelationRow({
  relation,
  onAccept,
  onRevoke,
  onSavePermissions,
}: {
  relation: GuardianRelation;
  onAccept: (uuid: string) => Promise<void>;
  onRevoke: (uuid: string) => Promise<void>;
  onSavePermissions: (uuid: string, permissions: string[]) => Promise<void>;
}) {
  const readingAsStudent = relation.viewer_side === "student";

  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<string[]>(() => relation.permissions.map((p) => p.key));
  const [busy, setBusy] = useState(false);

  /*
   * Whose name the row is about. A student needs the GUARDIAN's name — the whole
   * of FR-007's «who is asking» — and a guardian needs the child's. `guardian` is
   * optional on the payload because a Resource sends it only when it was eager
   * loaded, so the fallback is a sentence rather than an empty heading.
   */
  const heading = readingAsStudent
    ? (relation.guardian?.name ?? "وليّ أمر غير معروف")
    : relation.student_name;

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    try {
      await action();
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="rounded-xl border border-line p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-semibold text-ink">{heading}</p>

          <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
            <span>{relation.relation_type_label}</span>
            {/*
              ⚠️ `status_label` FROM THE PAYLOAD, NEVER `StatusBadge`. That helper
              maps `pending` to «بانتظار الدفع» — a payment word, on a family link.
            */}
            <Badge tone={relation.status === "active" ? "success" : "warning"}>
              {relation.status_label}
            </Badge>
            {!relation.student_has_account && !readingAsStudent && <span>لا يملك حساباً بعد</span>}
          </div>

          <p className="mt-2 text-xs text-ink-muted">
            {readingAsStudent ? "ما سيطّلع عليه:" : "ما يطّلع عليه:"}
          </p>

          {relation.permissions.length === 0 ? (
            <p className="mt-1 text-xs text-ink-muted">لا شيء.</p>
          ) : (
            <ul className="mt-1 flex flex-wrap gap-1.5">
              {relation.permissions.map((permission) => (
                <li
                  key={permission.key}
                  className="rounded bg-primary-soft px-2 py-0.5 text-xs text-primary-ink"
                >
                  {permission.label}
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/*
            The accept button, and the flag is the only condition. The server
            answers `can_decide` from the same predicate the endpoint enforces —
            including for a link created before this feature existed, which nobody
            may settle because nobody can prove who asked for it.
          */}
          {relation.can_decide && (
            <Button size="sm" loading={busy} onClick={() => void run(() => onAccept(relation.uuid))}>
              قبول
            </Button>
          )}

          {relation.status !== "revoked" && (
            /*
              ⚠️ A CONFIRMATION, BECAUSE THERE IS NO WAY BACK OUT OF `revoked`.
              Asking again is a brand-new request that the other party has to accept
              a second time — so a stray tap on a phone costs a relationship, not a
              toggle. It was a plain danger button until 030.
            */
            <ConfirmButton
              size="sm"
              confirmLabel={relation.can_decide ? "تأكيد الرفض" : "تأكيد القطع"}
              loading={busy}
              onConfirm={() => void run(() => onRevoke(relation.uuid))}
            >
              {relation.can_decide ? "رفض" : "قطع الارتباط"}
            </ConfirmButton>
          )}
        </div>
      </div>

      {/*
        ⚠️ THE EDITOR IS WHAT MAKES US3 EXIST AT ALL. `PATCH /family/relations/{uuid}`
        has shipped with a policy, a rule and zero callers in the whole frontend —
        the «an endpoint nobody calls» family this repository records three times.
        The rule that a guardian may narrow but never widen is enforced on the
        server; this is the door it guards.
      */}
      {relation.status === "active" && (
        <div className="mt-3 border-t border-line pt-3">
          {editing ? (
            <div className="space-y-3">
              <fieldset>
                <legend className="mb-2 text-sm font-medium text-ink">
                  {readingAsStudent ? "ما تسمح له بالاطّلاع عليه" : "ما تطّلع عليه"}
                </legend>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {GUARDIAN_PERMISSIONS.map((permission) => (
                    <CheckboxField
                      key={permission.key}
                      id={`perm-${relation.uuid}-${permission.key}`}
                      label={permission.label}
                      checked={draft.includes(permission.key)}
                      onChange={(checked) =>
                        setDraft((current) =>
                          checked
                            ? [...current, permission.key]
                            : current.filter((value) => value !== permission.key),
                        )
                      }
                    />
                  ))}
                </div>
              </fieldset>

              {/*
                Said before the request rather than after the refusal: widening is
                the student's decision (FR-009), and a guardian who does not know
                that reads the 422 as a fault.
              */}
              {!readingAsStudent && (
                <p className="text-xs text-ink-muted">
                  يمكنك التنازل عمّا لا تحتاجه. إضافة صلاحية جديدة قرار الطالب وحده.
                </p>
              )}

              <div className="flex gap-2">
                <Button
                  size="sm"
                  loading={busy}
                  onClick={() => void run(async () => {
                    await onSavePermissions(relation.uuid, draft);
                    setEditing(false);
                  })}
                >
                  حفظ
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setDraft(relation.permissions.map((p) => p.key));
                    setEditing(false);
                  }}
                >
                  إلغاء
                </Button>
              </div>
            </div>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
              تعديل الصلاحيات
            </Button>
          )}
        </div>
      )}
    </li>
  );
}
