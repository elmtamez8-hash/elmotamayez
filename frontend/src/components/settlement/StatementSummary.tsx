import { Card } from "@/components/ui/Card";
import { formatDate, formatMinorMoney } from "@/lib/labels";
import type { TeacherStatement } from "@/lib/settlement";

/**
 * The window at a glance: how much work, at what price, for how much.
 *
 * Nothing here concerns a student's money, and that is structural rather than a
 * matter of care — `TeacherStatement` has no such field to render, because
 * `TeacherFieldAllowlist` fails the backend build if the API grows one.
 *
 * Colours come from `@theme` tokens and layout from logical properties: this
 * document is `dir="rtl"`, so `text-start` is the right edge and `ml-*` would put
 * the gutter on the wrong side.
 */

function Figure({
  label,
  value,
  hint,
}: {
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <Card padding="sm">
      <p className="text-xs font-medium text-ink-muted">{label}</p>
      {/* <bdi> so a Latin-digit amount cannot reorder the Arabic around it. */}
      <bdi className="mt-1 block text-2xl font-bold text-ink">{value}</bdi>
      {hint ? <p className="mt-1 text-xs text-ink-muted">{hint}</p> : null}
    </Card>
  );
}

export function StatementSummary({
  statement,
  awaitingPayoutMinor,
}: {
  statement: TeacherStatement;
  /**
   * Money already earned, already frozen, and not yet transferred.
   *
   * Without it this screen lies by omission the moment a period closes: the
   * close moves the total onto the period row and empties the current window,
   * so the headline drops to near zero on a day the teacher is owed the most
   * they have been owed all month. The number is real, it is just no longer in
   * the window this card describes.
   */
  awaitingPayoutMinor: number;
}) {
  const money = (minor: number) => formatMinorMoney(minor, statement.currency);

  const individual = statement.units.by_type.individual ?? 0;
  const group = statement.units.by_type.group ?? 0;

  return (
    <section aria-labelledby="statement-summary" className="space-y-4">
      <h3 id="statement-summary" className="text-lg font-bold text-ink">
        الفترة الحالية
      </h3>

      <p className="text-sm text-ink-muted">
        من {formatDate(statement.period.starts_on)} إلى{" "}
        {formatDate(statement.period.ends_on)} · الصرف القادم{" "}
        {formatDate(statement.next_payout_on)}
      </p>

      {awaitingPayoutMinor > 0 && (
        <Card padding="sm">
          <p className="text-sm text-ink">
            <span className="font-semibold">مستحقّ من فترات مغلقة لم تُصرَف:</span>{" "}
            <bdi className="font-bold">{money(awaitingPayoutMinor)}</bdi>
          </p>
          <p className="mt-1 text-xs text-ink-muted">
            أُغلقت فترته وتجمّد مبلغه، وينتظر التحويل. لا يظهر ضمن أرقام الفترة
            الجارية أدناه.
          </p>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Figure
          label="الصافي المستحق"
          value={money(statement.net_minor)}
          hint={
            statement.carried_in_minor === 0
              ? undefined
              : `يشمل مُرحّلاً من الفترة السابقة: ${money(statement.carried_in_minor)}`
          }
        />
        <Figure label="الإجمالي قبل الخصومات" value={money(statement.gross_minor)} />
        <Figure
          label="الوحدات المستحقّة"
          value={statement.units.accrued.toLocaleString("ar-QA")}
          hint={`فردية ${individual.toLocaleString("ar-QA")} · جماعية ${group.toLocaleString("ar-QA")}`}
        />
        <Figure
          label="الطلاب"
          value={statement.students_count.toLocaleString("ar-QA")}
          hint={
            statement.units.pending_package === 0
              ? undefined
              : `${statement.units.pending_package.toLocaleString("ar-QA")} وحدة بانتظار اكتمال الحزمة`
          }
        />
      </div>

      {statement.deductions.length > 0 && (
        <Card padding="sm">
          <h4 className="mb-2 text-sm font-semibold text-ink">الخصومات</h4>
          <ul className="space-y-1 text-sm">
            {statement.deductions.map((line) => (
              <li
                key={`${line.type}-${line.reason ?? ""}`}
                className="flex items-baseline justify-between gap-4"
              >
                <span className="text-ink-muted">
                  {line.type_label}
                  {line.reason ? ` — ${line.reason}` : ""}
                </span>
                <bdi className="font-medium text-danger-ink">
                  {money(line.amount_minor)}
                </bdi>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </section>
  );
}
