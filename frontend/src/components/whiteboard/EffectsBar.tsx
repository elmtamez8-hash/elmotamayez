"use client";

import { Button } from "@/components/ui/Button";
import { EFFECT_GROUPS, TRAIL_STYLES, type Effect, type TrailStyle } from "@/lib/whiteboard/effects";
import { STICKERS, stickerText, type StickerName } from "@/lib/whiteboard/stickers";
import { WB } from "@/lib/whiteboard/strings";

export interface EffectsBarProps {
  sound: boolean;
  trail: TrailStyle;
  onEffect: (kind: Effect) => void;
  /** Stamping a sticker changes the page, so only the editor gets these. */
  onSticker?: (name: StickerName) => void;
  onSound: (on: boolean) => void;
  onTrail: (style: TrailStyle) => void;
}

/**
 * Encouragement and pointer effects (US10, US12), a row of the board's toolbar.
 * Every one of them is a display layer: none touches the page (FR-032).
 */
export function EffectsBar(props: EffectsBarProps) {
  return (
    <div className="flex flex-col gap-1 border-t border-line pt-1.5">
      {EFFECT_GROUPS.map((group) => (
        <div key={group.id} className="flex flex-wrap items-center gap-1">
          <span className="text-xs text-ink-muted">{WB.effects.groups[group.id]}</span>
          {group.effects.map((kind) => (
            <Button key={kind} size="sm" variant="ghost" onClick={() => props.onEffect(kind)}>
              <span aria-hidden>{WB.effects.icons[kind]}</span>
              {WB.effects.names[kind]}
            </Button>
          ))}
        </div>
      ))}
      {props.onSticker && (
        <div className="flex flex-wrap items-center gap-1">
          <span className="text-xs text-ink-muted">{WB.effects.stickers}</span>
          {STICKERS.map((name) => (
            <Button key={name} size="sm" variant="ghost" onClick={() => props.onSticker?.(name)}>
              {stickerText(name)}
            </Button>
          ))}
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3 text-xs">
        <label className="flex items-center gap-1">
          <input type="checkbox" checked={props.sound} onChange={(event) => props.onSound(event.target.checked)} />
          {WB.effects.sound}
        </label>
        <label className="flex items-center gap-1">
          {WB.effects.trail}
          <select
            value={props.trail}
            onChange={(event) => props.onTrail(event.target.value as TrailStyle)}
            className="rounded-md border border-line bg-surface px-1 py-0.5 text-ink"
          >
            {TRAIL_STYLES.map((style) => (
              <option key={style} value={style}>
                {WB.effects.trails[style]}
              </option>
            ))}
          </select>
        </label>
      </div>
      {/* FR-035: said wherever a sound can be played. */}
      {props.sound && <p className="text-xs text-ink-muted">{WB.effects.soundHint}</p>}
    </div>
  );
}
