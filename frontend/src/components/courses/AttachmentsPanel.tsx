"use client";

import { useState } from "react";
import { AssetRow, AssetUploader } from "./editors/AssetUploader";
import { Alert } from "@/components/ui/Alert";
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

  const remove = async (asset: MediaAsset) => {
    if (!window.confirm(`سيُحذف «${asset.original_filename}» نهائياً. متابعة؟`)) return;

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
              onRemove={() => void remove(attachment)}
            />
          ))}
        </div>
      )}

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
