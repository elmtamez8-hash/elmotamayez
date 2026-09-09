import { api } from "./api";
import type { MediaAsset } from "./media";

/**
 * The course tree, as its author sees it.
 *
 * Types mirror `CourseTreeResource` field for field. Read the PHP resource
 * before changing one — a type that claims a field the API does not send
 * renders a blank with no error anywhere.
 *
 * Two things to know before using this:
 *
 * **Reordering sends the COMPLETE sibling list.** Not "move this to index 3".
 * That is what makes a duplicate position impossible to express and removes any
 * server-side shifting arithmetic. Send every sibling, in the order you want.
 *
 * **`structure_version` is a concurrency token, not decoration.** Read it from
 * the tree, send it back with every reorder and publish. A 409 means someone
 * else moved something and the map you drew is out of date — reload, do not
 * retry.
 */

export type ContentStatus = "draft" | "published" | "archived";

export type LessonTypeValue =
  | "video"
  | "audio"
  | "pdf"
  | "file"
  | "article"
  | "note"
  | "link"
  | "exam"
  | "assignment"
  | "live_session"
  | "embed";

export interface TreeLesson {
  uuid: string;
  title: string;
  order: number;
  type: LessonTypeValue;
  type_label: string;
  status: ContentStatus;
  status_label: string;
  /** Set when the node is published but hidden anyway because an ancestor is not. */
  blocked_by: "section" | "chapter" | null;
  is_completable: boolean;
  /** A published session recording: watched by seat, and not repointable from here. */
  is_recording: boolean;
  /**
   * The exam or session this item points at has been deleted.
   *
   * Shown to the author and to nobody else: the student's tree drops the row, but
   * the teacher is the only person who can repoint or remove it.
   */
  reference_missing: boolean;
  is_preview: boolean;
  is_free: boolean;
  duration_seconds: number;
}

export interface TreeChapter {
  uuid: string;
  title: string;
  order: number;
  status: ContentStatus;
  status_label: string;
  blocked_by: "section" | "chapter" | null;
  lessons: TreeLesson[];
}

export interface TreeSection {
  uuid: string;
  title: string;
  order: number;
  status: ContentStatus;
  status_label: string;
  chapters: TreeChapter[];
}

export interface CourseTree {
  uuid: string;
  title: string;
  is_sequential: boolean;
  structure_version: number;
  sections: TreeSection[];
}

export interface NewLesson {
  chapter_uuid: string;
  title: string;
  type: LessonTypeValue;
  content?: string;
  external_url?: string;
  reference_uuid?: string;
  duration_seconds?: number;
  is_preview?: boolean;
  is_free?: boolean;
}

/**
 * One item in full — what the tree deliberately leaves out.
 *
 * `content_html` is derived on the server from the Markdown source on every
 * response and never stored. Render it; do not send it back.
 */
export interface LessonDetail {
  uuid: string;
  chapter_uuid: string | null;
  section_uuid: string | null;
  title: string;
  type: LessonTypeValue;
  type_label: string;
  status: ContentStatus;
  status_label: string;
  content: string | null;
  content_html: string;
  external_url: string | null;
  is_completable: boolean;
  is_recording: boolean;
  /**
   * What kind of thing this type IS, from `LessonTypeRegistry` — not restated here.
   *
   * `inline` has a body to type, `external` a URL, `uploaded` a file,
   * `reference` a target to pick. The editor branches on this and on
   * `asset_kind`; it used to keep its own arrays of type names, which meant the
   * registry's answer existed twice and only one copy was authoritative.
   */
  family: "inline" | "uploaded" | "reference" | "external";
  /** Which uploader an `uploaded` item needs. Null on every other family. */
  asset_kind: "video" | "audio" | "document" | null;
  order: number;
  duration_seconds: number;
  is_preview: boolean;
  is_free: boolean;
  /**
   * What this item points at — the exam, or the session. Null on the eight types
   * that point at nothing, and null when the target has been deleted.
   */
  reference: ExamReference | SessionReference | null;
  exam_gate: ExamGate | null;
  exam_gate_label: string | null;
  /** The item's own file — one, or none. */
  asset: MediaAsset | null;
  /** Files beside it, whatever the item's type. Many, or none. */
  attachments: MediaAsset[];
}

