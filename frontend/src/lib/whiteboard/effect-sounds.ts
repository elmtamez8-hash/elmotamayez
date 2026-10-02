/**
 * The encouragement sounds (spec 039 · US10). Students hear them only if the
 * teacher shares the TAB's audio with the screen (FR-035) — the effects bar says
 * so next to the buttons.
 *
 * Two kinds:
 *  - RECORDINGS in `public/whiteboard/sounds/` — public domain / CC0 from
 *    Wikimedia Commons, each source in that folder's LICENSE.txt. Cut short
 *    with a fade: a 12-second recording must not run on over the lesson.
 *  - SYNTHESISED (Web Audio): the chime of the stars, a balloon's pop and the
 *    gavel — no free recording of the last two was found.
 */

const SOUNDS = "/whiteboard/sounds";

/** A recording, how long to let it play, and how loud. */
// Chosen by measured loudness: a quieter applause recording (RMS ~0.05 against
// this one's ~0.17) was dropped — a compressed stream would have lost it.
const RECORDINGS = {
  hurray: { file: "clapping-hurray.ogg", seconds: 4.5, volume: 1 },
  blower: { file: "party-blower.ogg", seconds: 1.6, volume: 0.7 },
  drumroll: { file: "drum-roll.ogg", seconds: 6, volume: 0.9 },
} as const;

export type Recording = keyof typeof RECORDINGS;

const players = new Map<Recording, HTMLAudioElement>();

/**
 * Play a recording from its start, faded out after its allotted time. Resolves
 * when it stops — the drum roll's end is what releases the stars.
 */
export function playRecording(name: Recording): Promise<void> {
  if (typeof window === "undefined" || typeof window.Audio !== "function") return Promise.resolve();
  const { file, seconds, volume } = RECORDINGS[name];

  let audio = players.get(name);
  if (!audio) {
    audio = new window.Audio(`${SOUNDS}/${file}`);
    audio.preload = "auto";
    players.set(name, audio);
  }
  const player = audio;
  player.pause();
  player.currentTime = 0;
  player.volume = volume;

  return new Promise<void>((resolve) => {
    let fade = 0;
    const stop = () => {
      window.clearInterval(fade);
      window.clearTimeout(cut);
      player.pause();
      player.removeEventListener("ended", stop);
      resolve();
    };
    // The last half second fades out, so a cut never clicks.
    const cut = window.setTimeout(() => {
      fade = window.setInterval(() => {
        player.volume = Math.max(0, player.volume - volume / 10);
        if (player.volume <= 0.01) stop();
      }, 50);
    }, Math.max(0, seconds - 0.5) * 1000);
    player.addEventListener("ended", stop);
    // A refused play (no user gesture yet, or audio blocked) is silence, not an error.
    player.play().catch(stop);
  });
}

let context: AudioContext | null = null;

function audio(): AudioContext | null {
  if (typeof window === "undefined" || typeof window.AudioContext !== "function") return null;
  context ??= new window.AudioContext();
  void context.resume().catch(() => undefined);
  return context;
}

function noise(ctx: AudioContext, seconds: number): AudioBuffer {
  const buffer = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * seconds), ctx.sampleRate);
  const data = buffer.getChannelData(0);
  for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
  return buffer;
}

function burst(ctx: AudioContext, at: number, frequency: number, length: number, volume: number, type: BiquadFilterType = "bandpass") {
  const source = ctx.createBufferSource();
  source.buffer = noise(ctx, length);
  const filter = ctx.createBiquadFilter();
  filter.type = type;
  filter.frequency.value = frequency;
  const gain = ctx.createGain();
  gain.gain.setValueAtTime(volume, at);
  gain.gain.exponentialRampToValueAtTime(0.001, at + length);
  source.connect(filter).connect(gain).connect(ctx.destination);
  source.start(at);
}

function tone(ctx: AudioContext, at: number, from: number, to: number, length: number, type: OscillatorType, volume: number) {
  const osc = ctx.createOscillator();
  osc.type = type;
  osc.frequency.setValueAtTime(from, at);
  osc.frequency.exponentialRampToValueAtTime(to, at + length);
  const gain = ctx.createGain();
  gain.gain.setValueAtTime(0.0001, at);
  gain.gain.exponentialRampToValueAtTime(volume, at + 0.01);
  gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
  osc.connect(gain).connect(ctx.destination);
  osc.start(at);
  osc.stop(at + length + 0.05);
}

/** A short bright fanfare — the end of the party blower. */
export function playFanfare(): void {
  const ctx = audio();
  if (!ctx) return;
  const now = ctx.currentTime + 0.9;
  [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => tone(ctx, now + i * 0.11, f, f, 0.4, "triangle", 0.22));
}

/** A rising chime — the stars. */
export function playChime(): void {
  const ctx = audio();
  if (!ctx) return;
  const now = ctx.currentTime + 0.02;
  [1318.5, 1567.98, 2093, 2637].forEach((f, i) => tone(ctx, now + i * 0.09, f, f, 0.8, "sine", 0.18));
}

/** One balloon popping: a sharp crack of noise. */
export function playPop(): void {
  const ctx = audio();
  if (!ctx) return;
  const now = ctx.currentTime + 0.005;
  burst(ctx, now, 1800, 0.09, 0.9, "highpass");
  burst(ctx, now, 400, 0.05, 0.5);
}

/** Three knocks of a judge's gavel on wood: a low thump under a short click. */
export function playGavel(): void {
  const ctx = audio();
  if (!ctx) return;
  const now = ctx.currentTime + 0.02;
  [0, 0.32, 0.64].forEach((offset) => {
    tone(ctx, now + offset, 190, 70, 0.18, "sine", 0.9);
    burst(ctx, now + offset, 2400, 0.03, 0.6);
  });
}
