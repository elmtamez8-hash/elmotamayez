import { parseScene, type BoardPagePayload } from "@/lib/whiteboard/api";

/**
 * The board's pages, restored for the canvas only when first READ.
 *
 * Opening a board used to run Excalidraw's `restoreElements` (with text
 * re-measured) on every page before the first one showed — the slow part of a
 * 100-page board. A page is now parsed at once (plain JSON) and restored the
 * first time it is shown, saved or drawn as a thumbnail; the pictures of every
 * page are still read up front, from the parsed scene, so a class never waits
 * for one (T072).
 *
 * A `Map` so every reader in the canvas keeps its `get` / `set` / `delete`.
 * ⚠️ WALKING IT RESTORES EVERY PAGE FIRST (`values`, `entries`, `forEach`,
 * `size`, `new Map(store)`): a copy of only the pages shown so far was a board
 * PDF with blank pages (caught in review).
 */
export class PageStore<E> extends Map<string, readonly E[]> {
  private parsed = new Map<string, readonly unknown[]>();
  private restore: (elements: readonly unknown[]) => readonly E[] = () => [];

  static of<E>(pages: BoardPagePayload[], restore: (elements: readonly unknown[]) => readonly E[]): PageStore<E> {
    const store = new PageStore<E>();
    store.restore = restore;
    for (const page of pages) store.parsed.set(page.uuid, parseScene(page).elements);
    return store;
  }

  override get(uuid: string): readonly E[] | undefined {
    const waiting = this.parsed.get(uuid);
    if (waiting !== undefined) {
      this.parsed.delete(uuid);
      super.set(uuid, this.restore(waiting));
    }
    return super.get(uuid);
  }

  override has(uuid: string): boolean {
    return this.parsed.has(uuid) || super.has(uuid);
  }

  override set(uuid: string, elements: readonly E[]): this {
    this.parsed?.delete(uuid); // `parsed` is not there yet while `Map`'s own constructor runs
    return super.set(uuid, elements);
  }

  override delete(uuid: string): boolean {
    const waiting = this.parsed.delete(uuid);
    return super.delete(uuid) || waiting;
  }

  private restoreAll(): void {
    for (const uuid of [...this.parsed.keys()]) this.get(uuid);
  }

  override get size(): number {
    this.restoreAll();
    return super.size;
  }

  override entries(): MapIterator<[string, readonly E[]]> {
    this.restoreAll();
    return super.entries();
  }

  override values(): MapIterator<readonly E[]> {
    this.restoreAll();
    return super.values();
  }

  override keys(): MapIterator<string> {
    this.restoreAll();
    return super.keys();
  }

  override [Symbol.iterator](): MapIterator<[string, readonly E[]]> {
    return this.entries();
  }

  override forEach(fn: (value: readonly E[], key: string, map: Map<string, readonly E[]>) => void, thisArg?: unknown): void {
    this.restoreAll();
    super.forEach(fn, thisArg);
  }

  /** Whether the page was restored already — a thumbnail key may read it only then. */
  isRestored(uuid: string): boolean {
    return super.has(uuid);
  }

  /** Every picture the pages use (all of them, or those named, in that order), restored or not. */
  pictureIds(only?: readonly string[]): string[] {
    const ids = (elements: readonly unknown[]) =>
      (elements as { type?: string; fileId?: string | null; isDeleted?: boolean }[]).flatMap((e) =>
        e.type === "image" && !e.isDeleted && e.fileId && !e.fileId.startsWith("template:") ? [e.fileId] : [],
      );
    if (only) return only.flatMap((uuid) => ids(this.parsed.get(uuid) ?? super.get(uuid) ?? [])); // super: no restore
    return [...[...this.parsed.values()].flatMap(ids), ...[...super.values()].flatMap((elements) => ids(elements))]; // super: no restore
  }
}
