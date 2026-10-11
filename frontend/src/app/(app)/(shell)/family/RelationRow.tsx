"use client";

import { useState } from "react";

import { ConsentScreen } from "@/components/compliance/ConsentScreen";
import { CheckIcon } from "@/components/icons";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { CheckboxField } from "@/components/ui/Field";
import { childLinkName, GUARDIAN_PERMISSIONS, type GuardianRelation } from "@/lib/notifications";

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
  onConsented,
}: {
  relation: GuardianRelation;
  onAccept: (uuid: string) => Promise<void>;
  onRevoke: (uuid: string) => Promise<void>;
  onSavePermissions: (uuid: string, permissions: string[]) => Promise<void>;
  onConsented?: () => void;
}) {
  const readingAsStudent = relation.viewer_side === "student";

  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<string[]>(() => relation.permissions.map((p) => p.key));
  const [busy, setBusy] = useState(false);
  const [consenting, setConsenting] = useState(false);

  /*
   * Whose name the row is about. A student needs the GUARDIAN's name — the whole
   * of FR-007's «who is asking» — and a guardian needs the child's. `guardian` is
   * optional on the payload because a Resource sends it only when it was eager
   * loaded, so the fallback is a sentence rather than an empty heading.
   */
  const heading = readingAsStudent
    ? (relation.guardian?.name ?? "وليّ أمر غير معروف")
    : childLinkName(relation.student_name, relation.status);

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    try {
      await action();
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="overflow-hidden rounded-3xl border border-line bg-surface-raised shadow-sm">
      {/* The bold band (owner request 2026-10-11): brand colour for a live link,
          the pale tint while it waits or after it ended. */}
      <div
        className={`flex flex-wrap items-start justify-between gap-3 px-5 py-4 ${
          relation.status === "active" ? "bg-squares bg-primary text-white" : "bg-primary-soft text-primary-ink"
        }`}
      >
        <div className="min-w-0">
          <p className="text-lg font-extrabold">{heading}</p>

          <div className="mt-1 flex flex-wrap items-center gap-2 text-xs font-semibold">
            <span className="opacity-85">{relation.relation_type_label}</span>
            {/*
              ⚠️ `status_label` FROM THE PAYLOAD, NEVER `StatusBadge`. That helper
              maps `pending` to «بانتظار الدفع» — a payment word, on a family link.
            */}
            <span className="rounded-full bg-surface-raised p-0.5 shadow-sm">
              <Badge tone={relation.status === "active" ? "success" : "warning"}>
                {relation.status_label}
              </Badge>
            </span>
            {!relation.student_has_account && !readingAsStudent && (
              <span className="opacity-85">لا يملك حساباً بعد</span>
            )}
          </div>
        </div>

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
      </div>

      <div className="p-5">
      <p className="text-sm font-bold text-ink">
        {readingAsStudent ? "ما سيطّلع عليه:" : "ما يطّلع عليه:"}
      </p>

      {relation.permissions.length === 0 ? (
        <p className="mt-2 text-sm text-ink-muted">لا شيء.</p>
      ) : (
        <ul className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
          {relation.permissions.map((permission) => (
            <li key={permission.key} className="flex items-center gap-2 text-sm text-ink">
              <span aria-hidden="true" className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-secondary/15 text-secondary-ink">
                <CheckIcon className="h-3 w-3" />
              </span>
              {permission.label}
            </li>
          ))}
        </ul>
      )}

      {/*
        ⚠️ SPEC 013 — THE ONE THING THAT OPENS A MINOR'S ACCOUNT, AND NO SCREEN
        OFFERED IT. A self-registered minor gets no sign-in until a guardian
        consents; the notification sends the guardian here, they accept the link —
        and until this block the page offered nothing more, so the child stayed
        locked out for ever. The flag is the server's (`student_awaiting_consent`):
        it needs the child's account status, which nothing else on the row carries.
      */}
      {relation.student_awaiting_consent && relation.student_uuid && (
        <div className="mt-3 space-y-3 border-t border-line pt-3">
          <Alert tone="warning" title="حساب ابنك بانتظار موافقتك">
            لن يستطيع الدخول إلى المنصّة قبل أن توافق على معالجة بياناته.
          </Alert>

          {consenting ? (
            <ConsentScreen
              studentUuid={relation.student_uuid}
              onSaved={() => {
                setConsenting(false);
                onConsented?.();
              }}
            />
          ) : (
            <Button size="sm" onClick={() => setConsenting(true)}>
              مراجعة الموافقة على معالجة البيانات
            </Button>
          )}
        </div>
      )}

      {/*
        ⚠️ THE EDITOR IS WHAT MAKES US3 EXIST AT ALL. `PATCH /family/relations/{uuid}`
        has shipped with a policy, a rule and zero callers in the whole frontend —
        the «an endpoint nobody calls» family this repository records three times.
        The rule that a guardian may narrow but never widen is enforced on the
        server; this is the door it guards.
      */}
      {relation.status === "active" && editing && (
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
          ) : null}
        </div>
      )}

      {relation.status !== "revoked" && !editing && (
        /*
          ⚠️ A CONFIRMATION, BECAUSE THERE IS NO WAY BACK OUT OF `revoked`.
          Asking again is a brand-new request that the other party has to accept
          a second time — so a stray tap on a phone costs a relationship, not a
          toggle. It was a plain danger button until 030. At the foot and quiet
          since 2026-10-11: it is the action a family should reach for least.
        */
        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
          {relation.status === "active" ? (
            <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
              تعديل الصلاحيات
            </Button>
          ) : (
            <span />
          )}
          <ConfirmButton
            size="sm"
            variant="ghost"
            confirmLabel={relation.can_decide ? "تأكيد الرفض" : "تأكيد القطع"}
            loading={busy}
            onConfirm={() => void run(() => onRevoke(relation.uuid))}
          >
            {relation.can_decide ? "رفض" : "قطع الارتباط"}
          </ConfirmButton>
        </div>
      )}
      </div>
    </li>
  );
}
