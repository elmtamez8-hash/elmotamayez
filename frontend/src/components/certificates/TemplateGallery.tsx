"use client";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { ConfirmButton } from "@/components/ui/ConfirmButton";
import { CertificateArtwork } from "@/components/certificates/CertificateArtwork";
import { designKey, type CertificateDesignCard } from "@/lib/certificate-designs";
import type { CertificateValues } from "@/lib/certificate-design";

/**
 * The gallery a teacher picks their certificate design from.
 *
 * ⚠️ THE PREVIEWS ARE REAL DRAWINGS, NOT THUMBNAILS (`FR-013`). They go through
 * `CertificateArtwork` — the same component the public verify page draws with — so
 * what the teacher compares is what a parent will open. A gallery of bare images
 * would show two pretty frames and hide the only thing that differs between them:
 * where the six fields land.
 *
 * ⚠️ AND THE SAMPLE NAME IS DELIBERATELY LONG. A design chosen against «أحمد» and
 * used by «عبد الرحمن محمد الشريف الخطيب» is a design chosen blind — the shrinking
 * is the whole feature, and it has to be visible before the choice, not after.
 */
const SAMPLE: CertificateValues = {
  student: "عبد الرحمن محمد الشريف الخطيب",
  subject: "الرياضيات",
  teacher: "سامي عبد الله",
  date: "١٤ مايو ٢٠٢٦",
  number: "CERT-2026-A1B2C3D4",
  // An absolute address so the code actually draws: an empty one renders no box
  // at all, and where the code sits is part of what is being chosen.
  verifyUrl: "https://mteatch.example/certificates/verify/SAMPLE",
};

export interface TemplateGalleryProps {
  designs: CertificateDesignCard[];
  /** The card currently being written, by `designKey` — its button shows the wait. */
  busyKey?: string | null;
  onSelect: (card: CertificateDesignCard) => void;
  /** Offered only where there is a row to adjust — a template nobody adopted has no uuid. */
  onAdjust?: (card: CertificateDesignCard) => void;
  /** Uploaded designs only: a shipped template is not a teacher's to delete. */
  onDelete?: (card: CertificateDesignCard) => void;
}

export function TemplateGallery({ designs, busyKey, onSelect, onAdjust, onDelete }: TemplateGalleryProps) {
  return (
    <ul className="grid grid-cols-1 gap-6 md:grid-cols-2">
      {designs.map((card) => {
        const key = designKey(card);

        return (
          <li
            key={key}
            className={`rounded-3xl border bg-surface-raised p-4 ${
              card.is_selected ? "border-primary" : "border-line"
            }`}
            data-design={key}
          >
            <Preview card={card} />

            <div className="mt-3 flex flex-wrap items-center gap-2">
              <h3 className="me-auto text-sm font-semibold text-ink">{card.name ?? "تصميم بلا اسم"}</h3>

              {card.is_selected && <Badge tone="success">المعتمد الآن</Badge>}
              {!card.is_ready && <Badge tone="warning">يحتاج ضبطاً</Badge>}
              {card.source === "uploaded" && <Badge tone="neutral">تصميمك</Badge>}
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              <Button
                variant="ghost"
                size="sm"
                disabled={card.is_selected || !card.is_ready}
                loading={busyKey === key}
                loadingLabel="جارٍ الاعتماد…"
                onClick={() => onSelect(card)}
              >
                {card.is_selected ? "معتمد" : "اعتمد هذا القالب"}
              </Button>

              {onAdjust !== undefined && card.uuid !== null && (
                <Button variant="ghost" size="sm" onClick={() => onAdjust(card)}>
                  اضبط المواضع
                </Button>
              )}

              {onDelete !== undefined && card.source === "uploaded" && card.uuid !== null && (
                <ConfirmButton
                  variant="danger"
                  size="sm"
                  confirmLabel="أكّد الحذف"
                  onConfirm={() => onDelete(card)}
                >
                  احذف
                </ConfirmButton>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/**
 * ⚠️ A design with no positions is drawn as its bare image, never with somebody
 * else's boxes. Falling back to the default template's positions here would show
 * a teacher a preview that is correct for an artwork they are not looking at —
 * and the first person to see the real result would be a student on their own
 * certificate.
 */
function Preview({ card }: { card: CertificateDesignCard }) {
  if (card.boxes === null) {
    return (
      // ⚠️ `<img>` and never `next/image`: this src is a file a teacher uploaded,
      // and one such call site revives the `sharp` advisory CLAUDE.md records as
      // closed by call-site discipline.
      <img
        src={card.image_url}
        alt={`تصميم ${card.name ?? ""} قبل ضبط المواضع`}
        className="w-full rounded-lg border border-line"
      />
    );
  }

  return (
    <CertificateArtwork
      design={{ image_url: card.image_url, boxes: card.boxes }}
      values={SAMPLE}
      ariaLabel={`معاينة قالب ${card.name ?? ""}`}
    />
  );
}
