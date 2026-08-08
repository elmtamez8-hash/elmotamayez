"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { errorMessage } from "@/lib/api";
import { courses, type PublishItem, type PublishPreview } from "@/lib/courses";

/**
 * What this publish does to the people already enrolled, before it happens.
 *
 * A course with thirty students in it is not a document. Publishing a section
 * adds items to the denominator those thirty are measured against — everyone's
 * percentage drops the moment the button is pressed — and in a sequential course
 * it changes which item stands in front of which. The same button also
 * unpublishes and archives, which moves both of those the other way.
 *
 * So the batch is costed on the server first and shown here. The list it costed
 * is the list that gets published: this component never assembles its own, which
 * is the only way the number above the button can be the number that lands.
 */
export function PublishImpactDialog({
  courseUuid,
  items,
  title,
  confirmLabel,
  onConfirm,
  onClose,
}: {
  courseUuid: string;
  /** Omit to cost every draft in the course — the server derives that set. */
  items?: PublishItem[];
  title: string;
  confirmLabel: string;
  onConfirm: (preview: PublishPreview) => void;
  onClose: () => void;
}) {
  const [preview, setPreview] = useState<PublishPreview | null>(null);
  const [error, setError] = useState("");

  // The batch as a stable string. Depending on the array itself would refetch on
  // every render of the parent, because it is rebuilt each time; depending on
  // nothing would show the previous batch's impact if the dialog is reopened on
  // a different node without unmounting.
  const batch = (items ?? []).map((item) => `${item.uuid}:${item.status}`).join(",");

  useEffect(() => {
    let live = true;

    courses
      .publishPreview(courseUuid, batch === "" ? undefined : items)
      .then((result) => {
        if (live) setPreview(result);
      })
      .catch((err: unknown) => {
        if (live) setError(errorMessage(err, "تعذّر حساب أثر النشر. أعد المحاولة."));
      });

    return () => {
      live = false;
    };
  }, [courseUuid, batch]);

  return (
    <Card as="section">
      <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <h3 className="font-semibold text-ink">{title}</h3>
        <Button variant="ghost" size="sm" onClick={onClose}>
          إلغاء
        </Button>
      </header>

      {error !== "" && (
        <Alert tone="danger" title="تعذّر حساب الأثر">
          {error}
        </Alert>
      )}

      {error === "" && preview === null && <RowsSkeleton />}

      {preview !== null && (
        <div className="space-y-4">
          {preview.warnings.map((warning) => (
            <Alert key={warning.code} tone="warning" title="انتبه قبل المتابعة">
              {warning.message}
            </Alert>
          ))}

          <dl className="grid gap-3 sm:grid-cols-3">
            <Figure label="عناصر تدخل حساب التقدّم" value={preview.added_items} />
            <Figure label="عناصر تخرج منه" value={preview.removed_items} />
            <Figure label="طلاب تتغيّر نسبتهم" value={preview.students_affected} />
          </dl>

          {preview.students_affected > 0 && (
            <p className="text-sm text-ink-muted">
              {preview.largest_drop_pct < 0 && (
                <>أكبر انخفاض {Math.abs(preview.largest_drop_pct)}٪. </>
              )}
              {preview.largest_gain_pct > 0 && <>أكبر ارتفاع {preview.largest_gain_pct}٪. </>}
              {/* FR-050 said in words, where the teacher is deciding: a drop is a
                  drop in a number. Nobody loses a completion they already earned
                  and no certificate is withdrawn. */}
              من أتمّ الكورس يبقى متمّاً وشهادته سليمة — ما يتغيّر هو النسبة وحدها.
            </p>
          )}

          {preview.resequenced.length > 0 && (
            <div>
              <h4 className="text-sm font-semibold text-ink">يتغيّر ترتيب فتحها</h4>
              <p className="mt-1 text-sm text-ink-muted">
                هذا الكورس متسلسل، فالعنصر الذي يسبق درساً هو شرط فتحه.
              </p>
              <ul className="mt-2 space-y-1 text-sm text-ink-muted">
                {preview.resequenced.map((item) => (
                  <li key={item.uuid}>
                    «{item.title}» —{" "}
                    {item.unlocked_by === null
                      ? "يصير أوّل ما يُفتح"
                      : `لن يُفتح إلا بعد «${item.unlocked_by}»`}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {preview.items.length === 0 ? (
            <Alert tone="info" title="لا شيء لتنفيذه">
              لا يوجد في هذا الكورس عنصر بحالة مسودّة.
            </Alert>
          ) : (
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => onConfirm(preview)}>{confirmLabel}</Button>
              <Button variant="secondary" onClick={onClose}>
                تراجع
              </Button>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function Figure({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-line p-3">
      <dt className="text-sm text-ink-muted">{label}</dt>
      <dd className="mt-1 text-2xl font-bold text-ink">{value}</dd>
    </div>
  );
}
