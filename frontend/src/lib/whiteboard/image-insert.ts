import { uploadToTicket } from "@/lib/api";
import { boards } from "@/lib/whiteboard/api";

/**
 * A picture placed on a board (spec 039 · US3, T073) — through the image tool, a
 * paste, or a drop: all three reach Excalidraw's `generateIdForFile`, which hands
 * over the ORIGINAL file and waits for an id. That id is our file's uuid, given
 * only once the server has the bytes and has called them a PNG or a JPEG — so a
 * page never names a file the server would refuse (`unknown_file`), and no
 * picture's bytes are ever stored inside a scene.
 *
 * Excalidraw shows its own (1440 px) copy from memory; the server keeps ours,
 * downscaled here to 2560 px and re-encoded to PNG or JPEG (the only two the
 * server takes — an SVG, a GIF or a WebP becomes a PNG).
 */

export const MAX_SIDE = 2560;

/** The size to draw a w×h picture at so neither side passes `max`. */
export function fitWithin(width: number, height: number, max = MAX_SIDE): { width: number; height: number } {
  const scale = Math.min(1, max / Math.max(width, height));

  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

export class ImageRefused extends Error {
  constructor() {
    super("image-refused");
    this.name = "ImageRefused";
  }
}

async function loadImage(file: Blob): Promise<HTMLImageElement> {
  const url = URL.createObjectURL(file);
  try {
    const image = new Image();
    image.src = url;
    await image.decode();
    return image;
  } finally {
    URL.revokeObjectURL(url);
  }
}

/** A PNG or JPEG of at most `MAX_SIDE` px a side. A JPEG already small enough is sent as it is. */
export async function prepareImage(file: Blob): Promise<Blob> {
  // `createImageBitmap`, not `img.decode()`: decode waits for a rendered frame,
  // which a hidden tab never gives, so an import stalled behind another tab.
  // An SVG keeps `<img>`: Firefox refuses it to `createImageBitmap`.
  const image = await (file.type === "image/svg+xml" ? loadImage(file) : createImageBitmap(file)).catch(() => {
    throw new ImageRefused();
  });
  const done = () => void ("close" in image && image.close());
  const size = fitWithin(image.width || 1, image.height || 1);
  const isJpeg = file.type === "image/jpeg";
  const fits = size.width === image.width && size.height === image.height;

  if (fits && (isJpeg || file.type === "image/png")) {
    done();
    return file;
  }

  const canvas = document.createElement("canvas");
  canvas.width = size.width;
  canvas.height = size.height;
  canvas.getContext("2d")?.drawImage(image, 0, 0, size.width, size.height);
  done();

  const out = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, isJpeg ? "image/jpeg" : "image/png", 0.9));
  if (!out) throw new ImageRefused();
  return out;
}

/**
 * Upload one picture to a board and answer its file id. Ticket → bytes →
 * completion, in one function: stopping after the second would leave a pending
 * file the page could not name.
 */
export async function uploadBoardImage(
  boardUuid: string,
  tab: string,
  file: File,
  prepare: (file: Blob) => Promise<Blob> = prepareImage,
): Promise<string> {
  const bytes = await prepare(file);
  const filename = file.name.replace(/\.[^.]+$/, "") + (bytes.type === "image/jpeg" ? ".jpg" : ".png");

  const ticket = await boards.requestFile(boardUuid, { tab, filename, size: bytes.size });
  await uploadToTicket(ticket.upload, bytes);
  const settled = await boards.completeFile(boardUuid, ticket.file.uuid);

  if (settled.status !== "ready") throw new ImageRefused();
  return ticket.file.uuid;
}
