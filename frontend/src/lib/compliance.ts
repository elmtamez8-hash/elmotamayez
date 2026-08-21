import { api } from "@/lib/api";

/**
 * Spec 013 — what the platform collects, and the rights over it.
 *
 * ⚠️ THE CATALOGUE AND THE POLICY ARE PUBLIC READS, and that is a decision rather
 * than an oversight. Someone deciding whether to create an account for their child
 * has to be able to read what will be collected BEFORE creating one — a privacy
 * policy behind a login is not published.
 */

/** One thing the platform collects, as the consent screen reads it. */
export interface DataCategory {
  key: string;
  label_ar: string;
  purpose_ar: string;
  audience: string;
  /**
   * ⚠️ NEVER HIDDEN, AND NEVER RENDERED THE SAME AS AN OPTIONAL ONE. A screen
   * showing one undifferentiated list asks for consent to things that cannot be
   * refused as though they could.
   */
  is_required: boolean;
  owning_module: string;
  retain_days: number | null;
  /** Spelled out by the server — "1095" is not an answer a parent can read. */
  retention_label_ar: string;
  expiry_behaviour: "delete" | "anonymise" | "archive" | null;
}

/** A third party that receives personal data (FR-024). */
export interface DataProcessor {
  key: string;
  name: string;
  purpose_ar: string;
  processing_location: string;
  categories: string[];
  erasure_capability: "full" | "partial" | "none";
  erasure_capability_label_ar: string;
}

export interface PrivacyCatalogue {
  data: DataCategory[];
  processors: DataProcessor[];
}

export interface PrivacyPolicy {
  /**
   * ⚠️ IT TRAVELS WITH THE TEXT AND IS SENT BACK ON CONSENT. A consent names the
   * version it was given for, so a client that displayed one version and submitted
   * another would record a signature against words nobody read.
   */
  version: string;
  body_html: string;
}

export const compliance = {
  categories: () => api.get<PrivacyCatalogue>("/privacy/categories"),

  policy: () => api.get<PrivacyPolicy>("/privacy/policy"),

  /**
   * Change which optional categories are consented to (FR-007).
   *
   * ⚠️ THE COMPLETE SET, NEVER A DIFF. A diff applied to state read a second ago
   * is the lost update — and here the lost update decides whether a child's data
   * may be processed. Same shape as spec 016's reorder sending the whole sibling
   * list, for the same reason.
   *
   * ⚠️ AND AN EMPTY ARRAY IS A REAL ANSWER: "I consent to none of the optional
   * categories". It must be sent as `[]`, not omitted.
   */
  updateCategories: (body: { categories: string[]; version: string; student_uuid?: string }) =>
    api.put<{ version: string; categories: string[] }>("/privacy/consents/categories", body),
};
