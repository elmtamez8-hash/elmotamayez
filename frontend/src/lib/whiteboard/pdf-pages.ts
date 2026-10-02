import { PAGE_WIDTH } from "@/lib/whiteboard/page-model";

/** The most pages one import adds: each is drawn and uploaded by the teacher's own browser. */
export const IMPORT_MAX_PAGES = 100;

/** `total` is how many are added; `inFile`, how many the PDF has. */
export type PdfPage = { number: number; total: number; inFile: number; file: File; width: number; height: number };

/**
 * A PDF read IN THE BROWSER (PDF.js, Apache-2.0), page by page: each drawn
 * 1920 px wide — the board's page width — as a JPEG the board uploads like
 * any picture. The server stores pictures and converts nothing (owner,
 * 2026-10-02). PDF.js is loaded only when a PDF is chosen.
 */
export async function* pdfPages(file: File): AsyncGenerator<PdfPage> {
  const pdfjs = await import("pdfjs-dist");
  // One worker for the tab; `destroy()` below leaves a port it was given alive.
  pdfjs.GlobalWorkerOptions.workerPort ??= new Worker(new URL("pdfjs-dist/build/pdf.worker.min.mjs", import.meta.url), {
    type: "module",
  });
  const task = pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) });
  const pdf = await task.promise;
  const name = file.name.replace(/\.pdf$/i, "");
  try {
    const total = Math.min(pdf.numPages, IMPORT_MAX_PAGES);
    for (let number = 1; number <= total; number++) {
      const page = await pdf.getPage(number);
      const viewport = page.getViewport({ scale: PAGE_WIDTH / page.getViewport({ scale: 1 }).width });
      const canvas = document.createElement("canvas");
      canvas.width = PAGE_WIDTH;
      canvas.height = Math.round(viewport.height);
      // "print": the "display" intent paces itself on animation frames, which a
      // hidden tab never gives, so an import paused whenever the teacher looked
      // at another tab. A white page under the drawing by default.
      await page.render({ canvas, viewport, intent: "print" }).promise;
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.85));
      const { width, height } = canvas;
      page.cleanup();
      canvas.width = 0; // let the browser free the bitmap now, not at the next GC
      if (!blob) throw new Error("A PDF page could not be drawn.");
      yield { number, total, inFile: pdf.numPages, file: new File([blob], `${name}-${number}.jpg`, { type: "image/jpeg" }), width, height };
    }
  } finally {
    await task.destroy();
  }
}
