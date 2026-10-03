"use client";

import dynamic from "next/dynamic";
import { useEffect, useRef } from "react";

import { BoardLoading } from "@/components/whiteboard/BoardLoading";

declare global {
  interface Window {
    EXCALIDRAW_ASSET_PATH?: string | string[];
  }
}

/**
 * The client-only door to the canvas.
 *
 * `ssr: false` is refused inside a Server Component in Next 15, so it lives here, in a
 * client file, and the server page renders this. The asset path is set INSIDE the
 * loader, before the library module evaluates: Excalidraw reads it when it first builds
 * a font URL, and a value set later sends every font request to its esm.sh fallback,
 * which the production CSP blocks.
 */
const BoardCanvas = dynamic(
  () => {
    window.EXCALIDRAW_ASSET_PATH = "/excalidraw/";
    return import("./BoardCanvas");
  },
  { ssr: false, loading: () => <BoardLoading /> },
);

export function BoardCanvasClient({ boardUuid }: { boardUuid: string }) {
  // Excalidraw's setLanguage() writes <html lang/dir> and never restores them
  // (excalidraw#11963). Captured during render — child effects run before the parent's.
  const original = useRef<{ lang: string; dir: string } | null>(null);
  if (original.current === null && typeof document !== "undefined") {
    original.current = { lang: document.documentElement.lang, dir: document.documentElement.dir };
  }

  useEffect(
    () => () => {
      if (original.current) {
        document.documentElement.lang = original.current.lang;
        document.documentElement.dir = original.current.dir;
      }
    },
    [],
  );

  return <BoardCanvas boardUuid={boardUuid} />;
}
