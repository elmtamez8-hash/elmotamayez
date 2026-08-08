"use client";

import { useState } from "react";
import { AssetRow, AssetUploader } from "./AssetUploader";
import { Alert } from "@/components/ui/Alert";
import { CheckboxField } from "@/components/ui/Field";
import { userMessage } from "@/lib/errors";
import { media, type MediaAsset } from "@/lib/media";

/**
 * The editor for a PDF or a file.
 *
 * The warning is the part that must not be softened. "View only" is not copy
 * protection, and the web does not offer any: a document rendered in a browser
 * has already been decoded by that browser, and a determined reader keeps it.
 * What the switch actually buys is that the URL expires — so a link forwarded to
 * a group chat is worthless within minutes, which is the leak that happens in
 * practice.
 *
 * Saying more than that would be selling a promise the platform cannot keep, and
 * a teacher who believes it uploads material they would otherwise have withheld.
 */
export function DocumentEditor({
  lessonUuid,
  asset,
  onChanged,
}: {
  lessonUuid: string;
  asset: MediaAsset | null;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const setDisposition = async (downloadable: boolean) => {
    if (asset === null) return;

    setBusy(true);
    setError("");

    try {
      await media.setDisposition(asset.uuid, downloadable);
      onChanged();
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      {error !== "" && (
        <Alert tone="danger" title="تعذّر الحفظ">
          {error}
        </Alert>
      )}

      {asset === null ? (
        <AssetUploader
          lessonUuid={lessonUuid}
          kind="document"
          role="primary"
          label="ارفع المستند"
          hint="PDF أو Word أو PowerPoint أو صورة. يُقرأ نوع الملف من محتواه لا من امتداده."
          onUploaded={onChanged}
        />
      ) : (
        <>
          <AssetRow asset={asset} />

          <CheckboxField
            id={`downloadable-${asset.uuid}`}
            checked={asset.is_downloadable}
            disabled={busy}
            label={
              <span>
                يسمح بالتحميل
                <span className="block text-xs text-ink-muted">
                  مفعَّلاً: يصل الملف إلى جهاز الطالب باسمه. معطَّلاً: يُفتح داخل المتصفّح فقط.
                </span>
              </span>
            }
            onChange={(checked) => void setDisposition(checked)}
          />

          <Alert tone="info" title="ماذا يعني «عرض فقط» بالضبط">
            الرابط ينتهي بعد دقائق، فإرساله في مجموعة لا يفيد أحداً — وهذه هي التسريبة التي تحدث
            فعلاً. لكنه <strong>ليس منعاً للنسخ</strong>: أي مستند يُعرض في متصفّح يكون المتصفّح
            قد فكّه بالفعل، ومن أراد الاحتفاظ به احتفظ. لا ترفع هنا ما لا تحتمل خروجه.
          </Alert>

          <AssetUploader
            lessonUuid={lessonUuid}
            kind="document"
            role="primary"
            label="استبدال المستند"
            hint="الملف الجديد يحلّ محلّ الحالي، ولا يمسّ المرفقات."
            disabled={busy}
            onUploaded={onChanged}
          />
        </>
      )}
    </div>
  );
}
