"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";

import { FieldBoxEditor, type Boxes } from "@/components/certificates/FieldBoxEditor";
import { TemplateGallery } from "@/components/certificates/TemplateGallery";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { TextField } from "@/components/ui/Field";
import { userMessage } from "@/lib/errors";
import type { CertificateValues } from "@/lib/certificate-design";
import {
  certificateDesigns,
  designKey,
  type CertificateDesignCard,
} from "@/lib/certificate-designs";

/**
 * «تصميم الشهادة» — the screen that chooses what every certificate this workspace
 * issues is drawn on, and where its six fields sit.
 *
 * ⚠️ IT IS LINKED FROM `manage/certificates`. A screen reachable only by typing
 * its address is not shipped (`FR-039` · SC-009) — and this whole feature was born
 * from that rule being broken: five `/certificate-templates` routes with a table,
 * a model, a controller and a resource behind them, and not one caller anywhere
 * under `frontend/src`.
 *
 * ⚠️ AND CHANGING THE DESIGN RE-DRAWS CERTIFICATES ALREADY ISSUED (`FR-014`).
 * The design is read live at every verification while the six facts are frozen at
 * issue, so nothing is re-issued and nothing already printed stops verifying.
 */
const SAMPLE: CertificateValues = {
  student: "عبد الرحمن محمد الشريف الخطيب",
  subject: "الرياضيات",
  teacher: "سامي عبد الله",
  date: "١٤ مايو ٢٠٢٦",
  number: "CERT-2026-A1B2C3D4",
  verifyUrl: "https://mteatch.example/certificates/verify/SAMPLE",
};

interface Editing {
  card: CertificateDesignCard;
  boxes: Boxes;
}

export default function CertificateDesignPage() {
  const [designs, setDesigns] = useState<CertificateDesignCard[]>([]);
  const [limit, setLimit] = useState(0);
  const [used, setUsed] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    certificateDesigns
      .list()
      .then((res) => {
        setDesigns(res.data ?? []);
        setLimit(res.upload_limit ?? 0);
        setUsed(res.uploads_used ?? 0);
      })
      // ⚠️ Never a raw error: `userMessage()` is the one translator.
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const select = useCallback(
    (card: CertificateDesignCard) => {
      setBusyKey(designKey(card));
      setError(null);

      // A shipped template with no row yet is adopted (which selects it); a row
      // that already exists is selected through the same Action on the server.
      const request =
        card.uuid === null
          ? certificateDesigns.adopt(card.system_key ?? "")
          : certificateDesigns.select(card.uuid);

      request
        // Re-read rather than patch in place: the switch clears the PREVIOUS row's
        // selection on the server, and a client that flips one flag locally shows
        // two selected designs until the next refresh.
        .then(() => load())
        .catch((cause) => setError(userMessage(cause)))
        .finally(() => setBusyKey(null));
    },
    [load],
  );

  const adjust = useCallback(
    (card: CertificateDesignCard) => {
      setError(null);

      /*
        ⚠️ AN UNADJUSTED UPLOAD STARTS FROM THE SHIPPED DEFAULT'S OWN POSITIONS,
        READ OUT OF THE GALLERY — never from a copy of those numbers written here.
        The registry is the single source of them; a second spelling in TypeScript
        drifts the day anybody nudges a box in the PHP one, and the drift is
        invisible because both sides look right on their own.
      */
      const starting = card.boxes ?? designs.find((row) => row.boxes !== null)?.boxes ?? null;

      if (starting === null) return;

      if (card.boxes === null) {
        setError("هذا التصميم بلا مواضع بعد — اسحب الحقول الستّة ثمّ احفظ.");
      }

      setEditing({ card, boxes: starting });
    },
    [designs],
  );

  /*
    ⚠️ THE EDITOR OPENS ON THE UPLOAD ITSELF (`FR-044`). The row arrives with no
    field positions and cannot be selected until it has some — sending the teacher
    back to a gallery to find their own new card is the step in which they leave.
  */
  const upload = useCallback(
    (file: File, name: string) => {
      setBusyKey("upload");
      setError(null);

      certificateDesigns
        .upload(file, name)
        .then((card) => {
          load();
          adjust(card);
        })
        .catch((cause) => setError(userMessage(cause)))
        .finally(() => setBusyKey(null));
    },
    [adjust, load],
  );

  const remove = useCallback(
    (card: CertificateDesignCard) => {
      if (card.uuid === null) return;

      setBusyKey(designKey(card));
      setError(null);

      certificateDesigns
        .remove(card.uuid)
        .then(() => load())
        .catch((cause) => setError(userMessage(cause)))
        .finally(() => setBusyKey(null));
    },
    [load],
  );

  const save = useCallback(() => {
    if (editing === null || editing.card.uuid === null) return;

    setSaving(true);
    setError(null);

    certificateDesigns
      .saveBoxes(editing.card.uuid, editing.boxes)
      .then(() => {
        setEditing(null);
        load();
      })
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setSaving(false));
  }, [editing, load]);

  const reset = useCallback(() => {
    if (editing === null || editing.card.uuid === null) return;

    setSaving(true);
    setError(null);

    certificateDesigns
      .resetBoxes(editing.card.uuid)
      .then(() => {
        setEditing(null);
        load();
      })
      .catch((cause) => setError(userMessage(cause)))
      .finally(() => setSaving(false));
  }, [editing, load]);

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-ink">تصميم الشهادة</h1>
        <p className="mt-1 text-sm text-ink-muted">
          اختر القالب الذي تُرسم عليه شهادات طلابك واضبط مواضع الحقول عليه. التغيير يسري على
          الشهادات الصادرة أيضاً — لا يُعاد إصدار شيء، ولا يتغيّر اسم ولا تاريخ.
        </p>
      </header>

      {error !== null && <Alert tone="danger" title={error} />}

      {/*
        ⚠️ SAID OUT LOUD, because it was asked the first time somebody looked at
        this screen: the names in a preview are INVENTED. The student's is
        deliberately long — the shrinking is the feature, and it has to be visible
        before the choice rather than after — but with nothing saying so, a teacher
        reads «سامي عبد الله» beside their own certificate's «Demo Teacher» and
        reasonably concludes the product has the wrong name in it.
      */}
      {editing === null && (
        <p className="text-sm text-ink-muted">
          الأسماء في المعاينة نموذجيّة وليست بيانات شهادة حقيقية — اسم الطالب فيها طويل عمداً
          لترى كيف يصغر الخط حتى لا يتجاوز إطاره. الشهادة الحقيقية تحمل الأسماء المسجَّلة عليها
          يوم صدورها.
        </p>
      )}

      {editing !== null ? (
        <section className="space-y-4">
          <h2 className="text-base font-semibold text-ink">
            مواضع الحقول — {editing.card.name ?? "تصميم"}
          </h2>
          <p className="text-sm text-ink-muted">
            اسحب أيّ حقل إلى موضعه على الصورة. المعاينة هنا هي الشهادة نفسها، بالاسم الأطول الذي قد
            يصل.
          </p>

          <FieldBoxEditor
            imageUrl={editing.card.image_url}
            boxes={editing.boxes}
            values={SAMPLE}
            onChange={(update) =>
              setEditing((current) =>
                current === null ? current : { ...current, boxes: update(current.boxes) },
              )
            }
          />

          <div className="flex flex-wrap gap-2">
            <Button loading={saving} loadingLabel="جارٍ الحفظ…" onClick={save}>
              احفظ المواضع
            </Button>

            {editing.card.system_key !== null && (
              <Button variant="ghost" onClick={reset}>
                أعِدْ إلى مواضع القالب الأصليّة
              </Button>
            )}

            <Button variant="ghost" onClick={() => setEditing(null)}>
              إلغاء
            </Button>
          </div>
        </section>
      ) : loading && designs.length === 0 ? (
        <p className="text-sm text-ink-muted">جارٍ تحميل القوالب…</p>
      ) : designs.length === 0 ? (
        <div className="space-y-3">
          <p className="text-sm text-ink-muted">لا قوالب متاحة.</p>
          <Button variant="ghost" onClick={load}>
            أعد المحاولة
          </Button>
        </div>
      ) : (
        <>
          <TemplateGallery
            designs={designs}
            busyKey={busyKey}
            onSelect={select}
            onAdjust={adjust}
            onDelete={remove}
          />

          <UploadPanel
            used={used}
            limit={limit}
            busy={busyKey === "upload"}
            onUpload={upload}
          />
        </>
      )}

      <p className="text-sm">
        <Link href="/manage/certificates" className="text-primary-ink underline-offset-4 hover:underline">
          العودة إلى شهادات الطلاب
        </Link>
      </p>
    </div>
  );
}

