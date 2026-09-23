"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { CheckboxField, SelectField } from "@/components/ui/Field";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { StatTile } from "@/components/ui/StatTile";
import { BellIcon, ProgressIcon, SiteIcon } from "@/components/icons";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage, fieldErrors } from "@/lib/api";
import { formatDate } from "@/lib/labels";
import { arabicNumber } from "@/lib/numerals";
import {
  formatMetric,
  reports,
  type PlatformReport,
  type ReportCadence,
  type ReportSubscription,
} from "@/lib/reports";

/**
 * The platform's numbers, and a standing request for a copy (FR-040 · FR-045).
 *
 * ⚠️ THE REPORT AND THE SUBSCRIPTION ARE ONE SCREEN ON PURPOSE. A subscription
 * form on its own asks somebody to tick metric names they have never seen a value
 * for; showing today's numbers beside the boxes is what makes the choice mean
 * something. Both reads are guarded by the same platform permission, so there is
 * no half of this page a narrower reader could open.
 */
export default function ReportSubscriptionsPage() {
  const [report, setReport] = useState<PlatformReport | null>(null);
  const [subscription, setSubscription] = useState<ReportSubscription | null>(null);
  const [selected, setSelected] = useState<string[]>([]);
  const [cadence, setCadence] = useState<ReportCadence>("weekly");
  const [active, setActive] = useState(false);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    Promise.all([reports.platform(), reports.subscription()])
      .then(([platform, mine]) => {
        setReport(platform.data);
        setSubscription(mine.data);
        setSelected(mine.data.metric_keys);
        setCadence(mine.data.cadence);
        setActive(mine.data.is_active);
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const toggle = (key: string, on: boolean) =>
    setSelected((current) =>
      on ? [...current, key] : current.filter((entry) => entry !== key),
    );

  const save = async () => {
    setBusy(true);
    setError("");
    setErrors({});
    setSaved(false);

    try {
      await reports.saveSubscription(selected, cadence, active);
      setSaved(true);
    } catch (err: unknown) {
      setErrors(fieldErrors(err));
      setError(errorMessage(err, "تعذّر حفظ اشتراك التقارير. أعد المحاولة."));
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <RowsSkeleton />;
  if (failed || report === null || subscription === null) return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={ProgressIcon}
        title="تقارير المنصّة"
        description={
          <>
            أرقام يوم <bdi>{formatDate(report.date)}</bdi> — تُحدَّث بتجميع ليليّ،
            واختيارك أدناه يصلك في موعده.
          </>
        }
      />

      <section aria-labelledby="report-today" className="space-y-4">
        <SectionHeading id="report-today" Icon={ProgressIcon} title="الأرقام اليوم" />

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {report.metrics.map((metric) => (
            <StatTile
              key={metric.key}
              label={metric.label}
              value={formatMetric(metric)}
              hint={
                metric.is_ratio ? (
                  <>
                    {arabicNumber(metric.numerator)} من{" "}
                    {arabicNumber(metric.denominator)}
                  </>
                ) : undefined
              }
            />
          ))}
        </div>
      </section>

      <Card as="section">
        <SectionHeading id="report-regions" Icon={SiteIcon} title="توزيع الطلاب بالمنطقة" />

        {/* A region with nobody in it is shown with a zero rather than dropped:
            the absence is the fact somebody opening this page is looking for. */}
        <ul className="mt-4 space-y-1 text-sm text-ink">
          {report.regions.map((region) => (
            <li key={region.slug} className="flex justify-between">
              <span>{region.name}</span>
              <span>{arabicNumber(region.students)}</span>
            </li>
          ))}
        </ul>
      </Card>

      <Card as="section">
        <SectionHeading id="report-subscription" Icon={BellIcon} title="اشتراكي في التقرير" />

        {error !== "" && (
          <Alert tone="danger" title="تعذّر الحفظ">
            {error}
          </Alert>
        )}

        {saved && (
          <Alert tone="success" title="حُفظ اشتراكك">
            يصلك التقرير في موعده القادم.
          </Alert>
        )}

        <div className="mt-4 space-y-2">
          {subscription.available.map((metric) => (
            <CheckboxField
              key={metric.key}
              id={`metric-${metric.key}`}
              label={metric.label}
              checked={selected.includes(metric.key)}
              onChange={(on) => toggle(metric.key, on)}
            />
          ))}
        </div>

        {errors.metric_keys !== undefined && (
          <p className="mt-2 text-sm text-danger-ink">{errors.metric_keys}</p>
        )}

        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <SelectField
            id="cadence"
            label="دورية الإرسال"
            value={cadence}
            onChange={(value) => setCadence(value as ReportCadence)}
            options={[
              { value: "weekly", label: "أسبوعيّاً" },
              { value: "monthly", label: "شهريّاً" },
            ]}
            error={errors.cadence}
          />

          <div className="self-end">
            <CheckboxField
              id="is_active"
              label="أرسل لي التقرير"
              checked={active}
              onChange={setActive}
            />
          </div>
        </div>

        {subscription.last_sent_on !== null && (
          <p className="mt-3 text-xs text-ink-muted">
            آخر إرسال: <bdi>{formatDate(subscription.last_sent_on)}</bdi>
          </p>
        )}

        <div className="mt-4">
          <Button onClick={save} disabled={busy || selected.length === 0}>
            حفظ
          </Button>
        </div>
      </Card>
    </div>
  );
}
