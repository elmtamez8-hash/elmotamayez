"use client";

import Link from "next/link";
import { use, useState } from "react";

import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { userMessage } from "@/lib/errors";
import { media, type Caption, type MediaAsset } from "@/lib/media";

/**
 * Uploading a lesson's video.
 *
 * Three steps that never change with the provider: ask for a ticket, send the
 * bytes wherever it points, then ask the server what actually arrived. The
 * middle step goes straight to a commercial provider when there is one, so
 * gigabytes never pass through the API.
 */
export default function ManageLessonAssetPage({
  params,
}: {
  params: Promise<{ uuid: string; lessonUuid: string }>;
}) {
  const { uuid, lessonUuid } = use(params);

  const [asset, setAsset] = useState<MediaAsset | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const upload = async (file: File) => {
    setError("");
    setBusy(true);

    try {
      const { asset: reserved, upload: ticket } = await media.requestUpload(
        lessonUuid,
        { original_filename: file.name, size_bytes: file.size },
      );

      await media.uploadTo(ticket, file);

      // The server reads the type from the file's own bytes here. A zip named
      // .mp4 is rejected at this step, not accepted on the strength of its name.
      setAsset(await media.complete(reserved.uuid));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (asset === null) return;

    setBusy(true);
    try {
      await media.remove(asset.uuid);
      setAsset(null);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-bold text-ink">فيديو الدرس</h1>
        <Link
          href={`/manage/courses/${uuid}`}
          className="text-sm text-primary-ink underline"
        >
          العودة إلى الكورس
        </Link>
      </div>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر الرفع">
          {error}
        </Alert>
      )}

      <Card>
        <div className="flex flex-col gap-4">
          {asset === null ? (
            <p className="text-sm text-ink-muted">
              لا يوجد فيديو لهذا الدرس بعد.
            </p>
          ) : (
            <div className="flex flex-wrap items-center gap-3">
              <Badge tone={asset.status === "ready" ? "success" : asset.status === "failed" ? "danger" : "info"}>
                {asset.status_label}
              </Badge>
              <span className="text-sm text-ink">{asset.original_filename}</span>
              {asset.failure_reason !== null && (
                <span className="text-sm text-danger-ink">{asset.failure_reason}</span>
              )}
            </div>
          )}

          <label className="flex flex-col gap-2 text-sm text-ink">
            <span>اختر ملف الفيديو</span>
            <input
              type="file"
              accept="video/*"
              disabled={busy}
              onChange={(event) => {
                const file = event.target.files?.[0];
                if (file !== undefined) void upload(file);
              }}
              className="rounded-lg border border-line bg-surface p-2"
            />
          </label>

          {asset !== null && (
            <div>
              <Button variant="danger" onClick={remove} disabled={busy}>
                حذف الفيديو
              </Button>
            </div>
          )}
        </div>
      </Card>

      {asset !== null && <CaptionsCard assetUuid={asset.uuid} />}
    </div>
  );
}

/**
 * The lesson's captions.
 *
 * A track is not decoration: it is what makes the lesson usable to a deaf
 * student, watchable in a shared room, and searchable by the transcript panel in
 * the player. The file is parsed before it is stored, so a broken one is refused
 * here rather than discovered as an empty track during a lesson.
 */
function CaptionsCard({ assetUuid }: { assetUuid: string }) {
  const [caption, setCaption] = useState<Caption | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const attach = async (file: File) => {
    setError("");
    setBusy(true);

    try {
      setCaption(await media.attachCaption(assetUuid, file));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const detach = async () => {
    if (caption === null) return;

    setBusy(true);
    try {
      await media.removeCaption(caption.uuid);
      setCaption(null);
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <div className="flex flex-col gap-4">
        <h2 className="text-lg font-semibold text-ink">النصّ المصاحب</h2>

        {error !== "" && <Alert tone="danger" title={error} />}

        {caption !== null && (
          <div className="flex flex-wrap items-center gap-3">
            <Badge tone="success">مرفوع</Badge>
            <span className="text-sm text-ink">
              {caption.language === "ar" ? "العربية" : caption.language}
            </span>
          </div>
        )}

        <label className="flex flex-col gap-2 text-sm text-ink">
          <span>ملف WebVTT ‏(‎.vtt)</span>
          <input
            type="file"
            accept=".vtt,text/vtt"
            disabled={busy}
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (file !== undefined) void attach(file);
            }}
            className="rounded-lg border border-line bg-surface p-2"
          />
          <span className="text-xs text-ink-muted">
            رفع ملف بنفس اللغة يستبدل السابق. النصّ الكامل يُشتقّ من هذا الملف ولا
            يُخزَّن مرة ثانية.
          </span>
        </label>

        {caption !== null && (
          <div>
            <Button variant="danger" onClick={detach} disabled={busy}>
              حذف النصّ
            </Button>
          </div>
        )}
      </div>
    </Card>
  );
}
