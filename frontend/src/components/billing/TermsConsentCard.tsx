"use client";

import { useCallback, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { SectionHeading } from "@/components/ui/SectionHeading";
import { DocumentIcon } from "@/components/icons";
import { errorMessage } from "@/lib/api";
import { billing, type ConsentState } from "@/lib/billing";

/**
 * Agreeing to owe, where the student already is (FR-048 · FR-049).
 *
 * ⚠️ A SECTION ON THE BALANCE SCREEN, NOT A ROUTE OF ITS OWN. What is being
 * accepted is the right to defer payment of these balances, and a page reached
 * from a menu is a page a student signs having forgotten what it was about — and
 * one nobody visits when it matters.
 *
 * ⚠️ AND IT DISAPPEARS WHEN NOTHING IS OUTSTANDING. It reappears by itself when
 * new terms are published, because "outstanding" is computed from the version in
 * force rather than from a flag anybody has to remember to clear.
 *
 * There is no "decline" button. Declining is not signing, which is the state the
 * account is already in; a button that records a refusal would be a second thing
 * to store and would change nothing about what the student may do.
 */
/**
 * ⚠️ WITH `student`, THE SAME CARD SIGNS FOR A CHILD — and only then may a
 * guardian see it. A guardian's own `/billing` must never render it (the
 * signer would become the student, agreeing to owe for themselves); on the
 * child's dashboard it carries the child's uuid, and the server records the
 * guardian as the signer and the child as the one who owes. The server answers
 * the deferred-payment terms alone on that read, and a guardian without the
 * payments permission gets a 403 — which lands in the same silent catch, so the
 * card simply is not there.
 */
export function TermsConsentCard({
  student,
  onAccepted,
}: {
  student?: { uuid: string; name: string };
  onAccepted?: () => void;
} = {}) {
  const studentUuid = student?.uuid;
  const headingId = student === undefined ? "consents-heading" : `consents-heading-${student.uuid}`;
  const [documents, setDocuments] = useState<ConsentState[]>([]);
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(() => {
    billing
      .consents(studentUuid)
      .then((res) => setDocuments(res.data ?? []))
      // Silent: this is a card beside the balances, and a student who cannot
      // read their consent state can still read their credits. It reappears on
      // the next load.
      .catch(() => setDocuments([]));
  }, [studentUuid]);

  useEffect(load, [load]);

  const accept = async (document: ConsentState["document"]) => {
    setBusy(document);
    setError("");

    try {
      const res = await billing.accept(document, studentUuid);
      setDocuments(res.data ?? []);
      onAccepted?.();
    } catch (err: unknown) {
      setError(errorMessage(err, "تعذّر تسجيل الموافقة. أعد المحاولة."));
    } finally {
      setBusy("");
    }
  };

  const outstanding = documents.filter(
    (d) =>
      d.consented_at === null
      // Belt and braces: the child read already answers this document alone,
      // and a guardian here may not sign anything else.
      && (student === undefined || d.document === "deferred_payment_terms"),
  );

  if (outstanding.length === 0) return null;

  return (
    <section aria-labelledby={headingId} className="space-y-4">
      <SectionHeading
        id={headingId}
        Icon={DocumentIcon}
        title={student === undefined ? "موافقات مطلوبة" : `موافقة مطلوبة عن ${student.name}`}
      />

      {error !== "" && (
        <Alert tone="danger" title="تعذّرت العملية">
          {error}
        </Alert>
      )}

      {outstanding.map((document) => (
        <Card as="article" key={document.document}>
          <h4 className="text-base font-semibold text-ink">{document.label}</h4>

          <p className="mt-2 text-sm text-ink-muted">
            {student !== undefined
              ? "بتسجيل موافقتك نيابةً عن ابنك يمكنه حجز حصص قبل شراء رصيدها، على أن ما يأخذه بهذه الطريقة يبقى مستحقاً على حسابه. بلا هذه الموافقة لا يُمنح ابنك أي تأجيل، وتبقى حصصه بالرصيد المشترى مسبقاً كما هي."
              : document.document === "deferred_payment_terms"
              ? "بتسجيل موافقتك يمكنك حجز حصص قبل شراء رصيدها، على أن ما تأخذه بهذه الطريقة يبقى مستحقاً عليك. بلا هذه الموافقة لا يُمنح أي تأجيل، وتبقى الحصص بالرصيد المشترى مسبقاً كما هي."
              : "موافقة على معالجة بياناتك الشخصية داخل المنصة، وهي مستقلّة تماماً عن أي موافقة أخرى."}
          </p>

          {/* The version is shown, not chosen: a new one makes the card appear
              again, and the student can see that what they are signing today is
              not what they signed last term. */}
          <p className="mt-3 text-xs text-ink-muted">
            نسخة الشروط: <bdi>{document.version}</bdi>
          </p>

          <div className="mt-4">
            <Button
              onClick={() => accept(document.document)}
              disabled={busy !== ""}
            >
              {student === undefined
                ? `أوافق على ${document.label}`
                : `أوافق نيابةً عن ${student.name}`}
            </Button>
          </div>
        </Card>
      ))}
    </section>
  );
}
