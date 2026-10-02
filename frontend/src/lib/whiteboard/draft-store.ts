/**
 * The page drafts kept on this device (spec 039 · R-08): the real guarantee that a
 * network drop or a closed tab loses at most a couple of seconds. Raw IndexedDB
 * (constraint 6 — no `idb`): one database `whiteboard`, one store `drafts`.
 *
 * The key names the USER as well as the page — two accounts on one shared school
 * computer never restore each other's drafts.
 *
 * ⚠️ STORAGE CAN BE ABSENT OR REFUSE (a private window, a full quota, jsdom). Every
 * call is inside try/catch and answers `unavailable` rather than throwing: the
 * board keeps working and the indicator says the protection is off.
 */

export interface PageDraft {
  /** The page document, as `pageDocument()` builds it. */
  scene: string;
  /** The server version this draft was edited from. */
  ackedVersion: number;
  /** This tab's save counter when the draft was written. Never compared with a clock. */
  rev: number;
  /** True until the server has acknowledged this exact scene. */
  dirty: boolean;
}

export interface DraftStore {
  get(key: string): Promise<PageDraft | null | "unavailable">;
  put(key: string, draft: PageDraft): Promise<"ok" | "unavailable">;
  remove(key: string): Promise<"ok" | "unavailable">;
}

export function draftKey(board: string, page: string, user: string): string {
  return `board:${board}:page:${page}:user:${user}`;
}

export type DraftVerdict = "restore" | "ask" | "discard";

/**
 * What to do with a draft found on open (R-08):
 *  - nothing unsaved → discard;
 *  - unsaved, edited from the version the server still has → offer to restore;
 *  - unsaved, but the server moved on since → ASK. Never decided silently: either
 *    answer loses somebody's work, and only the teacher knows whose matters.
 */
export function classifyDraft(draft: PageDraft | null, serverVersion: number): DraftVerdict {
  if (draft === null || !draft.dirty) return "discard";

  return draft.ackedVersion === serverVersion ? "restore" : "ask";
}

const DB_NAME = "whiteboard";
const STORE = "drafts";

let opening: Promise<IDBDatabase> | null = null;

function open(): Promise<IDBDatabase> {
  opening ??= new Promise<IDBDatabase>((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
    request.onblocked = () => reject(new Error("blocked"));
  }).catch((error: unknown) => {
    opening = null; // a later call may try again
    throw error;
  });

  return opening;
}

function run<T>(mode: IDBTransactionMode, act: (store: IDBObjectStore) => IDBRequest): Promise<T> {
  return open().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const request = act(tx.objectStore(STORE));
        tx.oncomplete = () => resolve(request.result as T);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
      }),
  );
}

export const indexedDbDrafts: DraftStore = {
  async get(key) {
    try {
      const found = await run<PageDraft | undefined>("readonly", (store) => store.get(key));
      return found ?? null;
    } catch {
      return "unavailable";
    }
  },
  async put(key, draft) {
    try {
      await run("readwrite", (store) => store.put(draft, key));
      return "ok";
    } catch {
      return "unavailable";
    }
  },
  async remove(key) {
    try {
      await run("readwrite", (store) => store.delete(key));
      return "ok";
    } catch {
      return "unavailable";
    }
  },
};
