import { Children, isValidElement, type ReactNode } from "react";

import { STAGGER_CLASS, staggerStyle } from "./stagger";

const COLUMNS = {
  2: "sm:grid-cols-2",
  3: "sm:grid-cols-3",
  4: "sm:grid-cols-2 lg:grid-cols-4",
} as const;

/**
 * The row of headline figures at the top of a staff screen — `StatTile`s, two
 * across on a 375px phone and up to four on a desk. Each tile arrives with the
 * shared stagger, so the strip settles as one set.
 *
 * ⚠️ TWO ACROSS ON A PHONE, NOT ONE. A strip of four stacked one per line is a
 * screen of numbers before the list the teacher came for; two per line keeps
 * them to two short rows. A three-tile strip leaves one tile alone on the
 * second phone row — accepted, rather than a third width to remember.
 *
 * `label` names the set for a screen reader («ملخّص الشحنات»): four figures with
 * no group name are four unrelated sentences.
 */
export function StatStrip({
  children,
  label,
  columns = 4,
}: {
  children: ReactNode;
  label: string;
  columns?: keyof typeof COLUMNS;
}) {
  const tiles = Children.toArray(children).filter(isValidElement);

  return (
    <div role="group" aria-label={label} className={`grid grid-cols-2 gap-3 ${COLUMNS[columns]}`}>
      {tiles.map((tile, index) => (
        <div key={tile.key ?? index} className={STAGGER_CLASS} style={staggerStyle(index)}>
          {tile}
        </div>
      ))}
    </div>
  );
}
