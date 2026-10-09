import Link from "next/link";
import { Children, isValidElement, type ComponentType, type ReactNode } from "react";

import type { IconProps } from "@/components/icons";
import { TONE_CLASSES, type StatusTone } from "@/lib/labels";

import { CARD_INTERACTIVE } from "./Card";
import { STAGGER_CLASS, staggerStyle } from "./stagger";

/**
 * The staff screens' list of records — an announcement, a blog post, an
 * assignment, a grading scheme, an accommodation. One row per record, one shape
 * for all of them, so `/manage/blog` and `/manage/assignments` read as the same
 * product. The contract is `docs/design/manage-pages.md`.
 *
 * ⚠️ A RECORD WITH A BODY IS A ROW HERE; A GRID OF FIGURES IS A `Table`. Rows
 * that are really columns (a shipment's address, weight, carrier, date) belong in
 * `Table`, whose own scroll wrapper keeps a 375px phone from panning sideways.
 *
 * No free-form `className`, the kit's rule: appearance is the props below.
 */

export type RecordMetaItem = {
  key: string;
  /** Said to everybody. `labelHidden` takes it off the screen, never out of the tree. */
  label: string;
  value: ReactNode;
  Icon?: ComponentType<IconProps>;
  labelHidden?: boolean;
};

/**
 * The `<ul>` that holds the rows. Every child is wrapped in its own `<li>` with
 * the shared staggered arrival (`stagger.ts`), so no page writes a delay.
 */
export function RecordList({
  children,
  label,
  labelledBy,
}: {
  children: ReactNode;
  /** What the list is a list OF, for a list with no visible heading. */
  label?: string;
  /** The id of the `SectionHeading` above it — preferred when there is one. */
  labelledBy?: string;
}) {
  const items = Children.toArray(children).filter(isValidElement);

  return (
    <ul className="space-y-3" aria-label={label} aria-labelledby={labelledBy}>
      {items.map((child, index) => (
        <li key={child.key ?? index} className={STAGGER_CLASS} style={staggerStyle(index)}>
          {child}
        </li>
      ))}
    </ul>
  );
}

/**
 * One record.
 *
 * Layout (RTL, so «start» is the right edge):
 *
 *     [chip] [title · status        ] [actions]      ≥ 640px
 *            [description           ]
 *            [meta · meta · meta    ]
 *     [children — full width               ]
 *
 *     [chip] [title · status        ]                 375px
 *            [description · meta    ]
 *     [actions — wrap under a hairline     ]
 *     [children — full width               ]
 *
 * ⚠️ THE ACTIONS ARE RENDERED ONCE AND MOVED BY THE GRID, NEVER RENDERED TWICE
 * FOR TWO BREAKPOINTS. A `ConfirmButton` carries its armed state; two copies
 * would arm one and show the other, and a hidden copy is still in the tab order
 * of whoever forgets `hidden`.
 *
 * ⚠️ WITH `href`, THE TITLE IS THE LINK AND ITS `::after` COVERS THE CARD — the
 * «stretched link». The whole row is a target without nesting a button inside an
 * anchor (invalid, and a screen reader reads the lot as one link). `actions` sit
 * above the overlay (`relative z-10`) so they stay pressable. jsdom has no layout,
 * so no test here can see the overlay's reach: open a screen once.
 */
export function RecordRow({
  title,
  Icon,
  tone = "info",
  status,
  description,
  meta,
  actions,
  href,
  level = 3,
  children,
}: {
  title: ReactNode;
  /**
   * The screen's own icon (the one `PageHeader` shows), or one for the record's kind.
   * ⚠️ REQUIRED: the chip always takes its column, so a row without an icon
   * would show an empty tinted square. Every staff screen has a nav icon.
   */
  Icon: ComponentType<IconProps>;
  /**
   * The chip's tone — the same five as `Badge`, from `TONE_CLASSES`, so the chip
   * can never name a colour `@theme` does not define. `info` (the brand tint) for
   * a live record; `neutral` for an archived or ended one.
   */
  tone?: StatusTone;
  /** A `Badge`/`StatusBadge`. The word carries the state; the tone is emphasis. */
  status?: ReactNode;
  description?: ReactNode;
  meta?: RecordMetaItem[];
  /** Buttons for THIS record. A destructive one is a `ConfirmButton`. */
  actions?: ReactNode;
  /** Makes the whole row open the record's own page. */
  href?: string;
  /** 3 under `PageHeader`'s h2; 4 when the list sits under a `SectionHeading`. */
  level?: 3 | 4;
  /** Full-width content under the row: an expandable editor, a long body. */
  children?: ReactNode;
}) {
  const Heading = level === 3 ? "h3" : "h4";

  return (
    <article
      className={`group relative grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-3 rounded-3xl border border-line bg-surface-raised p-4 shadow-sm focus-within:border-primary/40 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:gap-x-4 sm:p-5 ${CARD_INTERACTIVE}`}
    >
      <span
        aria-hidden="true"
        className={`grid h-10 w-10 shrink-0 place-items-center rounded-2xl transition duration-300 motion-safe:group-hover:rotate-6 motion-safe:group-hover:scale-110 ${
          TONE_CLASSES[tone]
        }`}
      >
        <Icon className="h-5 w-5" />
      </span>

      <div className="min-w-0 self-center">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <Heading className="min-w-0 break-words font-bold text-ink">
            {href !== undefined ? (
              <Link
                href={href}
                className="rounded-sm transition-colors after:absolute after:inset-0 after:rounded-3xl hover:text-primary-ink focus-visible:outline-none focus-visible:after:outline focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-primary"
              >
                {title}
              </Link>
            ) : (
              title
            )}
          </Heading>
          {status}
        </div>

        {description !== undefined && (
          <div className="mt-1 text-sm leading-relaxed text-ink-muted">{description}</div>
        )}

        {meta !== undefined && meta.length > 0 && (
          <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
            {meta.map((item) => (
              <div key={item.key} className="flex items-center gap-1.5">
                <dt className="flex items-center gap-1 text-ink-muted">
                  {item.Icon !== undefined && <item.Icon className="h-4 w-4 shrink-0" />}
                  <span className={item.labelHidden ? "sr-only" : undefined}>{item.label}</span>
                </dt>
                <dd className="font-semibold text-ink">
                  <bdi>{item.value}</bdi>
                </dd>
              </div>
            ))}
          </dl>
        )}
      </div>

      {actions !== undefined && (
        <div className="relative z-10 col-span-2 flex flex-wrap items-center gap-2 border-t border-line pt-3 sm:col-span-1 sm:border-t-0 sm:pt-0 sm:self-center">
          {actions}
        </div>
      )}

      {children !== undefined && (
        <div className="relative z-10 col-span-full">{children}</div>
      )}
    </article>
  );
}
