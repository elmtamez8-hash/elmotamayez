"use client";

import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { userMessage } from "@/lib/errors";
import { gamification } from "@/lib/gamification";

/**
 * Claim a reward.
 *
 * ⚠️ IT REFUSES ITS OWN SECOND CLICK, and that is the whole reason this is a
 * component rather than three lines inline. The same family of defect shipped in
 * spec 008 — a second tap turning a right answer into a zero on a graded paper —
 * and here it would take a student's coins twice for one reward. The server's
 * claim is atomic and would refuse the second attempt on stock, but "the server
 * catches it" is not an argument for sending it: the second request is a second
 * chance to be told something confusing.
 *
 * ⚠️ AND THE REFUSAL IS A SENTENCE, NOT A FIELD ERROR. The 422 carries
 * `{message, code}` rather than a validation-errors shape, so it goes through
 * `userMessage()` — `fieldErrors()` would find nothing and render nothing.
 */
export function RedeemButton({
  rewardUuid,
  disabled = false,
  onRedeemed,
}: {
  rewardUuid: string;
  disabled?: boolean;
  onRedeemed?: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const redeem = () => {
    if (busy) return;

    setBusy(true);
    setError(null);

    gamification
      .redeem(rewardUuid)
      .then(() => onRedeemed?.())
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setBusy(false));
  };

  return (
    <div className="space-y-1">
      <Button onClick={redeem} disabled={busy || disabled}>
        {busy ? "جارٍ الاستبدال…" : "استبدل"}
      </Button>
      {error !== null && (
        <p role="alert" className="text-sm text-danger">
          {error}
        </p>
      )}
    </div>
  );
}
