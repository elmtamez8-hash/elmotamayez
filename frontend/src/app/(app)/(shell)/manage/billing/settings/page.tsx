"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField, TextField } from "@/components/ui/Field";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage, fieldErrors } from "@/lib/api";
import { billing, type BillingSettings } from "@/lib/billing";

/**
 * How this academy collects — switched here, never by shipping code (FR-011).
 *
 * Two independent choices, presented as two:
 *
 *   - the MODE says how money arrives and whether a student may owe;
 *   - the CADENCE says how much is settled at once.
 *
 * Options the platform cannot run are shown DISABLED with their reason, not
 * hidden. A missing option reads as a product that does not support it; a greyed
 * one reads as a thing that is coming, which is what is true.
 */
/**
 * Digits typed on an Arabic keyboard are Arabic-Indic, and `Number("٣")` is NaN.
 *
 * Without this the whole list is filtered away and saved as empty — no error,
 * no clue, and alerts that simply stop firing. In an Arabic-only product this is
 * the DEFAULT input, not an edge case.
 */
function parseThresholds(input: string): number[] {
  const latin = input.replace(/[٠-٩۰-۹]/g, (d) =>
    String((d.charCodeAt(0) & 0xf) % 10),
  );

  return latin
    .split(/[,،\s]+/)
    .filter((part) => part !== "")
    .map((part) => Number(part))
    .filter((n) => Number.isInteger(n) && n >= 0);
}

export default function BillingSettingsPage() {
  const [settings, setSettings] = useState<BillingSettings | null>(null);
  const [thresholds, setThresholds] = useState("");
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setFailed(false);

    billing
      .settings()
      .then((res) => {
        setSettings(res);
        setThresholds(res.alert_thresholds.join("، "));
      })
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const save = async (patch: Parameters<typeof billing.updateSettings>[0]) => {
    setSaving(true);
    setError("");
    setErrors({});
    setSaved(false);

    try {
      const res = await billing.updateSettings(patch);
      setSettings(res);
      setThresholds(res.alert_thresholds.join("، "));
      setSaved(true);
    } catch (err: unknown) {
      // 422 lands under its field; everything else — including the Action's own
      // refusal for an unconfigured mode — becomes one readable sentence.
      const fields = fieldErrors(err);
      setErrors(fields);

      if (Object.keys(fields).length === 0) {
        setError(errorMessage(err, "تعذّر حفظ الإعدادات. أعد المحاولة."));
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <RowsSkeleton count={4} />;
  if (failed || settings === null) return <ErrorState onRetry={load} />;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-bold text-ink">إعدادات الفوترة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          يسري أي تغيير هنا على ما بعده فقط — لا يُعاد حساب رصيد قائم ولا يسقط
          مستحقّ سابق.
        </p>
      </header>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر الحفظ">
          {error}
        </Alert>
      )}

      {saved && (
        <Alert tone="success" title="حُفظت الإعدادات">
          يسري النمط الجديد على أوّل حجز بعد الآن.
        </Alert>
      )}

      <Card>
        <div className="space-y-5">
          <SelectField
            id="mode"
            label="نمط التحصيل"
            hint="كيف يصل المال، وهل يُسمح للطالب بالحجز على الحساب."
            value={settings.mode}
            onChange={(value) => save({ mode: value })}
            disabled={saving}
            error={errors.mode}
            options={settings.modes.map((option) => ({
              value: option.value,
              label:
                option.unavailable_reason === null
                  ? option.label
                  : `${option.label} — ${option.unavailable_reason}`,
              disabled: option.unavailable_reason !== null,
            }))}
          />

          <SelectField
            id="cadence"
            label="دورة الدفع"
            hint="كم تُسدَّد دفعة واحدة: حصة، أو نصف شهر، أو شهر."
            value={settings.cadence}
            onChange={(value) => save({ cadence: value })}
            disabled={saving}
            error={errors.cadence}
            options={settings.cadences.map((option) => ({
              value: option.value,
              label:
                option.unavailable_reason === null
                  ? option.label
                  : `${option.label} — ${option.unavailable_reason}`,
              disabled: option.unavailable_reason !== null,
            }))}
          />

          <SelectField
            id="zero_balance_behavior"
            label="عند نفاد الرصيد"
            value={settings.zero_balance_behavior}
            onChange={(value) => save({ zero_balance_behavior: value })}
            disabled={saving}
            error={errors.zero_balance_behavior}
            options={[
              { value: "block", label: "منع الحجز الجديد" },
              { value: "remind", label: "تذكير بالدفع" },
              { value: "both", label: "منع الحجز وتذكير بالدفع" },
            ]}
          />
        </div>
      </Card>

      <Card>
        <div className="space-y-4">
          <TextField
            id="alert_thresholds"
            label="عتبات التنبيه"
            hint="أعداد الحصص المتبقّية التي يُنبَّه عندها، مفصولة بفاصلة. الأولى للطالب والثانية لوليّ أمره."
            value={thresholds}
            onChange={setThresholds}
            disabled={saving}
            error={errors.alert_thresholds ?? errors["alert_thresholds.0"]}
          />

          <Button
            variant="primary"
            loading={saving}
            onClick={() =>
              save({
                alert_thresholds: parseThresholds(thresholds),
              })
            }
          >
            حفظ العتبات
          </Button>
        </div>
      </Card>

      <Card>
        <h2 className="text-base font-semibold text-ink">الأثر الحالي</h2>
        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-ink-muted">الحجز على الحساب</dt>
            <dd className="font-medium text-ink">
              {settings.allows_deferral ? "مسموح حتى الحد" : "غير مسموح"}
            </dd>
          </div>
          <div>
            <dt className="text-ink-muted">ما تسمح به الدورة</dt>
            <dd className="font-medium text-ink">
              <bdi>{settings.cadence_allows_credits.toLocaleString("ar-EG")}</bdi> حصة
            </dd>
            <dd className="mt-1 text-xs text-ink-muted">
              {settings.allows_deferral
                ? "سقفٌ لا يُمنَح إلا بعد موافقة موثّقة على الدفع المؤجَّل."
                : "في الدفع المسبق تختار الدورةُ الحزمةَ المتصدّرة في شاشة الشراء، ولا تمنح سقفاً."}
            </dd>
          </div>
        </dl>
      </Card>
    </div>
  );
}
