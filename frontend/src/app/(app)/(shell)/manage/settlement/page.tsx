"use client";

import { useCallback, useEffect, useState } from "react";
import { errorMessage } from "@/lib/api";
import { formatDate, formatMinorMoney } from "@/lib/labels";
import {
  settlement,
  toMinorUnits,
  type SettlementPeriod,
  type TeacherStatement,
  type TeachingUnit,
} from "@/lib/settlement";
import { StatementSummary } from "@/components/settlement/StatementSummary";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { NumberField, SelectField } from "@/components/ui/Field";
import { StatusBadge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionHeading } from "@/components/ui/SectionHeading";
import {
  DownloadIcon,
  InfoIcon,
  ScheduleIcon,
  SessionsIcon,
  SettlementIcon,
  SparkIcon,
  TagIcon,
  UserIcon,
  UsersIcon,
} from "@/components/icons";
import { Table, type Column } from "@/components/ui/Table";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

import { arabicNumber } from "@/lib/numerals";
/**
 * The teacher's statement.
 *
 * What is NOT on this page is the point of the page: no student's payment, no
 * platform fee, no sale price. Not filtered out here — never sent, and the
 * backend fails its own build if it starts sending them.
 *
 * Errors go through `errorMessage()`. A raw one would reach the screen in
 * English, on the one screen in the product that is about money.
 */
