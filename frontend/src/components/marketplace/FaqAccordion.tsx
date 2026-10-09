import { ChevronDownIcon, QuestionIcon } from "@/components/icons";
/**
 * FAQ list built on <details>/<summary>.
 *
 * The native element is keyboard-operable and screen-reader-announced with no
 * ARIA wiring or state of our own — a hand-rolled accordion here would be more
 * code and less accessible (FR-044).
 *
 * With a `heading` (the home page) the section is two columns on wide screens:
 * the heading and its line on the start side, the questions beside it. Without
 * one (pricing, a teacher's tab) it is the list alone, so those pages keep their
 * own headings.
 */
export function FaqAccordion({
  items,
  heading,
  description,
}: {
  items: { question: string; answer: string }[];
  heading?: string;
  description?: string;
}) {
  if (items.length === 0) return null;

  const list = (
    <div className="space-y-3">
      {items.map((item) => (
        <details
          key={item.question}
          className="group rounded-2xl border border-line bg-surface-raised transition duration-300 ease-out open:border-primary/30 open:shadow-lg open:shadow-primary/10 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
        >
          <summary className="flex cursor-pointer list-none items-center gap-4 rounded-2xl p-4 text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:p-5 [&::-webkit-details-marker]:hidden">
            <span
              aria-hidden="true"
              className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary-ink transition duration-300 ease-out group-hover:scale-110 group-hover:bg-primary group-hover:text-white group-open:bg-primary group-open:text-white motion-reduce:group-hover:scale-100"
            >
              <QuestionIcon className="h-5 w-5" />
            </span>
            <span className="flex-1 text-base font-bold leading-snug text-ink transition-colors duration-300 group-hover:text-primary-ink">{item.question}</span>
            <span
              aria-hidden="true"
              className="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-line text-ink-muted transition duration-300 ease-out group-hover:border-primary group-hover:text-primary-ink group-open:rotate-180 group-open:border-primary group-open:text-primary-ink motion-reduce:transition-none"
            >
              <ChevronDownIcon className="h-4 w-4" />
            </span>
          </summary>
          <p className="pb-5 pe-5 ps-[4.5rem] text-sm leading-loose text-ink-muted sm:ps-[4.75rem]">
            {item.answer}
          </p>
        </details>
      ))}
    </div>
  );

  if (!heading) return <div className="mx-auto max-w-3xl">{list}</div>;

  return (
    <div className="grid gap-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-16">
      <div className="lg:sticky lg:top-28 lg:self-start">
        <span
          aria-hidden="true"
          className="mb-6 grid h-16 w-16 place-items-center rounded-2xl bg-primary text-white shadow-lg shadow-primary/30"
        >
          <QuestionIcon className="h-8 w-8" />
        </span>
        <h2 className="mb-4 text-balance text-3xl font-extrabold leading-tight text-ink sm:text-4xl">
          {heading}
        </h2>
        {description && (
          <p className="max-w-md leading-relaxed text-ink-muted">{description}</p>
        )}
      </div>
      {list}
    </div>
  );
}
