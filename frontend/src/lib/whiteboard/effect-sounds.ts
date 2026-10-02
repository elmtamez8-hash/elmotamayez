import type { Celebration } from "@/lib/whiteboard/effects";

/**
 * The celebrations' sounds, made by the browser (Web Audio) — no sound files, so
 * nothing to license, host or download (T125 asked for CC0 files; synthesis
 * needs neither). Students hear them only if the teacher shares the TAB's audio
 * with the screen (FR-035), which the effects bar says next to the buttons.
 *
 * ponytail: four short synthesised sounds, not recordings. A real applause sample
 * is the upgrade if the owner wants it, through a CC0 file in public/.
 */

let context: AudioContext | null = null;

function audio(): AudioContext | null {
  if (typeof window === "undefined" || typeof window.AudioContext !== "function") return null;
  context ??= new window.AudioContext();
  // A context made before a user gesture starts suspended; the button press is one.
  void context.resume().catch(() => undefined);
  return context;
}

function noiseBuffer(ctx: AudioContext, seconds: number): AudioBuffer {
  const buffer = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * seconds), ctx.sampleRate);
  const data = buffer.getChannelData(0);
  for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
  return buffer;
}

/** A burst of filtered noise — one clap, or one balloon pop. */
function burst(ctx: AudioContext, at: number, frequency: number, length: number, volume: number) {
  const source = ctx.createBufferSource();
  source.buffer = noiseBuffer(ctx, length);
  const filter = ctx.createBiquadFilter();
  filter.type = "bandpass";
  filter.frequency.value = frequency;
  const gain = ctx.createGain();
  gain.gain.setValueAtTime(volume, at);
  gain.gain.exponentialRampToValueAtTime(0.001, at + length);
  source.connect(filter).connect(gain).connect(ctx.destination);
  source.start(at);
}

/** One tone with a soft decay. */
function tone(ctx: AudioContext, at: number, frequency: number, length: number, type: OscillatorType, volume: number) {
  const osc = ctx.createOscillator();
  osc.type = type;
  osc.frequency.value = frequency;
  const gain = ctx.createGain();
  gain.gain.setValueAtTime(0.0001, at);
  gain.gain.exponentialRampToValueAtTime(volume, at + 0.02);
  gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
  osc.connect(gain).connect(ctx.destination);
  osc.start(at);
  osc.stop(at + length + 0.05);
}

export function playCelebration(kind: Celebration): void {
  const ctx = audio();
  if (!ctx) return;
  const now = ctx.currentTime + 0.02;

  switch (kind) {
    case "applause":
      // Many hands, each clapping a little out of time with the others.
      for (let i = 0; i < 70; i++) burst(ctx, now + Math.random() * 2.2, 1200 + Math.random() * 1400, 0.05, 0.35);
      break;
    case "balloons":
      for (let i = 0; i < 6; i++) burst(ctx, now + 0.3 + i * 0.35 + Math.random() * 0.1, 900, 0.08, 0.6);
      break;
    case "party":
      burst(ctx, now, 600, 0.15, 0.7);
      [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => tone(ctx, now + 0.12 + i * 0.12, f, 0.35, "triangle", 0.25));
      break;
    case "stars":
      [1318.5, 1567.98, 2093, 2637].forEach((f, i) => tone(ctx, now + i * 0.09, f, 0.8, "sine", 0.18));
      break;
  }
}
