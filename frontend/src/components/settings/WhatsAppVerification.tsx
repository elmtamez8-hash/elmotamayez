"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { PhoneInput } from "@/components/ui/PhoneInput";
import { TextField } from "@/components/ui/Field";
import { errorMessage, fieldErrors } from "@/lib/api";
import { contactVerification } from "@/lib/notifications";

/**
 * Two steps, one purpose: prove the number belongs to this account.
 *
 * Nothing reaches a phone until this succeeds. The channel asks whether the
 * recipient has a VERIFIED number and refuses otherwise — so without this screen
 * every WhatsApp delivery on the platform is recorded "skipped" for ever, and
 * the tick boxes on the settings grid promise something that never arrives.
 */
export function WhatsAppVerification() {
  const [dial, setDial] = useState("+974");
  const [number, setNumber] = useState("");
  const [uuid, setUuid] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState("");
  const [verified, setVerified] = useState(false);

  const fail = (err: unknown, fallback: string) => {
    // 422 lands under its field; everything else goes through the Arabic table.
    // A raw upstream sentence on a screen is the one rule this product does not
    // break.
    const fields = fieldErrors(err);
    setErrors(fields);
    if (Object.keys(fields).length === 0) setNotice(errorMessage(err, fallback));
  };

  const request = async () => {
    setBusy(true);
    setErrors({});
    setNotice("");

    try {
      const issued = await contactVerification.request("whatsapp", `${dial}${number}`);
      setUuid(issued.uuid);
      setNotice("أرسلنا رمزاً من ستة أرقام على واتساب. ينتهي خلال عشر دقائق.");
    } catch (err) {
      fail(err, "تعذّر إرسال رمز التأكيد.");
    } finally {
      setBusy(false);
    }
  };

  const confirm = async () => {
    if (!uuid) return;

    setBusy(true);
    setErrors({});
    setNotice("");

    try {
      await contactVerification.confirm(uuid, code);
      setVerified(true);
    } catch (err) {
      fail(err, "تعذّر تأكيد الرمز.");
    } finally {
      setBusy(false);
    }
  };

  if (verified) {
    return <Alert tone="success" title="تم تأكيد رقم واتساب. ستصلك الإشعارات المُختارة عليه." />;
  }

  return (
    <div className="space-y-4">
      {notice && <Alert tone="info" title={notice} />}

      {uuid === null ? (
        <>
          <PhoneInput
            id="whatsapp_number"
            dial={dial}
            number={number}
            onDialChange={setDial}
            onNumberChange={setNumber}
            error={errors.contact_value}
          />
          <Button onClick={request} loading={busy} loadingLabel="جارٍ الإرسال…">
            أرسِل رمز التأكيد
          </Button>
        </>
      ) : (
        <>
          <TextField
            id="whatsapp_code"
            label="رمز التأكيد"
            value={code}
            onChange={setCode}
            maxLength={6}
            error={errors.code}
            hint="ستة أرقام وصلتك على واتساب."
          />
          <div className="flex gap-2">
            <Button onClick={confirm} loading={busy} loadingLabel="جارٍ التأكيد…">
              أكّد الرقم
            </Button>
            <Button variant="secondary" onClick={() => setUuid(null)} disabled={busy}>
              تغيير الرقم
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
