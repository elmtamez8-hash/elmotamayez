"use client";

import { useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { userMessage } from "@/lib/errors";
import { media, type MediaAsset, type MediaKind, type MediaRole } from "@/lib/media";

/**
 * The three-step upload, in one place.
 *
 * Ask for a ticket, send the bytes wherever it points, then ask the server what
 * actually arrived. The middle step goes straight to a commercial provider when
 * there is one, so gigabytes never pass through the API — which is exactly why
 * the third step exists: the only account of what landed that can be trusted is
 * the server's, read from the file's own bytes rather than its name.
 *
 * Shared by the document, audio and attachment surfaces because the sequence is
 * identical for all three; only `kind` and `role` differ.
 */

const ACCEPT: Record<MediaKind, string> = {
  video: "video/*",
  audio: "audio/*",
  document: ".pdf,.doc,.docx,.ppt,.pptx,.png,.jpg,.jpeg",
};

export function AssetUploader({
  lessonUuid,
  kind,
  role,
  label,
  hint,
  disabled,
  onUploaded,
}: {
  lessonUuid: string;
  kind: MediaKind;
  role: MediaRole;
  label: string;
  hint?: string;
  disabled?: boolean;
  onUploaded: (asset: MediaAsset) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const inputId = `upload-${role}-${kind}-${lessonUuid}`;

  const upload = async (file: File) => {
    setError("");
    setBusy(true);

    try {
      const { asset: reserved, upload: ticket } = await media.requestUpload(lessonUuid, {
        original_filename: file.name,
        size_bytes: file.size,
        kind,
        role,
      });

      await media.uploadTo(ticket, file);

      // The type is read here from the file's own bytes. A zip named .pdf is
      // refused at this step, not accepted on the strength of its name — and
      // the refusal names the kind that was expected.
      onUploaded(await media.complete(reserved.uuid));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-2">
      {error !== "" && (
        <Alert tone="danger" title="تعذّر الرفع">
          {error}
        </Alert>
      )}

      <label htmlFor={inputId} className="block text-sm font-medium text-ink">
        {label}
      </label>

      <input
        id={inputId}
        type="file"
        accept={ACCEPT[kind]}
        disabled={disabled || busy}
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (file !== undefined) void upload(file);
          // Cleared so re-picking the same file after a failure still fires.
          event.target.value = "";
        }}
        className="w-full rounded-xl border border-line bg-surface-raised p-2 text-sm text-ink file:me-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-1.5 file:text-sm file:text-primary-ink disabled:opacity-60"
      />

      {hint !== undefined && <p className="text-xs text-ink-muted">{hint}</p>}

      {busy && (
        <p className="text-xs text-ink-muted" role="status">
          جارٍ الرفع… لا تغلق الصفحة.
        </p>
      )}
    </div>
  );
}

/** One uploaded file, with what a teacher needs to know about it. */
export function AssetRow({
  asset,
  busy,
  onRemove,
}: {
  asset: MediaAsset;
  busy?: boolean;
  onRemove?: () => void;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface-raised px-3 py-2">
      <Badge
        tone={asset.status === "ready" ? "success" : asset.status === "failed" ? "danger" : "info"}
      >
        {asset.status_label}
      </Badge>
      <span className="text-sm text-ink">{asset.original_filename}</span>
      <span className="text-xs text-ink-muted">{asset.kind_label}</span>

      {/* A failed upload says why, and stays visible. An item whose upload broke
          must not be left quietly claiming it has a file. */}
      {asset.failure_reason !== null && (
        <span className="text-xs text-danger-ink">{asset.failure_reason}</span>
      )}

      {onRemove !== undefined && (
        <span className="ms-auto">
          <Button variant="ghost" size="sm" disabled={busy} onClick={onRemove}>
            حذف
          </Button>
        </span>
      )}
    </div>
  );
}
