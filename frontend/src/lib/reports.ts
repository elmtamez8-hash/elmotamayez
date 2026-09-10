import { api } from "./api";

/**
 * The platform's own numbers, and the standing request for a copy of them
 * (spec 011 · US6 · FR-040 · FR-045).
 *
 * ⚠️ EVERY CALL HERE NEEDS `analytics.cross_teacher.view`, a PLATFORM permission
 * no tenant role holds. The workspace-level `analytics.view` is a different
 * question — «may you see your own teacher's numbers» — and using it to gate this
 * screen would hand one teacher's assistant the totals of every competitor.
 *
 * ⚠️ AND A METRIC ARRIVES AS A NUMERATOR AND A DENOMINATOR, never as a
 * percentage. The API sends what was counted so the screen can be compared
 * against the source with a difference of zero (SC-012); the rounding happens
 * here, at the last possible moment.
 */
export type ReportCadence = "weekly" | "monthly";

export interface PlatformMetric {
  key: string;
  label: string;
  is_ratio: boolean;
  numerator: number;
  denominator: number;
  value: number;
}

export interface RegionShare {
  slug: string;
  name: string;
  students: number;
}

export interface PlatformReport {
  date: string;
  metrics: PlatformMetric[];
  regions: RegionShare[];
  top_teachers: {
    uuid: string;
    name: string;
    average_rating: number;
    reviews_count: number;
  }[];
  top_students: { display_name: string; points: number; level: number }[];
}

export interface ReportSubscription {
  metric_keys: string[];
  cadence: ReportCadence;
  is_active: boolean;
  last_sent_on: string | null;
  /** The catalogue the picker renders — derived server-side from the enum. */
  available: { key: string; label: string }[];
}

export const reports = {
  platform: () => api.get<{ data: PlatformReport }>("/reports/platform"),

  subscription: () => api.get<{ data: ReportSubscription }>("/reports/subscriptions"),

  saveSubscription: (metricKeys: string[], cadence: ReportCadence, isActive: boolean) =>
    api.put<{ data: Omit<ReportSubscription, "available" | "last_sent_on"> }>(
      "/reports/subscriptions",
      { metric_keys: metricKeys, cadence, is_active: isActive },
    ),
};

/** A metric as one line of text — the ratio rounded, the count grouped. */
export function formatMetric(metric: PlatformMetric): string {
  if (!metric.is_ratio) return metric.numerator.toLocaleString("ar-QA");

  // A zero denominator is «nothing to divide», not zero percent: printing 0٪
  // for a day with no orders would read as a collection failure.
  if (metric.denominator === 0) return "—";

  return `${(metric.value * 100).toFixed(1)}٪`;
}
