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
};
