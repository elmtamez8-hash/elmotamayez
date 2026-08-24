import { api } from "./api";

/**
 * The teacher's announcements (spec 010 · US6).
 *
 * ⚠️ THERE IS NO STUDENT CLIENT HERE, AND THE ABSENCE IS THE DESIGN. `FR-044`
 * makes the notification centre the delivery, so the recipient's whole surface is
 * the bell — which is also why the notification body carries the announcement
 * verbatim rather than a link to a screen that does not exist. Nor is there a
 * reply call: `FR-045` sends answers to the private conversation, which is one
 * tap away and already moderated.
 *
 * ⚠️ AND THE RESPONSE SHAPE FOLLOWS THE RULE WRITTEN IN `lib/reviews.ts`:
 * `JsonResource::withoutWrapping()` is on, so a collection serialises as a bare
 * array — and `request()` in `lib/api.ts` re-wraps an array into `{ data }`. So
 * the client type is `{ data: T[] }` for a list and a BARE type for a single
 * object. Curling the API answers a different question from what the page gets.
 */

export type AnnouncementScope = "all" | "course" | "session";

export interface Announcement {
  uuid: string;
  body: string;
  scope: AnnouncementScope;
  is_urgent: boolean;
  is_published: boolean;
  is_hidden: boolean;
  published_at: string | null;
  created_at: string | null;
  /** Live counts, never stored — see `FR-046`. */
  notified_count: number | null;
  read_count: number | null;
  author_name?: string | null;
}

export interface AnnouncementInput {
  body: string;
  scope: AnnouncementScope;
  scope_uuid?: string | null;
  is_urgent?: boolean;
}

/** The three scopes that ship. «Groups» are out of scope, declared. */
export const ANNOUNCEMENT_SCOPES: { key: AnnouncementScope; label: string; hint: string }[] = [
  { key: "all", label: "كلّ الطلاب", hint: "كلّ طالب مسجَّل عندك الآن." },
  { key: "course", label: "كورس واحد", hint: "طلاب هذا الكورس وحدهم." },
  { key: "session", label: "حصة واحدة", hint: "من حجز مقعداً في هذه الحصة." },
];

export const announcements = {
  list: () => api.get<{ data: Announcement[] }>("/manage/announcements"),

  create: (input: AnnouncementInput) => api.post<Announcement>("/manage/announcements", input),

  publish: (uuid: string) => api.post<Announcement>(`/manage/announcements/${uuid}/publish`, {}),

  update: (uuid: string, input: AnnouncementInput) =>
    api.patch<Announcement>(`/manage/announcements/${uuid}`, input),

  /** Retracts it, and takes it off every screen holding it (`FR-047`). */
  hide: (uuid: string) => api.delete<Announcement>(`/manage/announcements/${uuid}`),
};
