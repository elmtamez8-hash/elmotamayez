"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { userMessage } from "@/lib/errors";
import { formatMinorMoney } from "@/lib/labels";
import { store, type AppliedDiscount, type CouponSubjectKind } from "@/lib/store";

/**
 * A discount code, and the line that says what it did (spec 011 · FR-011).
 *
 * ⚠️ THE PREVIEW IS ASKED ON SUBMIT, NEVER ON KEYSTROKE. `/billing/coupons/preview`
 * carries the tightest rate limiter in the product — ten a minute — because it
 * is the one endpoint whose whole purpose is to be guessed at. A per-keystroke
 * lookup would lock an honest buyer out on the seventh character of their own
 * code.
 *
 * ⚠️ THE REFUSAL IS SHOWN AS THE SERVER WORDED IT, and it does not distinguish
 * «no such code» from «not for this» — `code` is unique platform-wide while the
 * workspace narrows who may spend it, so a friendlier message would tell whoever
 * typed it that a real code exists at another teacher's. Nothing is composed
 * here; `userMessage()` turns anything else into a sentence.
 *
 * ⚠️ AND THE DISCOUNT LINE IS NOT THE COMMITMENT. Nothing is claimed by a
 * preview — no ceiling moves — so the amount the purchase comes back with is the
 * one that counts. That is why the code travels to the server on the purchase
 * request too, rather than the screen sending an amount it worked out itself.
 */
export function CouponField({
  kind,
  uuid,
  currency,
  onApplied,
}: {
  kind: CouponSubjectKind;
  uuid: string;
  currency: string;
  /**
   * The applied discount, or `null` once the buyer clears it — and the CODE
   * beside it.
   *
   * ⚠️ THE CODE IS REPORTED SEPARATELY BECAUSE THE PAYLOAD DOES NOT CARRY IT.
   * `AppliedDiscount` deliberately sends no coupon detail — no code, no ceiling,
   * no remaining count — since a preview that echoed them would be an
   * enumeration tool on the one endpoint built to be guessed at. It is `null` for
   * a family discount, which has no code by definition.
   */
  onApplied: (discount: AppliedDiscount | null, code: string | null) => void;
}) {
  const [code, setCode] = useState("");
  const [applied, setApplied] = useState<AppliedDiscount | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function apply() {
    if (code.trim() === "") return;

    setBusy(true);
    setProblem(null);

    try {
      const discount = await store.previewDiscount({ kind, uuid, code: code.trim() });

      setApplied(discount);
      onApplied(discount, discount.source === "coupon" ? code.trim() : null);
    } catch (error) {
      setApplied(null);
      onApplied(null, null);
      setProblem(userMessage(error));
    } finally {
      setBusy(false);
    }
  }

  function clear() {
    setCode("");
    setApplied(null);
    setProblem(null);
    onApplied(null, null);
  }

  return (
    <div className="space-y-2">
      <div className="flex items-end gap-2">
        <div className="grow">
          <TextField
            id="coupon_code"
            label="كود خصم (اختياري)"
            value={code}
            onChange={setCode}
            maxLength={32}
            disabled={busy || applied !== null}
            error={problem ?? undefined}
          />
        </div>

        {applied === null ? (
          <Button
            type="button"
            variant="secondary"
            // Not `type="submit"`: this control sits inside the purchase form,
            // and a default-typed button there submits the ORDER on Enter.
            onClick={apply}
            disabled={busy || code.trim() === ""}
          >
            {busy ? "…" : "تطبيق"}
          </Button>
        ) : (
          <Button type="button" variant="ghost" onClick={clear}>
            إزالة
          </Button>
        )}
      </div>

      {applied !== null && applied.discount_minor > 0 && (
        <p className="text-sm font-medium text-secondary-ink">
          {applied.label ?? "خصم"} — {formatMinorMoney(applied.discount_minor, currency)}
        </p>
      )}
    </div>
  );
}
