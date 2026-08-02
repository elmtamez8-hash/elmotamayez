/**
 * Shared SVG icons.
 *
 * Paths are from Heroicons (MIT), copied in rather than imported: the set ships
 * as a dependency whose tree-shaking we would then have to trust, and a CDN
 * <script> is out of the question on pages that must render without network
 * access to a third party.
 *
 * Only icons used in more than one place live here. An icon that appears once
 * stays inline next to the markup that needs it — a barrel file of single-use
 * components is indirection, not reuse.
 *
 * Every icon is aria-hidden by default. They sit beside text that already says
 * what they mean; announcing them again is noise for a screen-reader user.
 */

type IconProps = {
  className?: string;
  /** Set only when the icon carries meaning no adjacent text conveys. */
  title?: string;
};

export function CheckIcon({ className = "h-4 w-4", title }: IconProps) {
  return (
    <svg
      className={className}
      viewBox="0 0 20 20"
      fill="currentColor"
      role={title ? "img" : undefined}
      aria-hidden={title ? undefined : true}
    >
      {title && <title>{title}</title>}
      <path
        fillRule="evenodd"
        d="M16.4 6.4a1 1 0 010 1.4l-6.6 6.6a1 1 0 01-1.4 0L5.1 11.1a1 1 0 111.4-1.4l2.6 2.6 5.9-5.9a1 1 0 011.4 0z"
        clipRule="evenodd"
      />
    </svg>
  );
}
