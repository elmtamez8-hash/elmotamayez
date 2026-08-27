import { api } from "./api";

/**
 * The teacher's announcements (spec 010 · US6).
 *
 * ⚠️ STILL THE TEACHER'S CLIENT, AND STILL THE ONLY ONE HERE. 010's `FR-044`
 * made the notification centre the delivery, so the recipient's whole surface
 * was the bell — which is why the notification body carries the announcement
 * verbatim rather than a link to a screen that did not exist. Spec 021 added the
 * screen: `courseHub.announcements()` in `lib/course-hub.ts` reads one course's
 * notices for the student they were addressed to, from a different route and
 * with a different payload. It is NOT this type with fields removed — the
 * counters below are the publisher's question and a headcount of the class.
 *
 * There is still no reply call: `FR-045` sends answers to the private
 * conversation, which is one tap away and already moderated.
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
