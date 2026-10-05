/**
 * The starter library's file — a PUBLIC static file (`public/whiteboard/library/`),
 * so a plain `fetch` with no session, like the fonts in `arabic-font.ts`; the
 * one-door test exempts these two files only.
 */
const STARTER_URL = "/whiteboard/library/starter.excalidrawlib";

// Excalidraw reads the library again before every save: the file is fetched once a visit.
let starterItems: Promise<unknown[]> | null = null;

export function loadStarter<T>(): Promise<T[]> {
  starterItems ??= fetch(STARTER_URL)
    .then(async (response) => {
      if (!response.ok) throw new Error(`starter library ${response.status}`);
      return ((await response.json()) as { libraryItems: unknown[] }).libraryItems;
    })
    .catch((error: unknown) => {
      starterItems = null;
      throw error;
    });
  return starterItems as Promise<T[]>;
}