export interface LessonEdit {
  title?: string;
  content?: string | null;
  external_url?: string | null;
  /** The exam or session this item places, by uuid. */
  reference_uuid?: string;
  exam_gate?: ExamGate;
  is_preview?: boolean;
  is_free?: boolean;
  /**
   * Spec 032 · FR-018 — the ONE type whose duration the teacher writes.
   *
   * Every other duration is read off the uploaded file, so accepting it from the
   * teacher there would let the number under the play button disagree with the
   * file above it. An embed has no file of ours to read, and the host gives
   * nothing away without a key.
   *
   * ⚠️ It was absent from this interface entirely and no editor drew the field,
   * so FR-018 had no surface at all before this line.
   */
  duration_seconds?: number;
}

/** What an exam item asks before the course goes on. Two values, no third. */
export type ExamGate = "attempt" | "pass";

/** What a reference item may be pointed at, for one course. */
export interface ReferenceTargets {
  exams: Array<{
    uuid: string;
    title: string;
    passing_score: number;
    questions_count: number;
  }>;
  sessions: Array<{
    uuid: string;
    title: string;
    starts_at: string;
    /** Declared with the time — never render a session in the browser's zone. */
    timezone: string;
    status: string;
    status_label: string;
    has_recording: boolean;
  }>;
}

export interface ExamReference {
  uuid: string;
  title: string;
  passing_score: number;
  duration_minutes: number;
}

export interface SessionReference {
  uuid: string;
  title: string;
  starts_at: string;
  timezone: string;
  status: string;
  status_label: string;
  /**
   * The one word a screen switches on. `unavailable` is the case the spec singles
   * out (FR-048): the session's time has passed and no recording ever arrived.
   */
  state: "upcoming" | "processing" | "recorded" | "cancelled" | "unavailable";
}

/** What changing an item's type would discard — read before it is done. */
export interface TypeChangePreview {
  type: LessonTypeValue;
  type_label: string;
  losses: string[];
}

export interface PublishItem {
  uuid: string;
  status: ContentStatus;
}

/**
 * What a publish would do to the students already enrolled.
 *
 * `items` is the batch the server costed, and it is what must be published: ask
 * for the impact of one set and then send another and the number on screen
 * describes something that did not happen.
 */
export interface PublishPreview {
  structure_version: number;
  items: PublishItem[];
  /** Items entering the progress denominator — and leaving it. */
  added_items: number;
  removed_items: number;
  /** How many enrolled students' percentages move, and by how much at the edges. */
  students_affected: number;
  /** Zero or negative. */
  largest_drop_pct: number;
  /** Zero or positive. */
  largest_gain_pct: number;
  /** Items that will start being unlocked by something else. Empty unless sequential. */
  resequenced: Array<{ uuid: string; title: string; unlocked_by: string | null }>;
  warnings: Array<{ code: string; message: string }>;
}

