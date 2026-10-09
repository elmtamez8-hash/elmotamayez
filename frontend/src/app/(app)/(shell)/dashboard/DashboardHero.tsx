import type { ComponentType } from "react";

import type { IconProps } from "@/components/icons";

/**
 * The greeting at the top of all three dashboards: the burgundy band the public
 * site's how-it-works section uses, so the panel's first screen is recognisably
 * the same product as the page the reader signed in from.
 *
 * ⚠️ Still the `h2` the dashboards always had — the shell header owns the `h1`.
 */
export function DashboardHero({
  greeting,
  line,
  Icon,
}: {
  greeting: string;
  line: string;
  Icon: ComponentType<IconProps>;
}) {
  return (
    <section className="banner-rise bg-squares group relative isolate mb-8 overflow-hidden rounded-3xl bg-primary px-6 py-8 text-white shadow-xl shadow-primary/20 sm:px-10 sm:py-10">
      {/* The screen's own icon, oversized and faint in the end corner. */}
      <Icon
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-8 -end-6 -z-10 h-44 w-44 text-white/10 transition duration-700 ease-out group-hover:-rotate-6 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:rotate-0 motion-reduce:group-hover:scale-100"
      />
      <div className="flex items-center gap-4">
        <span
          aria-hidden="true"
          className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl bg-accent text-accent-foreground shadow-lg transition duration-300 ease-out group-hover:rotate-6 motion-reduce:transition-none motion-reduce:group-hover:rotate-0"
        >
          <Icon className="h-7 w-7" />
        </span>
        <div className="min-w-0">
          <h2 className="text-balance text-3xl font-extrabold leading-tight sm:text-4xl">{greeting}</h2>
          <p className="mt-1 text-white/80">{line}</p>
        </div>
      </div>
    </section>
  );
}
