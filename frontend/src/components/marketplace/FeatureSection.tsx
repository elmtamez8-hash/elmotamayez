import type { ComponentType } from "react";
import Image from "next/image";
import Link from "next/link";

export type Feature = {
  Icon: ComponentType<{ className?: string }>;
  title: string;
  body: string;
};

/**
 * A photo beside a short case and four icon points — the home page's
 * «who is this for» blocks (trust, live lessons, parents, teachers).
 *
 * `reverse` swaps the photo's side so consecutive blocks zig-zag instead of
 * stacking the same shape. The tilted `primary-soft` card behind the photo is
 * decoration only (`aria-hidden`). Every `image` must have a line in
 * `public/marketplace/LICENSES.md`, and every sentence here must be a feature
 * the product actually has — this is the front page, not a roadmap.
 */
export function FeatureSection({
  eyebrow,
  title,
  body,
  image,
  imageAlt,
  features,
  action,
  reverse = false,
  raised = false,
}: {
  eyebrow: string;
  title: string;
  body: string;
  image: string;
  imageAlt: string;
  features: Feature[];
  action?: { href: string; label: string };
  reverse?: boolean;
  raised?: boolean;
}) {
  return (
    <section className={raised ? "bg-surface-raised" : undefined}>
      <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:gap-16 lg:py-20">
        <div className={`reveal relative ${reverse ? "lg:order-last" : ""}`}>
          <div
            aria-hidden="true"
            className={`absolute inset-0 rounded-3xl bg-primary-soft ${reverse ? "-rotate-3" : "rotate-3"}`}
          />
          <div className="relative aspect-[4/3] overflow-hidden rounded-3xl bg-surface-raised shadow-lg">
            <Image
              src={image}
              alt={imageAlt}
              fill
              sizes="(min-width: 1024px) 50vw, 100vw"
              className="object-cover transition duration-700 ease-out hover:scale-105"
            />
          </div>
        </div>

        <div className="reveal">
          <p className="mb-3 inline-flex items-center gap-2 rounded-full bg-primary-soft px-3 py-1 text-sm font-bold text-primary-ink">
            <span className="h-1.5 w-1.5 rounded-full bg-accent" aria-hidden="true" />
            {eyebrow}
          </p>
          <h2 className="mb-4 text-2xl font-extrabold leading-snug text-ink sm:text-3xl">{title}</h2>
          <p className="mb-8 max-w-xl leading-relaxed text-ink-muted">{body}</p>

          <ul className="grid gap-5 sm:grid-cols-2">
            {features.map(({ Icon, title: featureTitle, body: featureBody }) => (
              <li key={featureTitle} className="group flex gap-3">
                <span
                  aria-hidden="true"
                  className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary-ink transition duration-300 ease-out group-hover:-translate-y-0.5 group-hover:bg-primary group-hover:text-white"
                >
                  <Icon className="h-5 w-5" />
                </span>
                <span>
                  <span className="mb-1 block font-bold text-ink">{featureTitle}</span>
                  <span className="block text-sm leading-relaxed text-ink-muted">{featureBody}</span>
                </span>
              </li>
            ))}
          </ul>

          {action && (
            <Link
              href={action.href}
              className="mt-8 inline-block rounded-xl bg-primary px-6 py-3 text-base font-semibold text-white transition hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {action.label}
            </Link>
          )}
        </div>
      </div>
    </section>
  );
}
