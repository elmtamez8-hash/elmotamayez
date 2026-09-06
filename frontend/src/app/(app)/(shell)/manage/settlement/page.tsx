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
import { Table, type Column } from "@/components/ui/Table";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";

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
      header: "المقاعد المُجمَّدة",
      numeric: true,
      render: (unit) => unit.frozen_seats.toLocaleString("ar-QA"),
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
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          {/* h2, not h1: the shell header already renders the page title as the
              document's h1 from the nav item it matched. */}
          <h2 className="text-2xl font-bold text-ink">كشف التسوية</h2>
          <p className="mt-1 text-sm text-ink-muted">
            ما تستحقّه عن عملك في هذه الفترة، بسعرك المعتمَد وقت تنفيذ كل حصة.
          </p>
        </div>
        <Button
          onClick={exportStatement}
          variant="secondary"
          loading={exporting}
          loadingLabel="جارٍ التصدير…"
        >
          تصدير الكشف
        </Button>
      </header>

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

      <section aria-labelledby="rates" className="space-y-3">
        <h3 id="rates" className="text-lg font-bold text-ink">
          أسعارك السارية
        </h3>
        <div className="grid gap-3 sm:grid-cols-2">
          {statement.rates.map((rate) => (
            <Card key={rate.uuid} padding="sm">
              <p className="text-sm font-medium text-ink">{rate.session_type_label}</p>
              <bdi className="mt-1 block text-xl font-bold text-ink">
                {formatMinorMoney(rate.amount_minor, rate.currency)}
              </bdi>
              <p className="mt-1 text-xs text-ink-muted">
                يسري من {formatDate(rate.effective_from)}
              </p>
            </Card>
          ))}
        </div>
        {statement.rates.length === 0 ? (
          <p className="text-sm text-ink-muted">
            لم يُعتمَد لك سعر بعد. اطلب سعرك من النموذج أدناه قبل أول حصة.
          </p>
        ) : null}

        {/*
          ⚠️ البابُ الذي لم يكنْ له زرّ. `POST /settlement/rate-requests` قائمٌ منذُ
          ٠١٤ و`settlement.requestRate` مكتوبٌ في `lib/settlement.ts`، ولم ينادِهما
          ملفٌّ واحد: هذه الصفحةُ كانت تعرضُ الأسعارَ والطلبَ القائمَ ثمّ تقولُ
          للمدرّسِ «تواصلْ مع إدارةِ المنصّة» — تذكرةُ دعمٍ عن مسارٍ مبنيّ. عائلةُ
          `writeBans.lift` نفسُها: إجراءٌ ونقطةُ نهايةٍ ولا شاشة.

          ولا حقلَ مادّةٍ ولا مرحلة: الخادمُ يقبلُ سعراً مخصوصاً بمادّةٍ ولا مدرّسَ
          طلبَه بعد — يُضافُ متى طُلِب.
        */}
        <Card as="section" padding="sm">
          <h4 className="mb-3 text-sm font-semibold text-ink">اطلب تغيير سعرك</h4>

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

          <p className="mt-2 text-xs text-ink-muted">
            لا يسري السعر إلا بعد اعتماد المنصة، والسعر الحالي يظل سارياً حتى ذلك.
          </p>

          <div className="mt-3">
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
      </section>

      <section aria-labelledby="units" className="space-y-3">
        <h3 id="units" className="text-lg font-bold text-ink">
          وحدات هذه الفترة
        </h3>
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
