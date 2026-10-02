/**
 * A board's pictures in this tab (spec 039 · US3, T072).
 *
 * Every picture's BYTES are fetched once, as the board opens — so the class never
 * waits on the network mid-lesson. They become data URLs and go to the canvas
 * only for the pages near the one shown (current ±2): a data URL is the bytes
 * again in a string, and 300 pages of them decoded at once is the memory a
 * teacher's laptop runs out of.
 *
 * ponytail: no concurrency cap on the prefetch — the browser already queues six
 * requests per host. Add one if a board with hundreds of pictures is measured slow.
 */

export interface Picture {
  id: string;
  dataURL: string;
  mimeType: "image/png" | "image/jpeg";
}

function toDataURL(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

export function createPictureCache(fetchBytes: (id: string) => Promise<Blob | null>) {
  const bytes = new Map<string, Promise<Blob | null>>();
  const given = new Set<string>();

  const fetchOnce = (id: string) => {
    let pending = bytes.get(id);
    if (!pending) {
      pending = fetchBytes(id).catch(() => null);
      bytes.set(id, pending);
    }
    return pending;
  };

  return {
    prefetch(ids: Iterable<string>): void {
      for (const id of ids) void fetchOnce(id);
    },

    /** The pictures among `ids` the canvas does not have yet; each is handed over once. */
    async take(ids: Iterable<string>): Promise<Picture[]> {
      const fresh = [...new Set(ids)].filter((id) => !given.has(id));
      const found = await Promise.all(
        fresh.map(async (id): Promise<Picture | null> => {
          const blob = await fetchOnce(id);
          if (!blob || given.has(id)) return null;
          given.add(id);
          return { id, dataURL: await toDataURL(blob), mimeType: blob.type === "image/jpeg" ? "image/jpeg" : "image/png" };
        }),
      );
      return found.filter((picture): picture is Picture => picture !== null);
    },

    /** A picture this tab just uploaded is already on the canvas. */
    given(id: string): void {
      given.add(id);
    },
  };
}
