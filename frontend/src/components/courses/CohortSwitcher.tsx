"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SelectField, TextareaField } from "@/components/ui/Field";
import { cohorts, type CohortsForCourse } from "@/lib/cohorts";
import { userMessage } from "@/lib/errors";
import { formatCohortSlot } from "@/lib/session-format";
import { useViewerTimeZone } from "@/lib/viewer-time-zone";

/**
 * «أنت في السبت ٤م» — and the way to ask for a different one (FR-028هـ).
 *
 * ⚠️ ASKING CHANGES NOTHING (FR-028و). The student stays in their group with
 * every right intact — timetable, seats, thread — until somebody approves; this
 * component says so in as many words, because a screen that went quiet after the
 * submit reads as though the move already happened, and the student stops
 * turning up on Saturday.
 *
 * ⚠️ AND A REFUSAL IS SHOWN WITH ITS REASON. `decision_reason` is mandatory on a
 * rejection precisely so it can be read here: a request that vanished with no
 * explanation reads as a fault and is submitted again for ever.
 */

export function CohortSwitcher({
  state,
  onChanged,
}: {
  state: CohortsForCourse;
  onChanged: () => void;
}) {
  const zone = useViewerTimeZone();
  // The destination's meeting times on the student's own clock (2026-09-26);
  // the platform-zone labels when the payload carries no instants.
  const scheduleText = (labels: string[], slots: string[] | undefined): string | null => {
    const items = slots !== undefined && slots.length > 0 ? slots.map((at) => formatCohortSlot(at, zone)) : labels;

    return items.length > 0 ? items.join(" · ") : null;
  };
  const [target, setTarget] = useState("");
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { membership, pending_request: pending } = state;

  /*
    ⚠️ TRUTHINESS, NOT `=== null`. This is fed by a side read that is allowed to
    fail, and a malformed or half-loaded body would slip past a null check and
    take the whole course page down on `state.cohorts.filter` — a group control
    blanking the lessons is the tail wagging the dog.
  */
  if (!membership) return null;

  /*
    ⛔ 036 · T118 · FR-019 — THE DESTINATIONS COME FROM THEIR OWN READ, NOT FROM
    THE PICKER LIST FILTERED. `state.cohorts` answers «which group may I JOIN»
    and now DROPS any group no live price reaches, and `is_joinable` on those
    rows asks the price as well — so «open, has room, not on sale», the one case
    FR-019 exists to mark, was deleted twice over before this component saw it.
    The requirement was unimplementable from here, not merely unimplemented.

    ⚠️ Truthiness, like the membership check above: this is fed by a side read
    that is allowed to fail, and a half-loaded body must not take the course page
    down on `.filter`.
  */
  const elsewhere = (state.transfer_destinations ?? []).filter(
    (option) => option.uuid !== membership.cohort_uuid,
  );

  const submit = () => {
    if (target === "") return;

    setBusy(true);
    setError(null);

    cohorts
      .requestTransfer(target, reason.trim() === "" ? undefined : reason.trim())
      .then(() => {
        setTarget("");
        setReason("");
        onChanged();
      })
      .catch((e: unknown) => setError(userMessage(e)))
      .finally(() => setBusy(false));
  };

  const withdraw = () => {
    if (pending === null) return;

    setBusy(true);
    setError(null);

    cohorts
      .withdrawRequest(pending.uuid)
      .then(onChanged)
      .catch((e: unknown) => setError(userMessage(e)))
      .finally(() => setBusy(false));
  };

  return (
    <Card padding="sm">
      <div className="space-y-3">
        <p className="text-sm text-ink">
          مجموعتك: <span className="font-semibold">{membership.cohort_name}</span>
        </p>

        {error !== null && <Alert tone="danger" title="تعذّر تنفيذ الطلب">{error}</Alert>}

        {pending !== null ? (
          <div className="space-y-2">
            {/*
              ⚠️ «ما زلت في مجموعتك» IS THE LOAD-BEARING HALF OF THIS SENTENCE.
              Without it a waiting student reads the request as done and stops
              attending the group they are still in.
            */}
            <Alert tone="info" title="طلب انتقال قيد المراجعة">
              طلبك للانتقال إلى «{pending.to_cohort?.name ?? "مجموعة أخرى"}» قيد المراجعة. ما زلت في مجموعتك
              الحالية بكامل حقوقك حتى يُبتّ فيه.
            </Alert>

            <Button onClick={withdraw} variant="ghost" size="sm" loading={busy} loadingLabel="جارٍ السحب">
              اسحب الطلب
            </Button>
          </div>
        ) : elsewhere.length === 0 ? (
          <p className="text-xs text-ink-muted">لا توجد مجموعة أخرى مفتوحة للانتقال إليها.</p>
        ) : (
          <div className="space-y-3">
            <SelectField
              label="الانتقال إلى"
              id="cohort-target"
              value={target}
              onChange={setTarget}
              options={[
                { value: "", label: "اختر مجموعة" },
                ...elsewhere.map((option) => ({
                  value: option.uuid,
                  /*
                    ⚠️ MARKED, NOT HIDDEN (FR-019). A group that is open and has
                    room but is not on sale is a destination an officer can still
                    approve — dropping it makes the question unaskable, while
                    saying so lets the student ask knowing what they are asking
                    for. The words are on the option itself because a legend
                    beside a closed `<select>` is a legend nobody reads.
                  */
                  label: [
                    option.name,
                    scheduleText(option.schedule_preview, option.schedule_slots),
                    option.is_on_sale ? null : "غير معروضة للبيع",
                  ]
                    .filter((part): part is string => part !== null)
                    .join(" — "),
                })),
              ]}
            />

            <TextareaField
              label="سبب الطلب (اختياري)"
              id="cohort-reason"
              value={reason}
              onChange={setReason}
              rows={2}
            />

            <Button
              onClick={submit}
              disabled={target === ""}
              loading={busy}
              loadingLabel="جارٍ الإرسال"
              size="sm"
            >
              اطلب الانتقال
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
}
