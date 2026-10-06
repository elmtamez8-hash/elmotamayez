"use client";

import {
  ApplauseIcon,
  BalloonIcon,
  BrickIcon,
  BubblesIcon,
  CardIcon,
  ConfettiIcon,
  DrumrollIcon,
  EggIcon,
  GavelIcon,
  HeartIcon,
  PlaneIcon,
  StarIcon,
  StickIcon,
  ThumbUpIcon,
  TomatoIcon,
  WarningIcon,
  WhistleIcon,
  WrongIcon,
  type IconProps,
} from "@/components/icons";
import { MenuChip, MenuRow, MenuSelect } from "@/components/whiteboard/MenuParts";
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
    <div className="flex flex-col gap-2.5">
      {EFFECT_GROUPS.map((group) => (
        <MenuRow key={group.id} label={WB.effects.groups[group.id]}>
          {group.effects.map((kind) => (
            <MenuChip key={kind} icon={<EffectIcon kind={kind} />} onClick={() => props.onEffect(kind)}>
              {WB.effects.names[kind]}
            </MenuChip>
          ))}
        </MenuRow>
      ))}
      {props.onSticker && (
        <MenuRow label={WB.effects.stickers}>
          {STICKERS.map((name) => (
            <MenuChip key={name} onClick={() => props.onSticker?.(name)}>
              {stickerText(name)}
            </MenuChip>
          ))}
        </MenuRow>
      )}
      <div className="flex flex-col gap-2 border-t border-line pt-2.5">
        <label className="flex items-center justify-between gap-2 text-xs font-semibold text-ink-muted">
          {WB.effects.sound}
          <input type="checkbox" className="h-4 w-4 accent-primary" checked={props.sound} onChange={(event) => props.onSound(event.target.checked)} />
        </label>
        <MenuSelect
          label={WB.effects.trail}
          value={props.trail}
          options={TRAIL_STYLES.map((style) => ({ value: style, label: WB.effects.trails[style] }))}
          onChange={(style: TrailStyle) => props.onTrail(style)}
        />
      </div>
      {/* FR-035: said wherever a sound can be played. */}
      {props.sound && <p className="text-xs text-ink-muted">{WB.effects.soundHint}</p>}
    </div>
  );
}

const ICONS: Record<Effect, (props: IconProps) => React.ReactElement> = {
  applause: ApplauseIcon,
  balloons: BalloonIcon,
  party: ConfettiIcon,
  stars: StarIcon,
  drumroll: DrumrollIcon,
  attention: GavelIcon,
  hearts: HeartIcon,
  thumbs: ThumbUpIcon,
  bubbles: BubblesIcon,
  airplane: PlaneIcon,
  egg: EggIcon,
  tomato: TomatoIcon,
  brick: BrickIcon,
  whistle: WhistleIcon,
  stick: StickIcon,
  warning: WarningIcon,
  wrong: WrongIcon,
  yellowCard: CardIcon,
  redCard: CardIcon,
};

/** A drawn icon, not an emoji: the same on every system. The cards wear their colour. */
function EffectIcon({ kind }: { kind: Effect }) {
  const Icon = ICONS[kind];
  const colour = kind === "yellowCard" ? " text-star" : kind === "redCard" ? " text-danger-ink" : "";
  return <Icon className={`h-4 w-4${colour}`} />;
}
