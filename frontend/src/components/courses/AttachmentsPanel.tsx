"use client";

import { useState } from "react";
import { AssetRow, AssetUploader } from "./editors/AssetUploader";
import { Alert } from "@/components/ui/Alert";
import { Modal } from "@/components/ui/Modal";
import { userMessage } from "@/lib/errors";
import { media, type MediaAsset } from "@/lib/media";

/**
 * Files beside the item, whatever the item is (FR-019).
 *
 * A worksheet under a video, the slides under an article, a reading list under a
 * notice. Separate from the item's own file rather than one list with a role
 * flag, because they answer different questions — "what is this item" and "what
 * comes with it" — and merging them makes the first a filter every caller has to
 * remember.
 *
 * Attachments are the one place a kind is unconstrained: an article has no
 * primary file at all, and constraining its attachments would be constraining
 * the feature.
 */
export function AttachmentsPanel({
  lessonUuid,
  attachments,
  onChanged,
}: {
  lessonUuid: string;
  attachments: MediaAsset[];
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  /*
    The attachment whose deletion is waiting on an answer (spec 033).

    ⚠️ ASKED IN OUR OWN WINDOW, never in the browser's. `window.confirm` renders
    English buttons on some Arabic Android builds, lays itself out
    left-to-right inside a right-to-left page, and freezes everything while it
    is open — and it asked a permanent deletion in exactly the same grey box the
    same screen used to ask for a new item's title.
  */
  const [pending, setPending] = useState<MediaAsset | null>(null);

  const remove = async (asset: MediaAsset) => {
    setBusy(true);
    setError("");

    try {
      await media.remove(asset.uuid);
      onChanged();
    } catch (err: unknown) {
      // Deleting an asset needs two-factor verification (004). The refusal that
      // comes back says so; it is not a failure to retry.
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="space-y-3">
      <div>
        <h4 className="text-sm font-semibold text-ink">مرفقات</h4>
        <p className="text-xs text-ink-muted">
          ملفات تُعرض بجانب العنصر ولا تُحتسب في تقدّم الطالب — ورقة عمل، شرائح، قائمة قراءة.
        </p>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر حذف المرفق">
          {error}
        </Alert>
      )}

      {attachments.length > 0 && (
        <div className="space-y-2">
          {attachments.map((attachment) => (
            <AssetRow
              key={attachment.uuid}
              asset={attachment}
              busy={busy}
              onRemove={() => {
                setError("");
                setPending(attachment);
              }}
            />
          ))}
        </div>
      )}

      <Modal
        open={pending !== null}
        title="تأكيد الحذف"
        message={
          pending === null ? undefined : `سيُحذف «${pending.original_filename}» نهائياً. متابعة؟`
        }
        confirmLabel="احذف"
        tone="danger"
        busy={busy}
        onCancel={() => setPending(null)}
        onConfirm={() => {
          if (pending === null) return;

          const asset = pending;

          setPending(null);
          void remove(asset);
        }}
      />

      <AssetUploader
        lessonUuid={lessonUuid}
        kind="document"
        role="attachment"
        label="أضف مرفقاً"
        hint="لا حدّ لعدد المرفقات. حذف أيٍّ منها يتطلّب تحقّقاً بخطوتين، لأنه يُتلف ملفاً رفعته."
        disabled={busy}
        onUploaded={onChanged}
      />
    </section>
  );
}
