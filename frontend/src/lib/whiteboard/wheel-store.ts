/**
 * The wheel's names and its picks, kept in THIS browser for THIS board (the
 * owner asked: closing the wheel must not lose the names, and the teacher needs
 * the order the students were picked in to ask them in turn).
 *
 * ⚠️ localStorage, never the server: these are students' names, a convenience for
 * the teacher at this computer. It can be absent (a private window) — then the
 * wheel still works and simply forgets on close.
 */

export interface WheelPick {
  name: string;
  /** ms since epoch. */
  at: number;
}

export interface WheelMemory {
  text: string;
  picks: WheelPick[];
}

const key = (board: string) => `whiteboard.wheel.${board}`;
const EMPTY: WheelMemory = { text: "", picks: [] };

export function loadWheel(board: string): WheelMemory {
  try {
    const raw = localStorage.getItem(key(board));
    if (!raw) return EMPTY;
    const parsed = JSON.parse(raw) as Partial<WheelMemory>;
    return {
      text: typeof parsed.text === "string" ? parsed.text : "",
      picks: Array.isArray(parsed.picks) ? parsed.picks.filter((p): p is WheelPick => typeof p?.name === "string" && typeof p?.at === "number") : [],
    };
  } catch {
    return EMPTY;
  }
}

export function saveWheel(board: string, memory: WheelMemory): void {
  try {
    localStorage.setItem(key(board), JSON.stringify(memory));
  } catch {
    // A private window or a full quota: the wheel forgets on close, nothing more.
  }
}
