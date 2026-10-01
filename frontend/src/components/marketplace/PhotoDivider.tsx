import Image from "next/image";

/**
 * A photo band between two home-page sections.
 *
 * The photo is decoration (`alt=""`): the sentence beside it carries the
 * meaning, and a screen reader announcing «سبورة عليها معادلات» between two
 * headings is noise. The primary-colour wash sits on the START side so the
 * white text always reads, whatever the photo is doing underneath — `text-white`
 * is allowed here only because it stands on `primary`.
 *
 * Every `src` must have a line in `public/marketplace/LICENSES.md`.
 */
export function PhotoDivider({ src, line }: { src: string; line: string }) {
  return (
    <div className="reveal mx-auto max-w-7xl px-4 py-6 sm:px-6">
      <div className="relative h-44 overflow-hidden rounded-3xl bg-primary sm:h-56">
        <Image src={src} alt="" fill sizes="(min-width: 1280px) 1280px, 100vw" className="object-cover" />
        <div className="absolute inset-0 bg-linear-to-l from-primary via-primary/75 to-primary/10" aria-hidden="true" />
        <div className="relative flex h-full max-w-2xl flex-col justify-center gap-3 px-6 sm:px-10">
          <span className="h-1 w-12 rounded-full bg-accent" aria-hidden="true" />
          <p className="text-xl font-extrabold leading-relaxed text-white sm:text-2xl">{line}</p>
        </div>
      </div>
    </div>
  );
}
