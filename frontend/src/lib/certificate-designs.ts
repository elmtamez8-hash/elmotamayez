import { api } from "./api";
import type { FieldBox, FieldKey } from "./certificate-design";

/**
 * The teacher's certificate design gallery.
 *
 * ⚠️ ONE SHAPE for a shipped template and an uploaded image — the server merges
 * them so the screen never branches on which it is holding. A card with
 * `uuid: null` is a shipped template this workspace has not adopted yet; adopting
 * it is what gives it a row, and that happens in the same act as selecting it.
 */
export interface CertificateDesignCard {
  uuid: string | null;
  system_key: string | null;
  name: string | null;
  image_url: string;
  source: "system" | "uploaded";
  is_selected: boolean;
  /** An uploaded design with no field positions yet — it may not be selected. */
  is_ready: boolean;
  boxes: Record<FieldKey, FieldBox> | null;
}

export interface DesignGallery {
  data: CertificateDesignCard[];
  upload_limit: number;
  uploads_used: number;
}

/** A stable key for a card that may have no uuid yet. */
export function designKey(card: CertificateDesignCard): string {
  return card.uuid ?? `system:${card.system_key}`;
}

export const certificateDesigns = {
  list: () => api.get<DesignGallery>("/certificate-designs"),

  /** Adopts a shipped template — and selects it in the same request. */
  adopt: (systemKey: string) =>
    api.post<CertificateDesignCard>("/certificate-designs", { system_key: systemKey }),

  /** The six keys in full — the server refuses a partial write. */
  saveBoxes: (uuid: string, boxes: Record<FieldKey, FieldBox>) =>
    api.patch<CertificateDesignCard>(`/certificate-designs/${uuid}`, { boxes }),

  /**
   * ⚠️ `boxes: null` is «go back to the template's own positions» (`FR-021`), not
   * «clear them». Only an adopted row has a template behind it; the server refuses
   * this on an uploaded design with a sentence saying so.
   */
  resetBoxes: (uuid: string) =>
    api.patch<CertificateDesignCard>(`/certificate-designs/${uuid}`, { boxes: null }),

  select: (uuid: string) =>
    api.patch<CertificateDesignCard>(`/certificate-designs/${uuid}`, { is_selected: true }),

  /**
   * ⚠️ The upload arrives NOT SELECTED, deliberately: it carries no field
   * positions yet, and a design with none prints the student's name over the
   * artwork's ornament (`FR-044`).
   */
  upload: (file: File, name: string) => {
    const form = new FormData();

    form.append("image", file);
    form.append("name", name);

    return api.upload<CertificateDesignCard>("/certificate-designs", form);
  },

  remove: (uuid: string) => api.delete<null>(`/certificate-designs/${uuid}`),
};
