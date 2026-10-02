import type { Metadata } from "next";

import { BoardCanvasClient } from "@/components/whiteboard/BoardCanvasClient";

export const metadata: Metadata = { title: "السبّورة" };

/**
 * The board, full screen and outside the shell — the tab the teacher shares (spec 039).
 *
 * ⚠️ IT UNWRAPS `params` AND NOTHING ELSE. The token lives in the browser
 * (`lib/api.ts` answers no token on the server), so the board is fetched by the
 * client canvas, behind the sign-in guard of `whiteboard/layout.tsx`.
 */
export default async function WhiteboardPage({ params }: { params: Promise<{ board: string }> }) {
  const { board } = await params;

  return <BoardCanvasClient boardUuid={board} />;
}
