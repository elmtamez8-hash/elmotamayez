"use client";

import { MenuChip, MenuRow } from "@/components/whiteboard/MenuParts";
import { TIMER_PRESETS } from "@/lib/whiteboard/presenter";
import { WB } from "@/lib/whiteboard/strings";

export interface PresenterBarProps {
  spotlight: boolean;
  timerRunning: boolean;
  onLaser: () => void;
  onSpotlight: () => void;
  onTimer: (minutes: number) => void;
}

/** The presenter's tools (US8): laser, spotlight, countdown — none of them touches the page. */
export function PresenterBar(props: PresenterBarProps) {
  return (
    <div className="flex flex-col gap-2.5">
      <MenuRow label={WB.presenter.title}>
        <MenuChip onClick={props.onLaser}>{WB.presenter.laser}</MenuChip>
        <MenuChip active={props.spotlight} expanded={props.spotlight} onClick={props.onSpotlight}>
          {props.spotlight ? WB.presenter.spotlightOff : WB.presenter.spotlight}
        </MenuChip>
      </MenuRow>
      <MenuRow label={WB.presenter.timer}>
        {TIMER_PRESETS.map((minutes) => (
          <MenuChip key={minutes} disabled={props.timerRunning} onClick={() => props.onTimer(minutes)}>
            {WB.presenter.minutes(minutes)}
          </MenuChip>
        ))}
      </MenuRow>
    </div>
  );
}
