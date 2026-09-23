import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";

/**
 * The top of a dashboard screen: the screen's icon, its title, one line saying
 * what it is for, and its actions.
 *
 * ⚠️ `h2`, NOT `h1`. The shell header already renders the page title as the
 * document's `h1` from the nav item it matched; a second `h1` splits the outline.
 *
 * ⚠️ THE ICON IS THE SAME ONE THE NAV SHOWS for this screen (`panel-nav.tsx`),
 * from `components/icons` — never a new drawing. The chip is the dashboard's
 * existing one (`DashboardCard`), one size up, so a screen and its cards read as
 * one family. `aria-hidden` by construction: the title beside it is the text.
 */
export function PageHeader({
  title,
  description,
  Icon,
  actions,
}: {
  title: string;
  description?: ReactNode;
  Icon?: ComponentType<IconProps>;
  actions?: ReactNode;
}) {
  return (
    <header className="banner-rise flex flex-wrap items-start justify-between gap-4">
      <div className="flex min-w-0 items-start gap-3">
        {Icon !== undefined && (
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary-ink">
            <Icon className="h-5 w-5" />
          </span>
        )}
        <div className="min-w-0">
          <h2 className="text-2xl font-bold text-ink">{title}</h2>
          {description !== undefined && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
        </div>
      </div>
      {actions !== undefined && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
  );
}
