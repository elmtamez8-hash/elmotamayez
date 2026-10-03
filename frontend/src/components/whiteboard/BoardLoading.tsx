import { WB } from "@/lib/whiteboard/strings";

/**
 * The board's one loading screen, centred over the whole board: shown while the
 * canvas code arrives, while the board is fetched, and over Excalidraw until its
 * scene is ready — Excalidraw's own («Loading scene…») is hidden, because it is
 * drawn before its Arabic strings load and so appears in English.
 */
export function BoardLoading({ overlay = false }: { overlay?: boolean }) {
  return (
    <div
      role="status"
      aria-live="polite"
      className={`${overlay ? "absolute inset-0 z-50" : "h-dvh w-full"} flex flex-col items-center justify-center gap-4 bg-surface text-ink-muted`}
    >
      <span aria-hidden="true" className="h-10 w-10 animate-spin rounded-full border-4 border-line border-t-primary" />
      <p className="text-base">{WB.loading}</p>
    </div>
  );
}
