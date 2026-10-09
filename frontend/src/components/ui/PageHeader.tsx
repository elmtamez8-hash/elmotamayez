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
 * filled brand tile, and the header sits in a card like the screen's own cards,
 * so all 89 screens open the same way. `aria-hidden` by construction: the title beside it is the text.
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
    <header className="banner-rise group relative isolate flex flex-wrap items-center justify-between gap-4 overflow-hidden rounded-3xl border border-line bg-surface-raised p-5 shadow-sm sm:p-6">
      {/* The screen's icon again, oversized and faint in the end corner. */}
      {Icon !== undefined && (
        <Icon
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-6 -end-4 -z-10 hidden h-32 w-32 sm:block text-primary-ink/10 transition duration-700 ease-out group-hover:-rotate-6 motion-reduce:transition-none motion-reduce:group-hover:rotate-0"
        />
      )}
      <div className="flex min-w-0 items-center gap-4">
        {Icon !== undefined && (
          <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-primary text-white shadow-md shadow-primary/20 transition duration-300 ease-out group-hover:rotate-6 motion-reduce:transition-none motion-reduce:group-hover:rotate-0">
            <Icon className="h-6 w-6" />
          </span>
        )}
        <div className="min-w-0">
          <h2 className="text-balance text-2xl font-extrabold leading-tight text-ink sm:text-3xl">{title}</h2>
          {description !== undefined && <p className="mt-1 text-sm leading-relaxed text-ink-muted">{description}</p>}
        </div>
      </div>
      {actions !== undefined && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
  );
}
