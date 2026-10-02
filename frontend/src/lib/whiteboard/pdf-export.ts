/**
 * The whole board as one PDF, built IN THE BROWSER (story 5): pdf-lib (MIT,
 * owner-approved 2026-10-03), loaded only when the teacher exports. One PDF
 * page per board page, the page's own height — a page grown to three screens
 * is one tall PDF page, as the student scrolled it in class.
 *
 * Saved without object streams (`useObjectStreams: false`) so a test can count
 * its pages in the bytes.
 */
export async function boardPdf(pageCount: number, drawPage: (index: number) => Promise<Blob>, onPage: (done: number) => void): Promise<Blob> {
  const { PDFDocument } = await import("pdf-lib");
  const doc = await PDFDocument.create();
  for (let index = 0; index < pageCount; index++) {
    const jpg = await doc.embedJpg(await (await drawPage(index)).arrayBuffer());
    // Half the pixels in points: a 1920-wide page is 960 pt, a little over A4 landscape.
    const width = jpg.width / 2;
    const height = jpg.height / 2;
    doc.addPage([width, height]).drawImage(jpg, { x: 0, y: 0, width, height });
    onPage(index + 1);
  }
  const bytes = await doc.save({ useObjectStreams: false });
  return new Blob([bytes as BlobPart], { type: "application/pdf" });
}
