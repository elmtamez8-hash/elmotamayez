import { api } from "@/lib/api";
import type { ScenePut } from "@/lib/whiteboard/autosave";
import type { BoardBackground } from "@/lib/whiteboard/page-model";

/**
 * The whiteboard's server, as contracts/api.md describes it (spec 039).
 *
 * ⚠️ EVERY CALL RUNS IN THE BROWSER. `lib/api.ts` has no token on the server
 * (`getToken()` answers null outside `window`), so a server component that
 * fetched a board would be refused — the page renders the client canvas, which
 * fetches behind the sign-in guard.
 *
 * ⚠️ A PAGE'S SCENE ARRIVES AS TEXT. The server stores and returns the document
 * exactly as it was saved (no decode, so no memory spike on a 50 MB board); it is
 * parsed here, page by page.
 */

export interface BoardSummary {
  uuid: string;
  title: string;
  background: BoardBackground;
  pages_count: number;
  course: { uuid: string; title: string; deleted: boolean } | null;
  lesson: { uuid: string; title: string } | null;
  owner: { uuid: string; name: string };
  teacher: { uuid: string; name: string };
  updated_at: string;
  lock: { held_by: { uuid: string; name: string } | null };
  can: { edit: boolean; take_lock: boolean; export: boolean; delete: boolean };
}

export interface BoardPagePayload {
  uuid: string;
  position: number;
  version: number;
  /** The scene document, as stored — parse with `parseScene`. */
  scene: string;
  background_file: string | null;
}

export interface BoardDetail extends BoardSummary {
  pages: BoardPagePayload[];
}

export interface SceneDocument {
  v: number;
  elements: unknown[];
  appState: { viewBackgroundColor?: string };
  fileIds: string[];
}

interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
}

export function parseScene(page: BoardPagePayload): SceneDocument {
  const doc = JSON.parse(page.scene) as Partial<SceneDocument>;

  return {
    v: typeof doc.v === "number" ? doc.v : 1,
    elements: Array.isArray(doc.elements) ? doc.elements : [],
    appState: doc.appState ?? {},
    fileIds: Array.isArray(doc.fileIds) ? doc.fileIds : [],
  };
}

/** Where an import stands (story 4). `position` is the place in line while queued. */
export interface ImportState {
  status: "uploading" | "queued" | "converting" | "done" | "failed";
  failure_reason: "too_many_pages" | "unsupported" | "corrupt" | "timeout" | "board_deleted" | null;
  pages_count: number | null;
  position: number | null;
}

export const boards = {
  list: (params: { page?: number; q?: string; course?: string; lesson?: string; mine?: boolean } = {}) => {
    const query = new URLSearchParams();
    if (params.page) query.set("page", String(params.page));
    if (params.q) query.set("q", params.q);
    if (params.course) query.set("course", params.course);
    if (params.lesson) query.set("lesson", params.lesson);
    if (params.mine) query.set("mine", "1");
    const suffix = query.toString();

    return api.get<Paginated<BoardSummary>>(`/boards${suffix ? `?${suffix}` : ""}`);
  },

  create: (data: { title: string; course?: string; lesson?: string; background?: BoardBackground }) =>
    api.post<BoardSummary>("/boards", data),

  show: (uuid: string) => api.get<BoardDetail>(`/boards/${uuid}`),

  update: (uuid: string, data: { title?: string; course?: string | null; lesson?: string | null; background?: BoardBackground }) =>
    api.patch<BoardSummary>(`/boards/${uuid}`, data),

  /** Acquire or renew the edit lock — the 5-second heartbeat. A 423 `locked` carries `held_by`. */
  lock: (uuid: string, tab: string) => api.post<{ held: boolean; handover_requested: boolean }>(`/boards/${uuid}/lock`, { tab }),

  /** «خُذ التحرير»: the owning teacher; the lock moves after the grace. */
  takeLock: (uuid: string, tab: string) => api.post<{ handover_at: string }>(`/boards/${uuid}/lock/take`, { tab }),

  /** Released as the tab closes, so `keepalive`. */
  releaseLock: (uuid: string, tab: string) => api.deleteKeepalive<void>(`/boards/${uuid}/lock`, { tab }).catch(() => undefined),

  saveScene: (uuid: string, page: string, body: ScenePut) =>
    api.put<{ version: number; client_rev: number }>(`/boards/${uuid}/pages/${page}/scene`, body),

  saveSceneKeepalive: (uuid: string, page: string, body: ScenePut) =>
    void api.putKeepalive(`/boards/${uuid}/pages/${page}/scene`, body).catch(() => undefined),

  addPage: (uuid: string, body: { tab: string; after?: string; duplicate_of?: string }) =>
    api.post<BoardPagePayload>(`/boards/${uuid}/pages`, body),

  reorderPages: (uuid: string, tab: string, pages: string[]) =>
    api.put<{ pages: { uuid: string; position: number }[] }>(`/boards/${uuid}/pages/order`, { tab, pages }),

  deletePage: (uuid: string, tab: string, page: string) => api.delete<void>(`/boards/${uuid}/pages/${page}`, { tab }),

  duplicate: (uuid: string) => api.post<{ status: "copying"; uuid: string }>(`/boards/${uuid}/duplicate`),

  remove: (uuid: string) => api.delete<{ status: "deleting" }>(`/boards/${uuid}`),

  /** Reserve an upload for a picture (the lock holder only). */
  requestFile: (uuid: string, body: { tab: string; filename: string; size: number }) =>
    api.post<{ file: { uuid: string }; upload: { url: string; method: string; headers: Record<string, string> } }>(
      `/boards/${uuid}/files`,
      body,
    ),

  completeFile: (uuid: string, file: string) =>
    api.post<{ uuid: string; status: "ready" | "failed" | "pending" | "processing" }>(`/boards/${uuid}/files/${file}/complete`),

  /** Story 4: start a PDF import after a page (the lock holder only). */
  requestImport: (uuid: string, body: { tab: string; filename: string; size: number; after: string | null }) =>
    api.post<{ import: { uuid: string }; upload: { url: string; method: string; headers: Record<string, string> } }>(
      `/boards/${uuid}/imports`,
      body,
    ),

  completeImport: (uuid: string, importUuid: string) => api.post<ImportState>(`/boards/${uuid}/imports/${importUuid}/complete`),

  importState: (uuid: string, importUuid: string) => api.get<ImportState>(`/boards/${uuid}/imports/${importUuid}`),

  /** A picture's bytes, with the session's headers (an <img src> cannot carry them). */
  fileBytes: (uuid: string, file: string) => api.blob(`/boards/${uuid}/files/${file}`),
};
