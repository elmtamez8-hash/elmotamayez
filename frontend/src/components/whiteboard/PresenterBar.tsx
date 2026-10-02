"use client";

import { Button } from "@/components/ui/Button";
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
    <div className="flex flex-wrap items-center gap-1 border-t border-line pt-1.5">
      <span className="text-xs text-ink-muted">{WB.presenter.title}</span>
      <Button size="sm" variant="ghost" onClick={props.onLaser}>
        {WB.presenter.laser}
      </Button>
      <Button size="sm" variant={props.spotlight ? "secondary" : "ghost"} expanded={props.spotlight} onClick={props.onSpotlight}>
        {props.spotlight ? WB.presenter.spotlightOff : WB.presenter.spotlight}
      </Button>
      <span className="text-xs text-ink-muted">{WB.presenter.timer}</span>
      {TIMER_PRESETS.map((minutes) => (
        <Button key={minutes} size="sm" variant="ghost" disabled={props.timerRunning} onClick={() => props.onTimer(minutes)}>
          {WB.presenter.minutes(minutes)}
        </Button>
      ))}
    </div>
  );
}
