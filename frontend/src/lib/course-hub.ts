import { api } from "./api";
import type { Assignment } from "./assignments";
import type { Certificate, Exam } from "./types";

/**
 * The five per-course reads behind the course page's tabs (021 · US2).
 *
 * ⚠️ ONE FILE RATHER THAN FIVE EDITS, because they are one question asked of
 * five modules: «what does this course hold». Four of them are the existing
 * lists with `?course={uuid}` — matched on the server THROUGH THE RELATION, so a
 * uuid it cannot resolve returns an empty list rather than the unfiltered one.
 * Silently dropping the filter is how a tab headed «اختبارات هذه المادّة» shows a
 * student every paper on the platform.
 *
 * ⚠️ AND THE FIFTH IS NEW. There has never been a student-facing announcements
 * route: `/manage/announcements` is the teacher's, and the announcement body
 * travels inside the notification precisely because there was no screen to link
 * to. See `lib/announcements.ts`, which is still the teacher's client and says
 * so.
 */

/**
 * One notice, as the person it was addressed to reads it.
 *
 * ⚠️ NOT `Announcement` FROM `lib/announcements.ts`. That one carries
 * `notified_count` and `read_count` — «how many did I reach», the publisher's
 * question and a headcount of the class — plus the draft and hidden flags of a
 * workflow the reader is not in. The server sends a different object, not a
 * redacted one.
 */
export interface CourseAnnouncement {
  uuid: string;
  body: string;
  is_urgent: boolean;
  published_at: string | null;
  author_name: string | null;
}

/**
 * The reader's own record on one paper.
 *
 * ⚠️ ABSENT MEANS «NOT ASKED», NOT «NEVER SAT» — the server sends it only when
 * the list was loaded with the reader's own attempts, so a screen must not
 * render its absence as a zero. And `best_score` is null rather than 0 for a
 * paper never sat: a 0% reads as a paper failed outright, which is the harsher
 * of the two and the wrong one.
 */
export interface MyAttempts {
  count: number;
  best_score: number | null;
  passed: boolean;
  last_uuid: string | null;
}

export interface CourseExam extends Exam {
  my_attempts?: MyAttempts;
}

export const courseHub = {
  announcements: (courseUuid: string) =>
    api.get<{ data: CourseAnnouncement[] }>(`/courses/${courseUuid}/announcements`),

  exams: (courseUuid: string) =>
    api.get<{ data: CourseExam[] }>(`/exams?course=${encodeURIComponent(courseUuid)}`),

  assignments: (courseUuid: string) =>
    api.get<{ data: Assignment[] }>(`/assignments?course=${encodeURIComponent(courseUuid)}`),

  certificates: (courseUuid: string) =>
    api.get<{ data: Certificate[] }>(`/certificates?course=${encodeURIComponent(courseUuid)}`),
};
