"use client";

import { useState } from "react";

import { AssetRow, AssetUploader } from "./AssetUploader";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { userMessage } from "@/lib/errors";
import { media, type Caption, type MediaAsset } from "@/lib/media";

/**
 * The editor for a video item — the same three-step upload as audio and document.
 *
 * ⚠️ VIDEO USED TO BE THE ONE TYPE WITH NO EDITOR HERE, AND ITS SEPARATE PAGE IS
 * WHAT THE BUG CAME OUT OF. `/manage/courses/{course}/lessons/{lesson}` was a
 * video-only screen — the heading said «فيديو الدرس», the input said
 * `accept="video/*"` — and the course page linked EVERY item to it, whatever its
 * type. Opening an article from that list therefore offered to upload a video for
 * it, and the server refused with «هذا النوع من العناصر لا يحمل ملفاً خاصاً به»:
 * a correct refusal to a request the screen should never have offered.
 *
 * The page also never fetched the lesson, so it never knew about an EXISTING
 * video: a teacher who had already uploaded one was told «لا يوجد فيديو لهذا
 * الدرس بعد» on every visit, with no way to replace it or reach its captions.
 *
 * Both are gone by joining the family instead of being an exception to it:
 * `LessonEditor` already loads the lesson and already renders by `asset_kind`,
 * which is the registry's own answer rather than a list of types kept in the
 * browser.
 */
export function VideoEditor({
  lessonUuid,
  asset,
  onChanged,
}: {
  lessonUuid: string;
  asset: MediaAsset | null;
  onChanged: () => void;
}) {
  return (
    <div className="space-y-4">
      {asset !== null && <AssetRow asset={asset} />}

      <AssetUploader
        lessonUuid={lessonUuid}
        kind="video"
        role="primary"
        label={asset === null ? "ارفع الفيديو" : "استبدال الفيديو"}
        hint="MP4 أو MOV أو WebM. تُقرأ المدة من الملف نفسه، ويُتحقّق من نوعه من بايتاته لا من امتداده."
        onUploaded={onChanged}
      />

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
 *
 * ⚠️ IT SHOWS WHAT THIS VISIT UPLOADED, NOT WHAT THE ASSET HAS. There is no
 * teacher-facing list of an asset's captions — `LessonResource` describes the
 * asset without them — so a track uploaded last week renders no badge here and
 * cannot be deleted from this screen, only replaced by uploading the same
 * language again. Carried over from the page this moved out of rather than
 * quietly fixed: the fix is an endpoint, and inventing one inside a bug fix is
 * how a payload gains a field nobody reviewed.
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
