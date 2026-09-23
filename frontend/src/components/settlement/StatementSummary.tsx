import { Card } from "@/components/ui/Card";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatTile } from "@/components/ui/StatTile";
import {
  ClockIcon,
  CreditsIcon,
  RefundIcon,
  ScheduleIcon,
  SessionsIcon,
  StudentIcon,
  WalletIcon,
} from "@/components/icons";
import { counted, formatDate, formatMinorMoney } from "@/lib/labels";
import type { TeacherStatement } from "@/lib/settlement";

import { arabicNumber } from "@/lib/numerals";
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
      <div className="flex flex-wrap items-end justify-between gap-3">
        <SectionHeading id="statement-summary" Icon={ScheduleIcon} title="الفترة الحالية" />
        <p className="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
          <span className="rounded-full border border-line bg-surface-raised px-3 py-1">
            من {formatDate(statement.period.starts_on)} إلى {formatDate(statement.period.ends_on)}
          </span>
          <span className="rounded-full bg-primary-soft px-3 py-1 font-medium text-primary-ink">
            الصرف القادم {formatDate(statement.next_payout_on)}
          </span>
        </p>
      </div>

      {awaitingPayoutMinor > 0 && (
        <div className="flex items-start gap-3 rounded-3xl border border-accent/40 bg-accent/10 p-4">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-accent text-accent-foreground">
            <ClockIcon className="h-5 w-5" />
          </span>
          <div>
            <p className="text-sm text-ink">
              <span className="font-semibold">مستحقّ من فترات مغلقة لم تُصرَف:</span>{" "}
              <bdi className="font-bold">{money(awaitingPayoutMinor)}</bdi>
            </p>
            <p className="mt-1 text-xs text-ink-muted">
              أُغلقت فترته وتجمّد مبلغه، وينتظر التحويل. لا يظهر ضمن أرقام الفترة
              الجارية أدناه.
            </p>
          </div>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile
          emphasis
          Icon={WalletIcon}
          label="الصافي المستحق"
          value={money(statement.net_minor)}
          hint={
            statement.carried_in_minor === 0
              ? undefined
              : `يشمل مُرحّلاً من الفترة السابقة: ${money(statement.carried_in_minor)}`
          }
        />
        <StatTile Icon={CreditsIcon} label="الإجمالي قبل الخصومات" value={money(statement.gross_minor)} />
        <StatTile
          Icon={SessionsIcon}
          label="الوحدات المستحقّة"
          value={arabicNumber(statement.units.accrued)}
          hint={`فردية ${arabicNumber(individual)} · جماعية ${arabicNumber(group)}`}
        />
        <StatTile
          Icon={StudentIcon}
          label="الطلاب"
          value={arabicNumber(statement.students_count)}
          hint={
            statement.units.pending_package === 0
              ? undefined
              : `${counted(statement.units.pending_package, {
                  one: "وحدة واحدة",
                  two: "وحدتان",
                  few: "وحدات",
                  many: "وحدة",
                  other: "وحدة",
                })} بانتظار اكتمال الحزمة`
          }
        />
      </div>

      {statement.deductions.length > 0 && (
        <Card padding="sm">
          <div className="mb-3">
            <SectionHeading id="statement-deductions" level={4} Icon={RefundIcon} title="الخصومات" />
          </div>
          <ul className="divide-y divide-line text-sm">
            {statement.deductions.map((line) => (
              <li
                key={`${line.type}-${line.reason ?? ""}`}
                className="flex items-baseline justify-between gap-4 rounded-lg px-2 py-2 transition hover:bg-primary-soft/30"
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
