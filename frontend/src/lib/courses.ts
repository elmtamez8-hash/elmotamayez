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
  | "live_session";

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
  order: number;
  duration_seconds: number;
  is_preview: boolean;
  is_free: boolean;
  /** The item's own file — one, or none. */
  asset: MediaAsset | null;
  /** Files beside it, whatever the item's type. Many, or none. */
  attachments: MediaAsset[];
}

export interface LessonEdit {
  title?: string;
  content?: string | null;
  external_url?: string | null;
  is_preview?: boolean;
  is_free?: boolean;
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

export const courses = {
  tree: (courseUuid: string) => api.get<CourseTree>(`/courses/${courseUuid}/tree`),

  lesson: (courseUuid: string, lessonUuid: string) =>
    api.get<LessonDetail>(`/courses/${courseUuid}/lessons/${lessonUuid}`),

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
