"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, TextField } from "@/components/ui/Field";
import { Table, type Column } from "@/components/ui/Table";
import { billing, formatCredits, type StudentBalanceRow } from "@/lib/billing";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { P, can } from "@/lib/permissions";

/**
 * Who has sessions left, and who has stopped.
 *
 * ⚠️ NO MONEY ON THIS SCREEN, and none arrives to put here. The API sends
 * credits alone: a session's price is the teacher's approved rate plus platform
 * constants, so a teacher reading two priced rows could solve for the platform's
 * margin — the mirror of the rule that keeps a teacher's rate off every public
 * page.
 *
 * ⚠️ ONE ROW PER (STUDENT × COURSE), never a total per student. +10 in maths and
 * −6 in physics adds to +4 and "fine", while withholding is decided per course
 * precisely so the paid-up course stays open. A total here would hide the one
 * course that has stopped.
 */
function buildColumns(
  onEdit: ((row: StudentBalanceRow) => void) | null,
): Column<StudentBalanceRow>[] {
  return [
  { key: "student", header: "الطالب", render: (row) => row.student_name },
  { key: "course", header: "الكورس", render: (row) => row.course_title },
  {
    key: "remaining",
    header: "المتبقّي",
    numeric: true,
    render: (row) => formatCredits(row.remaining_credits),
  },
  {
    key: "consumed",
    header: "المستهلَك",
    numeric: true,
    render: (row) => formatCredits(row.consumed_credits),
  },
  {
    key: "state",
    header: "الحالة",
    // The label carries the meaning and the tone is emphasis only, so the row
    // still reads correctly to someone who cannot tell the two colours apart.
    render: (row) =>
      row.is_withheld ? (
        <Badge tone="danger">موقوف</Badge>
      ) : (
        <Badge tone="success">متاح</Badge>
      ),
  },
  /*
  | ⛔ THE CEILING MOVED ONLY BY ITSELF UNTIL NOW. `EvaluateCreditLimitsJob` and
  | a recorded consent were its only writers reachable from the product:
  | `PATCH …/limit` existed, was permissioned and was tested, and no file in the
  | frontend called it — so a student demoted in error had no way back except a
  | hand-written row in the production database.
  |
  | ⚠️ THE BUTTON IS ABSENT RATHER THAN DISABLED FOR A TEACHER. Raising a ceiling
  | creates a debt the PLATFORM carries alone (Q-4) and the teacher is the party
  | paid out of it, so this is not a control they may have — and a disabled one
  | invites the question of how to enable it.
  */
  {
    key: "limit",
    header: "السقف",
    numeric: true,
    render: (row) =>
      onEdit === null ? (
        <span>{formatCredits(row.credit_limit_credits)}</span>
      ) : (
        <span className="flex items-center justify-end gap-2">
          {formatCredits(row.credit_limit_credits)}
          <Button size="sm" variant="secondary" onClick={() => onEdit(row)}>
            عدّل
          </Button>
        </span>
      ),
  },
  ];
}

export default function StudentBalancesPage() {
  const { user } = useAuth();
  const [rows, setRows] = useState<StudentBalanceRow[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "error">("loading");
  const [editing, setEditing] = useState<StudentBalanceRow | null>(null);
  // ⚠️ A STRING, BECAUSE `NumberField` IS ONE. An emptied box is "" and not 0,
  // and a numeric state would turn «I cleared it to retype» into a saved
  // ceiling of zero — which is the switch to prepaid for that student.
  const [limit, setLimit] = useState("0");
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = useCallback(() => {
    setState("loading");

    billing
      .students()
      .then((res) => {
        setRows(res.data ?? []);
        setState("ready");
      })
      .catch(() => setState("error"));
  }, []);

  useEffect(load, [load]);

  const openEditor = (row: StudentBalanceRow) => {
    setEditing(row);
    setLimit(String(row.credit_limit_credits));
    setReason("");
    setError("");
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editing) return;

    setSaving(true);
    setError("");

    try {
      await billing.setCreditLimit(editing.student_uuid, {
        course: editing.course_uuid,
        credit_limit_credits: Number(limit),
        reason,
      });
      setEditing(null);
      // Reloaded rather than patched in place: the Action caps the number
      // against a platform setting and may store less than was typed, and
      // withholding is derived from five inputs the server alone has seen.
      load();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const mayManageLimits = can(user, P.billingLimitManage);
  const columns = buildColumns(mayManageLimits ? openEditor : null);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">أرصدة الطلاب</h1>
        <p className="mt-1 text-sm text-ink-muted">
          الحصص المتبقّية لكل طالب في كل كورس، ومن توقّف حجزه منهم.
        </p>
      </header>

      {editing && (
        <Card as="section" padding="sm">
          <form onSubmit={save} className="space-y-3">
            <p className="font-medium text-ink">
              سقف {editing.student_name} في {editing.course_title}
            </p>

            {error !== "" && <Alert tone="danger" title={error} />}

            <div className="flex flex-wrap items-end gap-3">
              <div className="min-w-40">
                <NumberField
                  id="credit_limit"
                  label="السقف بالحصص"
                  value={limit}
                  onChange={setLimit}
                  min={0}
                  hint="صفر يعني الدفع المسبق لهذا الكورس وحده."
                  required
                />
              </div>

              <div className="min-w-56 flex-1">
                {/* Recorded in the financial audit trail under the officer's name
                    (FR-039). The server refuses a blank one, and so does this. */}
                <TextField
                  id="limit_reason"
                  label="السبب"
                  value={reason}
                  onChange={setReason}
                  minLength={3}
                  required
                />
              </div>

              <Button type="submit" loading={saving} loadingLabel="جارٍ الحفظ…">
                احفظ
              </Button>
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>
                إلغاء
              </Button>
            </div>
          </form>
        </Card>
      )}

      <Table
        caption="أرصدة الطلاب في كل كورس"
        columns={columns}
        rows={rows}
        rowKey={(row) => `${row.student_uuid}:${row.course_uuid}`}
        state={state}
        emptyTitle="لا طلاب مسجّلين بعد"
        emptyDescription="يظهر هنا كل طالب لديه تسجيل نشط عندك، ولو لم يشترِ رصيداً بعد."
        onRetry={load}
      />

      <p className="text-xs text-ink-muted">
        الأرقام بالحصص لا بالمال. يتوقّف الحجز في الكورس الذي نفد رصيده وحده،
        ويعود فور اعتماد الدفع بلا أي إجراء منك.
      </p>
    </div>
  );
}
