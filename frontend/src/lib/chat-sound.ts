/**
 * The short sound an incoming chat message makes.
 *
 * ⚠️ A BROWSER REFUSES `play()` UNTIL THE PAGE HAS HAD A USER GESTURE, and it
 * refuses per element. So the element is created and «primed» — played silently
 * and paused — inside the first tap or key press anywhere on the page, and the
 * later, gesture-less `play()` from a socket frame reuses THAT element. A fresh
 * `new Audio()` inside the frame handler is refused on most browsers, and the
 * refusal is a rejected promise nobody sees.
 *
 * ⚠️ AND A REFUSAL IS NOT AN ERROR. Every `play()` is caught and dropped: a chime
 * that could not play changes nothing a person can act on, and the message is
 * on the screen and in the bell either way.
 */

export const CHAT_SOUND_URL = "/sounds/message.wav";

const MUTE_KEY = "chat:sound-muted";

/** Fired on `window` when the mute preference changes, so every control agrees. */
export const CHAT_SOUND_CHANGED = "chat-sound:changed";

/**
 * Whether this incoming message deserves a sound.
 *
 * The reader hears about a message they are not looking at: the tab is in the
 * background, or the message belongs to a thread other than the open one. A
 * message landing in the thread on screen, in a visible tab, is already seen.
 */
export function shouldChime(input: {
  hidden: boolean;
  openConversationUuid: string | null;
  incomingConversationUuid: string;
  muted: boolean;
}): boolean {
  if (input.muted) return false;

  return input.hidden || input.incomingConversationUuid !== input.openConversationUuid;
}

/** Per browser, per person; a private window with no storage simply chimes. */
export function isChatSoundMuted(): boolean {
  try {
    return window.localStorage.getItem(MUTE_KEY) === "1";
  } catch {
    return false;
  }
}

export function setChatSoundMuted(muted: boolean): void {
  try {
    window.localStorage.setItem(MUTE_KEY, muted ? "1" : "0");
  } catch {
    // Not being able to remember the choice must not stop them making it for
    // this page.
  }

  window.dispatchEvent(new CustomEvent(CHAT_SOUND_CHANGED, { detail: muted }));
}

let element: HTMLAudioElement | null = null;

function audio(): HTMLAudioElement | null {
  if (typeof Audio === "undefined") return null;

  element ??= new Audio(CHAT_SOUND_URL);
  element.preload = "auto";

  return element;
}

/** Call inside a user gesture. Idempotent. */
export function primeChatSound(): void {
  const sound = audio();

  if (sound === null) return;

  sound.muted = true;

  sound
    .play()
    .then(() => {
      sound.pause();
      sound.currentTime = 0;
    })
    .catch(() => undefined)
    .finally(() => {
      sound.muted = false;
    });
}

export function playChatSound(): void {
  const sound = audio();

  if (sound === null) return;

  sound.currentTime = 0;
  sound.play().catch(() => undefined);
}
