/**
 * The page names the workspace it is showing, and reloads when the server says
 * that is no longer the one it is answering in.
 *
 * ⛔ THE CURRENT WORKSPACE IS PER ACCOUNT ON THE SERVER, NEVER PER TAB. Switching
 * (`POST /workspaces/{uuid}/switch`) reloads the tab that did it; every other tab
 * and device kept drawing workspace A while the server read — and WROTE — in
 * workspace B. So every request carries `X-Workspace: <uuid>` (the
 * `current_workspace` `/auth/me` returned), and `RefuseStaleWorkspace` answers a
 * mismatch with 409 `workspace_changed` before anything runs. `lib/api.ts` then
 * reloads, and the fresh `/auth/me` says where the account now is.
 *
 * No header before `/auth/me` has answered, and none for an account with no
 * workspace (a student, a guardian): the server checks nothing then.
 *
 * ⚠️ ONE RELOAD, NEVER A LOOP — the `stale-chunk.ts` rule. A reload is stamped in
 * `sessionStorage` (per tab, survives the reload); a second 409 inside the window
 * throws instead, and so does a tab whose storage is unavailable, because then we
 * cannot prove we have not just reloaded. The server makes the loop unlikely on
 * its own — the fresh page sends no header until `/auth/me` has answered — so
 * this is the belt to that pair of braces.
 */

export const WORKSPACE_HEADER = "X-Workspace";

/** The stable code `RefuseStaleWorkspace` answers with. */
export const WORKSPACE_CHANGED = "workspace_changed";

/** Per tab: a reload for a workspace change happened at this time. */
export const WORKSPACE_RELOAD_KEY = "workspace:reloaded-at";

/** Per tab: show «switched to …» once the reloaded page knows the name. */
export const WORKSPACE_NOTICE_KEY = "workspace:switched";

/** Per browser: written after a switch so the OTHER tabs reload at once. */
export const WORKSPACE_BROADCAST_KEY = "workspace:switch";

/** A second change inside this window is assumed to be a loop. */
export const WORKSPACE_RELOAD_GUARD_MS = 10_000;

let expected: string | null = null;

/** Set from every `/auth/me`-shaped answer the tab adopts; null clears it. */
export function setExpectedWorkspace(uuid: string | null | undefined): void {
  expected = uuid ?? null;
}

export function expectedWorkspace(): string | null {
  return expected;
}

/** Whether a failed response is the server's «this tab is in the wrong workspace». */
export function isWorkspaceChanged(status: number, body: unknown): boolean {
  return (
    status === 409 &&
    typeof body === "object" &&
    body !== null &&
    (body as { code?: unknown }).code === WORKSPACE_CHANGED
  );
}

/** Leave a note for the reloaded page to say where the account now is. */
export function markWorkspaceSwitched(): void {
  try {
    window.sessionStorage.setItem(WORKSPACE_NOTICE_KEY, "1");
  } catch {
    // No note, no notice — the reload itself is what matters.
  }
}

/** Read and clear the note. True once per switch. */
export function takeWorkspaceSwitchedNotice(): boolean {
  try {
    if (window.sessionStorage.getItem(WORKSPACE_NOTICE_KEY) === null) return false;

    window.sessionStorage.removeItem(WORKSPACE_NOTICE_KEY);

    return true;
  } catch {
    return false;
  }
}

/**
 * Reload this tab because the server moved it to another workspace.
 *
 * Returns false — and does not reload — when this tab already reloaded for the
 * same reason moments ago, or when storage cannot prove it did not.
 */
export function reloadForWorkspaceChange(now: number = Date.now()): boolean {
  try {
    const last = Number(window.sessionStorage.getItem(WORKSPACE_RELOAD_KEY) ?? "");

    if (Number.isFinite(last) && last > 0 && now - last < WORKSPACE_RELOAD_GUARD_MS) {
      return false;
    }

    window.sessionStorage.setItem(WORKSPACE_RELOAD_KEY, String(now));
  } catch {
    return false;
  }

  markWorkspaceSwitched();
  window.location.reload();

  return true;
}

/**
 * Tell the other tabs of this browser that the account switched.
 *
 * A `storage` event fires in every OTHER tab of the origin, never in the writer,
 * and only when the value changes — hence the timestamp. A nudge, not the guard:
 * a tab this misses (another device, storage blocked) still meets the 409.
 */
export function announceWorkspaceSwitch(): void {
  try {
    window.localStorage.setItem(WORKSPACE_BROADCAST_KEY, String(Date.now()));
  } catch {
    // The server-side guard covers the tab this could not reach.
  }
}

/** Reload this tab when another tab of the browser switches workspace. */
export function onWorkspaceSwitchElsewhere(reload: () => void): () => void {
  const listener = (event: StorageEvent) => {
    if (event.key !== WORKSPACE_BROADCAST_KEY || event.newValue === null) return;

    // A tab showing no workspace (a student's, or signed out) has nothing stale.
    if (expected === null) return;

    reload();
  };

  window.addEventListener("storage", listener);

  return () => window.removeEventListener("storage", listener);
}
