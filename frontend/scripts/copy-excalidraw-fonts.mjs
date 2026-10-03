// Copies Excalidraw's self-hosted fonts into public/ so the board never asks a CDN.
//
// Excalidraw 0.18 resolves every font as `${window.EXCALIDRAW_ASSET_PATH}fonts/<Family>/…woff2`,
// so the `fonts/` folder itself must survive the copy (the README's "copy the contents" is wrong).
// Run by `predev` and `prebuild`, never `postinstall`: docker/frontend.Dockerfile runs `npm ci`
// before `COPY . .`, so this file does not exist yet at install time.
import { cpSync, existsSync, rmSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const from = join(root, "node_modules", "@excalidraw", "excalidraw", "dist", "prod", "fonts");
const to = join(root, "public", "excalidraw", "fonts");

if (!existsSync(from)) {
  console.error(`copy-excalidraw-fonts: ${from} is missing — run npm install first.`);
  process.exit(1);
}

rmSync(to, { recursive: true, force: true });
cpSync(from, to, { recursive: true });
console.log("copy-excalidraw-fonts: fonts → public/excalidraw/fonts");

// The equation editor's fonts (story 6), self-hosted for the same reason: the CSP
// allows no font CDN. MathLive is told this folder (`fontsDirectory`).
const mathFrom = join(root, "node_modules", "mathlive", "fonts");
const mathTo = join(root, "public", "mathlive", "fonts");
if (existsSync(mathFrom)) {
  rmSync(mathTo, { recursive: true, force: true });
  cpSync(mathFrom, mathTo, { recursive: true });
  console.log("copy-excalidraw-fonts: mathlive fonts → public/mathlive/fonts");
}
