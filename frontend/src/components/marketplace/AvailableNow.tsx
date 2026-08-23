/**
 * «متاح الآن», in its two halves.
 *
 * ⚠️ TWO PIECES BECAUSE THEY SIT IN TWO PLACES, AND ONE FILE BECAUSE THEY ARE ONE
 * FACT. The dot rides the photo — that is where a reader looks for presence,
 * because every product they already use puts it there — and the words sit beside
 * the name, where a status about a person belongs. Split across two files they
 * would drift: a green dot with no label anywhere is decoration, and a label with
 * no dot is a sentence nobody scans.
 *
 * The dot is `aria-hidden` on purpose: the chip carries the meaning in words, and
 * a screen reader announcing a coloured circle beside it says the same thing
 * twice.
 */

/** The presence indicator, anchored to the corner of an avatar. */
export function AvailableNowDot() {
  return (
    <span
      /*
       * ⚠️ LOGICAL `end`, NEVER `right`. The page is RTL, so the corner a reader
       * expects is mirrored with it — `-end-1` lands bottom-left here and
       * bottom-right on a page that is ever LTR, with no second rule to write.
       *
       * The ring is the surface colour rather than white: it is what keeps the
       * dot legible against a dark photo AND against the card behind it, in both
       * themes, without a colour of its own.
       */
      className="absolute -bottom-1 -end-1 h-4 w-4 rounded-full bg-secondary ring-2 ring-surface-raised"
      aria-hidden="true"
    />
  );
}

/** The words, for the row that carries the teacher's name. */
export function AvailableNowChip() {
  return (
    <span className="inline-flex shrink-0 items-center rounded-full bg-secondary/15 px-2.5 py-1 text-xs font-semibold text-secondary-ink">
      {/* No dot inside the chip — it is on the photo now, and two of them read as
          two different things being true. */}
      متاح الآن
    </span>
  );
}