export default function SettlementPage() {
  const [statement, setStatement] = useState<TeacherStatement | null>(null);
  const [units, setUnits] = useState<TeachingUnit[]>([]);
  const [periods, setPeriods] = useState<SettlementPeriod[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState("");

  // طلبُ السعر. المبلغُ نصٌّ حتّى الإرسالِ لأنّ حقلاً فارغاً ليس صفراً.
  const [rateType, setRateType] = useState("individual");
  const [rateAmount, setRateAmount] = useState("");
  const [requesting, setRequesting] = useState(false);
  const [rateError, setRateError] = useState("");

  const load = useCallback(() => {
    setLoading(true);
    setError("");

    Promise.all([settlement.statement(), settlement.units(), settlement.periods()])
      .then(([summary, page, closed]) => {
        setStatement(summary);
        setUnits(page.data ?? []);
        setPeriods(closed.data ?? []);
      })
      .catch((err: unknown) =>
        setError(errorMessage(err, "تعذّر تحميل كشف التسوية. أعد المحاولة.")),
      )
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const exportStatement = async () => {
    setExporting(true);
    // Cleared before the attempt, not after it: a stale banner over a download
    // that has just succeeded reads as a failure.
    setExportError("");

    try {
      await settlement.exportStatement();
    } catch (err: unknown) {
      setExportError(errorMessage(err, "تعذّر تصدير الكشف. أعد المحاولة."));
    } finally {
      setExporting(false);
    }
  };

  /*
   * ⚠️ والرفضُ للخادمِ وحدَه. فـ`RequestRateChange` يرفضُ لسببينِ لا ثالثَ لهما —
   * طلبٌ قائمٌ على النطاقِ نفسِه، وبلوغُ الحدِّ في النافذةِ الزمنيّة — وكلاهما
   * يقرأُ صفوفاً لا تصلُ هذه الشاشةَ (`pending_rate_request` مفردٌ والرفضُ لكلِّ
   * نطاق). فإخفاءُ الاستمارةِ هنا اشتقاقٌ ثانٍ للقاعدة: يمنعُ طلباً مشروعاً،
   * ويختلفُ عنِ الخادمِ عندَ أوّلِ تعديل. جملةُ الخادمِ هي الجواب، وهي تحملُ
   * تاريخَ الطلبِ التّالي.
   */
  const requestRate = async () => {
    const minor = toMinorUnits(rateAmount);

    if (minor === null || minor <= 0) {
      setRateError("اكتب مبلغاً أكبر من صفر، بمنزلتين عشريتين على الأكثر.");

      return;
    }

    setRequesting(true);
    setRateError("");

    try {
      await settlement.requestRate({
        session_type: rateType as "individual" | "group",
        requested_amount_minor: minor,
      });

      setRateAmount("");
      // لتظهرَ لافتةُ «طلب سعر قيد الاعتماد» من جوابِ الخادمِ لا من افتراضِنا.
      load();
    } catch (err: unknown) {
      setRateError(errorMessage(err, "تعذّر إرسال طلب السعر. أعد المحاولة."));
    } finally {
      setRequesting(false);
    }
  };

  if (loading) return <RowsSkeleton />;

  if (error !== "" || statement === null) {
    return (
      <ErrorState
        title="تعذّر تحميل كشف التسوية"
        description={error || "أعد المحاولة بعد قليل."}
        onRetry={load}
      />
    );
  }

  const money = (minor: number) => formatMinorMoney(minor, statement.currency);

  // Closed but not yet paid. Derived from the periods list already fetched — the
  // number is frozen on each row, so summing it here costs no query and cannot
  // disagree with what the close wrote. `paid` periods are excluded: that money
  // has left, and showing it as owed would ask the teacher to chase a transfer
  // they already received.
  const awaitingPayoutMinor = periods
    .filter((period) => period.status === "closed")
    .reduce((total, period) => total + period.net_minor, 0);

  // «—», never «٠». A unit delivered before ٠٣٥ carries no verdict at all, and a
  // zero there would tell a teacher nobody attended an hour they taught in full.
  const seatCount = (seats: number | null) =>
    seats === null ? "—" : arabicNumber(seats);

  const columns: Column<TeachingUnit>[] = [
    {
      key: "delivered_at",
      header: "تاريخ التنفيذ",
      render: (unit) => formatDate(unit.delivered_at),
    },
    {
      key: "session_type",
      header: "نوع الحصة",
      render: (unit) => unit.session_type_label,
    },
    {
      key: "frozen_seats",
      header: "المقاعد المحجوزة",
      numeric: true,
      render: (unit) => arabicNumber(unit.frozen_seats),
    },
    {
      key: "attended_seats",
      header: "الحاضرون",
      numeric: true,
      render: (unit) => seatCount(unit.attended_seats),
    },
    {
      key: "charged_seats",
      header: "المقاعد المحمَّلة",
      numeric: true,
      render: (unit) => seatCount(unit.charged_seats),
    },
    {
      key: "status",
      header: "الحالة",
      render: (unit) => (
        <>
          <StatusBadge status={unit.status} />
          {/* The reason is the whole value of a pending row: "waiting" with no
              object is the message that generates a support ticket. */}
          {unit.pending_reason ? (
            <span className="ms-2 text-xs text-ink-muted">{unit.pending_reason}</span>
          ) : null}
        </>
      ),
    },
    {
      key: "amount_minor",
      header: "المبلغ",
      numeric: true,
      render: (unit) => money(unit.amount_minor),
    },
  ];

  return (
    <div className="space-y-8">
      <PageHeader
        Icon={SettlementIcon}
        title="كشف التسوية"
        description="ما تستحقّه عن عملك في هذه الفترة، بسعرك المعتمَد وقت تنفيذ كل حصة."
        actions={
          <Button
            onClick={exportStatement}
            variant="secondary"
            iconStart={<DownloadIcon className="h-4 w-4" />}
            loading={exporting}
            loadingLabel="جارٍ التصدير…"
          >
            تصدير الكشف
          </Button>
        }
      />

      {exportError !== "" && (
        <Alert tone="danger" title="تعذّر التصدير">
          {exportError}
        </Alert>
      )}

      <StatementSummary
        statement={statement}
        awaitingPayoutMinor={awaitingPayoutMinor}
      />

      {statement.pending_rate_request ? (
        <Alert tone="info" title="طلب سعر قيد الاعتماد">
          لديك طلب سعر قيد الاعتماد بقيمة{" "}
          {money(statement.pending_rate_request.requested_amount_minor)} — قُدِّم في{" "}
          {formatDate(statement.pending_rate_request.requested_at)}. السعر الحالي
          يظل سارياً حتى يُعتمد.
        </Alert>
      ) : null}

      {/* The price and the way to change it, side by side: the teacher reads
          the current number and asks for a new one in the same glance. */}
      <div className="grid gap-6 lg:grid-cols-5">
        <section aria-labelledby="rates" className="space-y-3 lg:col-span-2">
          <SectionHeading id="rates" Icon={TagIcon} title="أسعارك السارية" />
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
            {statement.rates.map((rate) => {
              const RateIcon = rate.session_type === "group" ? UsersIcon : UserIcon;

              return (
                <Card key={rate.uuid} padding="sm" interactive>
                  <div className="flex items-start gap-3">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink">
                      <RateIcon className="h-5 w-5" />
                    </span>
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-ink">{rate.session_type_label}</p>
                      <bdi className="mt-0.5 block text-xl font-bold text-ink">
                        {formatMinorMoney(rate.amount_minor, rate.currency)}
                      </bdi>
                      <p className="mt-1 flex items-center gap-1 text-xs text-ink-muted">
                        <ScheduleIcon className="h-3.5 w-3.5" />
                        يسري من {formatDate(rate.effective_from)}
                      </p>
                    </div>
                  </div>
                </Card>
              );
            })}
          </div>
          {statement.rates.length === 0 ? (
            <p className="rounded-2xl border border-dashed border-line px-4 py-3 text-sm text-ink-muted">
              لم يُعتمَد لك سعر بعد. اطلب سعرك من النموذج المجاور قبل أول حصة.
            </p>
          ) : null}
        </section>

        {/*
          ⚠️ البابُ الذي لم يكنْ له زرّ: `POST /settlement/rate-requests` قائمٌ منذُ
          ٠١٤، وكانت هذه الصفحةُ تقولُ «تواصلْ مع إدارةِ المنصّة» بدلَه. ولا حقلَ
          مادّةٍ ولا مرحلة: الخادمُ يقبلُ سعراً مخصوصاً ولا مدرّسَ طلبَه بعد.
        */}
        <div className="lg:col-span-3">
          <Card as="section" padding="md">
            <div className="mb-4">
              <SectionHeading
                id="rate-request"
                level={4}
                Icon={SparkIcon}
                title="اطلب تغيير سعرك"
                description="لا يسري السعر إلا بعد اعتماد المنصة، والسعر الحالي يظل سارياً حتى ذلك."
              />
            </div>

            {rateError && (
              <div className="mb-3">
                <Alert tone="danger" title="لم يُرسَل الطلب">{rateError}</Alert>
              </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
              <SelectField
                id="rate_session_type"
                label="نوع الحصة"
                value={rateType}
                onChange={setRateType}
                options={[
                  { value: "individual", label: "حصة خاصة" },
                  { value: "group", label: "حصة جماعية" },
                ]}
                required
              />

              <NumberField
                id="rate_amount"
                label={`السعر للحصة (${statement.currency})`}
                value={rateAmount}
                onChange={setRateAmount}
                min={0}
                step={0.01}
                placeholder="150.00"
                required
              />
            </div>

            {/* ٠٣٥ · T073 — where the price is AGREED, not only where it is
                reported: what counts as a billed seat, before a number is named. */}
            <p className="mt-3 flex items-start gap-2 rounded-2xl bg-surface px-3 py-2 text-xs text-ink-muted">
              <InfoIcon className="mt-0.5 h-4 w-4 shrink-0" />
              <span>
                يُحتسب السعر على المقعد المحمَّل — الحاضر ومَن تخلَّف دون إخطار — لا على
                كل مقعد محجوز.
              </span>
            </p>

            <div className="mt-4">
              <Button
                type="button"
                loading={requesting}
                loadingLabel="جارٍ الإرسال…"
                onClick={() => void requestRate()}
              >
                أرسل الطلب
              </Button>
            </div>
          </Card>
        </div>
      </div>

      <section aria-labelledby="units" className="space-y-3">
        {/* ٠٣٥ · T073 — the sentence names which of the three seat counts is
            billed, and carries no number of its own. */}
        <SectionHeading
          id="units"
          Icon={SessionsIcon}
          title="وحدات هذه الفترة"
          description="المحاسبة على المقاعد المحمَّلة وحدها — وهي مَن حضر، ومَن تخلَّف دون إخطار. المقعد الذي أُخطِر عنه في الوقت أو قُبِل عذره لا يُحمَّل ولا يدخل مستحقَّك."
        />
        <Table
          columns={columns}
          rows={units}
          rowKey={(unit) => unit.uuid}
          caption="وحدات التدريس في الفترة الحالية بحالتها ومبلغها"
          emptyTitle="لا وحدات بعد"
          emptyDescription="تُحتسب الوحدة بعد انتهاء الحصة واكتمال حزمتها."
        />
      </section>
    </div>
  );
}
