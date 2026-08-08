"use client";

import { AssetRow, AssetUploader } from "./AssetUploader";
import { Alert } from "@/components/ui/Alert";
import type { MediaAsset } from "@/lib/media";

/**
 * The editor for an audio item.
 *
 * The same upload line as a video and a document — ticket, bytes, ask the server
 * what arrived — with `kind: audio`, which is what picks the mime list and the
 * size ceiling. There is no separate pipeline, and there should not be: a second
 * one is a second place for the entitlement check to be forgotten.
 *
 * Its length comes from the file, never from the teacher (FR-015). A typed
 * duration is a number that was right the day it was typed and wrong after the
 * first re-upload.
 */
export function AudioEditor({
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
        kind="audio"
        role="primary"
        label={asset === null ? "ارفع الملف الصوتي" : "استبدال الملف الصوتي"}
        hint="MP3 أو M4A أو WAV. تُقرأ المدة من الملف نفسه وتظهر للطالب كما هي."
        onUploaded={onChanged}
      />

      <Alert tone="info" title="الصوت يُستمع إليه بنفس حماية الفيديو">
        رابط مؤقّت مرتبط بجلسة الطالب، ينتهي بانتهائها — لا رابط دائم يمكن إرساله. لكن التسجيل
        الصوتي بطبيعته أسهل في إعادة النشر من الفيديو، فلا علامة مائية تحمل اسم المستمع.
      </Alert>
    </div>
  );
}
