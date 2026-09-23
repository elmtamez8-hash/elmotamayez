import type { ComponentType, ReactNode } from "react";

import type { IconProps } from "@/components/icons";

/**
 * A section's title inside a screen, with its icon — the `DashboardCard` chip at
 * the same size, so a section and a dashboard card read as one family.
 *
 * `id` is required because every caller labels its `<section>` with it
 * (`aria-labelledby`): a heading nobody points at is a landmark with no name.
 */
export function SectionHeading({
  id,
  title,
  Icon,
  description,
  level = 3,
}: {
  id: string;
  title: string;
  Icon?: ComponentType<IconProps>;
  description?: ReactNode;
  level?: 3 | 4;
}) {
  const Tag = level === 3 ? "h3" : "h4";

  return (
    <div className="space-y-1">
      <Tag
        id={id}
        className={`flex items-center gap-2 font-bold text-ink ${level === 3 ? "text-lg" : "text-sm"}`}
      >
        {Icon !== undefined && (
          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary-ink">
            <Icon className="h-4 w-4" />
          </span>
        )}
        <span>{title}</span>
      </Tag>
      {description !== undefined && <p className="text-sm text-ink-muted">{description}</p>}
    </div>
  );
}