/**
 * Uploading your own artwork.
 *
 * ⚠️ THE COUNTER IS SHOWN BEFORE THE REFUSAL, not after it (`FR-046`). A limit a
 * teacher only meets by hitting it is a limit that reads as a bug.
 */
function UploadPanel({
  used,
  limit,
  busy,
  onUpload,
}: {
  used: number;
  limit: number;
  busy: boolean;
  onUpload: (file: File, name: string) => void;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [name, setName] = useState("");

  const full = used >= limit;

  return (
    <section className="space-y-3 rounded-3xl border border-line bg-surface-raised p-6">
      <h2 className="text-base font-semibold text-ink">ارفع تصميمك</h2>
      <p className="text-sm text-ink-muted">
        استعملتَ {used} من {limit} تصاميم. يُعاد ترميز الصورة على الخادم، ثمّ تضبط مواضع الحقول
        عليها قبل اعتمادها.
      </p>

      <div className="flex flex-wrap items-end gap-3">
        {/* From the `Field` family, not a bare input — appearance is a closed set. */}
        <div className="min-w-56">
          <TextField
            id="design-name"
            label="اسم التصميم"
            value={name}
            maxLength={80}
            disabled={full}
            onChange={setName}
          />
        </div>

        {/*
          ⚠️ THE FILE INPUT STAYS RAW, and deliberately: `components/ui/` has no
          file control, and inventing one for a single screen is a second design
          system's worth of surface for one button. The label carries the `htmlFor`
          the family exists to enforce.
        */}
        <label className="text-sm text-ink" htmlFor="design-image">
          <span className="mb-1 block text-xs text-ink-muted">الصورة</span>
          <input
            id="design-image"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            disabled={full}
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            className="text-sm text-ink"
          />
        </label>

        <Button
          disabled={full || file === null}
          loading={busy}
          loadingLabel="جارٍ الرفع…"
          onClick={() => file !== null && onUpload(file, name.trim() === "" ? "تصميمي" : name.trim())}
        >
          ارفع
        </Button>
      </div>

      {full && (
        <p className="text-sm text-ink-muted">بلغتَ الحدّ الأعلى — احذف تصميماً قبل رفع آخر.</p>
      )}
    </section>
  );
}