export const courses = {
  tree: (courseUuid: string) => api.get<CourseTree>(`/courses/${courseUuid}/tree`),

  /**
   * The impact of a publish, before it happens.
   *
   * Called with no `items` it costs everything still in draft and returns that
   * list — which is why the page no longer works out the draft set itself. Two
   * implementations of "which nodes are drafts" would be one edit away from
   * showing the impact of one batch and publishing another.
   */
  publishPreview: (courseUuid: string, items?: PublishItem[]) => {
    const query = new URLSearchParams();

    items?.forEach((item, index) => {
      query.set(`items[${index}][uuid]`, item.uuid);
      query.set(`items[${index}][status]`, item.status);
    });

    const suffix = query.toString() === "" ? "" : `?${query.toString()}`;

    return api.get<PublishPreview>(`/courses/${courseUuid}/tree/publish-preview${suffix}`);
  },

  lesson: (courseUuid: string, lessonUuid: string) =>
    api.get<LessonDetail>(`/courses/${courseUuid}/lessons/${lessonUuid}`),

  /** This course's published exams and its sessions, for the two pickers. */
  referenceTargets: (courseUuid: string) =>
    api.get<ReferenceTargets>(`/courses/${courseUuid}/reference-targets`),

  updateLesson: (courseUuid: string, lessonUuid: string, patch: LessonEdit) =>
    api.put<LessonDetail>(`/courses/${courseUuid}/lessons/${lessonUuid}`, patch),

  typeChangePreview: (courseUuid: string, lessonUuid: string, type: LessonTypeValue) =>
    api.get<TypeChangePreview>(`/courses/${courseUuid}/lessons/${lessonUuid}/type/${type}`),

  changeLessonType: (courseUuid: string, lessonUuid: string, type: LessonTypeValue) =>
    api.put<LessonDetail>(`/courses/${courseUuid}/lessons/${lessonUuid}/type`, { type }),

  /**
   * Publish, unpublish or archive a batch of nodes in one call.
   *
   * Answers with the whole tree, because the version moves — a client left
   * holding the old one 409s on its very next write.
   */
  publishTree: (courseUuid: string, version: number, items: PublishItem[]) =>
    api.post<CourseTree>(`/courses/${courseUuid}/tree/publish`, {
      structure_version: version,
      items,
    }),

  createSection: (courseUuid: string, title: string) =>
    api.post<TreeSection>(`/courses/${courseUuid}/sections`, { title }),

  renameSection: (courseUuid: string, sectionUuid: string, title: string) =>
    api.put<TreeSection>(`/courses/${courseUuid}/sections/${sectionUuid}`, { title }),

  deleteSection: (courseUuid: string, sectionUuid: string) =>
    api.delete<void>(`/courses/${courseUuid}/sections/${sectionUuid}`),

  createChapter: (courseUuid: string, sectionUuid: string, title: string) =>
    api.post<TreeChapter>(`/courses/${courseUuid}/chapters`, {
      section_uuid: sectionUuid,
      title,
    }),

  renameChapter: (courseUuid: string, chapterUuid: string, title: string) =>
    api.put<TreeChapter>(`/courses/${courseUuid}/chapters/${chapterUuid}`, { title }),

  deleteChapter: (courseUuid: string, chapterUuid: string) =>
    api.delete<void>(`/courses/${courseUuid}/chapters/${chapterUuid}`),

  createLesson: (courseUuid: string, lesson: NewLesson) =>
    api.post<TreeLesson>(`/courses/${courseUuid}/lessons`, lesson),

  renameLesson: (courseUuid: string, lessonUuid: string, title: string) =>
    api.put<TreeLesson>(`/courses/${courseUuid}/lessons/${lessonUuid}`, { title }),

  deleteLesson: (courseUuid: string, lessonUuid: string) =>
    api.delete<void>(`/courses/${courseUuid}/lessons/${lessonUuid}`),

  /** The full sibling list, in its new order. See the note at the top. */
  reorderSections: (courseUuid: string, version: number, order: string[]) =>
    api.put<{ structure_version: number }>(`/courses/${courseUuid}/sections/order`, {
      structure_version: version,
      order,
    }),

  reorderChapters: (courseUuid: string, sectionUuid: string, version: number, order: string[]) =>
    api.put<{ structure_version: number }>(
      `/courses/${courseUuid}/sections/${sectionUuid}/chapters/order`,
      { structure_version: version, order },
    ),

  reorderLessons: (courseUuid: string, chapterUuid: string, version: number, order: string[]) =>
    api.put<{ structure_version: number }>(
      `/courses/${courseUuid}/chapters/${chapterUuid}/lessons/order`,
      { structure_version: version, order },
    ),
};

/**
 * Move an item one step within its siblings, returning the new full order.
 *
 * Buttons rather than drag-and-drop: the frontend carries four dependencies,
 * native HTML5 dragging is hostile to screen readers and fragile under RTL, and
 * the keyboard path has to exist either way. Drag can be built on top of this
 * same call later without changing the endpoint.
 */
export function moveWithin(uuids: string[], uuid: string, direction: -1 | 1): string[] {
  const from = uuids.indexOf(uuid);
  const to = from + direction;

  if (from === -1 || to < 0 || to >= uuids.length) return uuids;

  const next = [...uuids];
  [next[from], next[to]] = [next[to], next[from]];

  return next;
}
