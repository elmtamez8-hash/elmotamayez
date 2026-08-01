/**
 * FAQ list built on <details>/<summary>.
 *
 * The native element is keyboard-operable and screen-reader-announced with no
 * ARIA wiring or state of our own — a hand-rolled accordion here would be more
 * code and less accessible (FR-044).
 */
export function FaqAccordion({
  items,
  heading,
}: {
  items: { question: string; answer: string }[];
  heading?: string;
}) {
  if (items.length === 0) return null;

  return (
    <div className="mx-auto max-w-3xl">
      {heading && (
        <h2 className="mb-8 text-center text-2xl font-extrabold text-ink sm:text-3xl">
          {heading}
        </h2>
      )}

      <div className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-white dark:bg-transparent">
        {items.map((item) => (
          <details key={item.question} className="group">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 p-5 text-start text-base font-semibold text-ink transition hover:bg-primary-soft focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">
              {item.question}
              <svg
                className="h-5 w-5 shrink-0 text-ink-muted transition group-open:rotate-180"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={2}
                aria-hidden="true"
              >
                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </summary>
            <p className="px-5 pb-5 text-sm leading-relaxed text-ink-muted">
              {item.answer}
            </p>
          </details>
        ))}
      </div>
    </div>
  );
}
