"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField } from "@/components/ui/Field";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { ClockIcon } from "@/components/icons";
import { auth } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { userMessage } from "@/lib/errors";
import { timezonePlace } from "@/lib/timezone-names";
import { setStoredViewerTimeZone, useViewerTimeZone } from "@/lib/viewer-time-zone";

/** The two countries the product serves, first; then every zone the runtime knows. */
const FIRST = ["Asia/Qatar", "Africa/Cairo"];

/**
 * Qatar and Egypt first; then every zone with an Arabic name, in Arabic
 * alphabetical order; then the rest under their IANA names, in the runtime's
 * order. A reader in Amman finds «الأردن — عمّان» among a few dozen Arabic
 * lines instead of scanning four hundred English ones.
 */
export function timezoneOptions(all: readonly string[] = supportedZones()): Array<{ value: string; label: string }> {
  const rest = all.filter((zone) => !FIRST.includes(zone));
  const named: Array<{ value: string; label: string }> = [];
  const unnamed: Array<{ value: string; label: string }> = [];

  for (const zone of rest) {
    const place = timezonePlace(zone);

    if (place === null) {
      unnamed.push({ value: zone, label: zone });
    } else {
      named.push({ value: zone, label: place });
    }
  }

  named.sort((a, b) => a.label.localeCompare(b.label, "ar"));

  return [
    ...FIRST.map((zone) => ({ value: zone, label: timezonePlace(zone) ?? zone })),
    ...named,
    ...unnamed,
  ];
}

function supportedZones(): string[] {
  try {
    const list = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.("timeZone");

    return list && list.length > 0 ? list : FIRST;
  } catch {
    return FIRST;
  }
}

/**
 * «منطقتي الزمنية» — the clock every time in the product is shown on (owner
 * decision 2026-09-26).
 *
 * ⚠️ A CHOICE HERE IS THE SOURCE OF TRUTH. It is saved as `manual`, and after
 * that neither the sign-in stamp nor the quiet-hours form may replace it with
 * the browser's zone — both are refused on the server, not only skipped here.
 *
 * ⚠️ AND IT TAKES EFFECT AT ONCE. The viewer-zone store is updated before the
 * account is re-read, so every time on the screen is redrawn on the new clock
 * without a reload.
 */
export function TimezoneCard() {
  const { user, refreshUser } = useAuth();
  const current = useViewerTimeZone();
  const [zone, setZone] = useState(user?.timezone ?? current);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setSaved(false);
    setError(null);

    try {
      await auth.setTimezone(zone, "manual");
      setStoredViewerTimeZone(zone);
      setSaved(true);
      await refreshUser();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card as="section">
      <div className="mb-4">
        <SectionHeading
          id="settings-timezone"
          Icon={ClockIcon}
          title="المنطقة الزمنية"
          description="كلّ المواعيد في المنصّة تُعرَض بهذه المنطقة، ومنها مواعيد الإشعارات."
        />
      </div>
      <form onSubmit={submit} className="space-y-4">
        {error && <Alert tone="danger" title={error} />}
        {saved && <Alert tone="success" title="حُفِظت منطقتك الزمنية." />}

        <SelectField
          id="timezone"
          label="منطقتي الزمنية"
          value={zone}
          onChange={setZone}
          options={timezoneOptions()}
          hint={
            user?.timezone_source === "manual"
              ? "اخترتَها بنفسك، فلا تتغيّر تلقائياً."
              : "مأخوذة من جهازك حتى تختار بنفسك."
          }
        />

        <Button type="submit" loading={saving} loadingLabel="جارٍ الحفظ…">
          احفظ المنطقة
        </Button>
      </form>
    </Card>
  );
}
