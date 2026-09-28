"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode, type RefObject } from "react";

import { ChevronEndIcon, ChevronStartIcon, CloseIcon } from "@/components/icons";
import { arabicNumber } from "@/lib/numerals";

/**
 * A picture, enlarged in the page — never in a new tab (owner decision,
 * 2026-09-28).
 *
 * ⚠️ A NATIVE `<dialog>` OPENED WITH `showModal()`, like `Modal`, and for the same
 * reason: the platform traps focus, makes everything behind it inert and wires
 * Escape. What the platform does NOT do is returned here by hand: focus goes
 * back to the picture that opened the viewer, and the page behind stops
 * scrolling while it is open.
 *
 * ⚠️ «التالي» POINTS LEFT. The product is right-to-left, so the next picture is
 * at the END of the line, which is the left edge — the on-screen arrow sits
 * there and ArrowLeft moves to it. Reading the document's direction rather than
 * assuming it keeps the component honest on the day a left-to-right page uses it.
 *
 * Zoom is a press on the picture (twice the fitted size, and back) or a pinch
 * on a touch screen; a zoomed picture scrolls inside the viewer to pan. The
 * browser's own pinch is switched off INSIDE the viewer only (`touch-action`),
 * because it would zoom the page behind rather than the picture.
 */
export type LightboxImage = { src: string; alt: string };

const MAX_ZOOM = 4;
/** A click within this long of a pinch ending belongs to the pinch. */
const PINCH_CLICK_GRACE_MS = 350;
/** How far one arrow press moves a zoomed picture. */
const PAN: Record<string, [number, number]> = {
  ArrowLeft: [-80, 0],
  ArrowRight: [80, 0],
  ArrowUp: [0, -80],
  ArrowDown: [0, 80],
};

export function ImageLightbox({
  images,
  index,
  onIndexChange,
  onClose,
}: {
  images: readonly LightboxImage[];
  /** The picture on screen, or null when the viewer is closed. */
  index: number | null;
  onIndexChange: (index: number) => void;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const closeButton = useRef<HTMLButtonElement>(null);
  const [zoom, setZoom] = useState(1);
  const [failed, setFailed] = useState(false);
  const baseWidth = useRef(0);
  const image = useRef<HTMLImageElement>(null);
  const pointers = useRef(new Map<number, { x: number; y: number }>());
  const pinch = useRef<{ distance: number; zoom: number } | null>(null);
  const pinched = useRef(false);
  const pinchEndedAt = useRef(Number.NEGATIVE_INFINITY);
  const stage = useRef<HTMLDivElement>(null);

  const open = index !== null && images[index] !== undefined;

  // Where focus goes back to, and the page's own scroll, both held while open.
  // ⚠️ DECLARED BEFORE the `showModal()` effect: effects run in order, and
  // `showModal()` moves focus into the dialog — read after it, «the opener» is
  // the dialog's own first button.
  useEffect(() => {
    if (!open) return;

    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const root = document.documentElement;
    const overflow = root.style.overflow;
    const gutter = root.style.scrollbarGutter;

    root.style.overflow = "hidden";
    /*
     * ⚠️ AND THE GUTTER TOO. `globals.css` gives `html` `scrollbar-gutter:
     * stable`, so with its scrollbar hidden the page still keeps the scrollbar's
     * strip — and the backdrop, sized to the viewport inside it, left ~15px of
     * page showing down one edge (measured in Chrome). Released while open,
     * restored on close; the page behind shifts under the backdrop, unseen.
     */
    root.style.scrollbarGutter = "auto";

    return () => {
      root.style.overflow = overflow;
      root.style.scrollbarGutter = gutter;
      pointers.current.clear();
      pinch.current = null;
      opener?.focus();
    };
  }, [open]);

  useEffect(() => {
    const dialog = ref.current;

    if (dialog === null) return;

    if (open && !dialog.open) {
      dialog.showModal();
      // Not `autoFocus`: React applies that during the commit, BEFORE the effect
      // above has recorded the opener — and focus would then «return» to the
      // close button of a viewer that no longer exists.
      closeButton.current?.focus();
    }
    if (!open && dialog.open) dialog.close();
  }, [open]);

  // Every picture opens fitted — and a new link for the same picture (a
  // refreshed signature) is a fresh chance to load it.
  const currentSrc = index !== null ? images[index]?.src ?? null : null;

  useEffect(() => {
    setZoom(1);
    setFailed(false);
  }, [index, currentSrc]);

  const last = images.length - 1;
  const hasPrevious = index !== null && index > 0;
  const hasNext = index !== null && index < last;

  const previous = useCallback(() => {
    if (index !== null && index > 0) onIndexChange(index - 1);
  }, [index, onIndexChange]);

  const next = useCallback(() => {
    if (index !== null && index < last) onIndexChange(index + 1);
  }, [index, last, onIndexChange]);

  if (!open) return null;

  const current = images[index];
  const rtl = isRightToLeft(ref);

  const close = () => ref.current?.close();

  const toggleZoom = () => {
    // The click a browser may fire at the end of a pinch is not a tap.
    if (performance.now() - pinchEndedAt.current < PINCH_CLICK_GRACE_MS) return;

    if (zoom === 1 && image.current !== null) baseWidth.current = image.current.clientWidth;

    setZoom((value) => (value === 1 ? 2 : 1));
  };

  const onPointerDown = (event: React.PointerEvent) => {
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
    // The finger keeps reporting to the stage even when it slides off it.
    (event.currentTarget as Element).setPointerCapture?.(event.pointerId);

    if (pointers.current.size === 2) {
      if (zoom === 1 && image.current !== null) baseWidth.current = image.current.clientWidth;
      pinch.current = { distance: spread(pointers.current), zoom };
    }
  };

  const onPointerMove = (event: React.PointerEvent) => {
    if (!pointers.current.has(event.pointerId)) return;

    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pinch.current !== null && pointers.current.size === 2 && pinch.current.distance > 0) {
      const scale = spread(pointers.current) / pinch.current.distance;

      pinched.current = true;
      setZoom(Math.min(MAX_ZOOM, Math.max(1, pinch.current.zoom * scale)));
    }
  };

  const onPointerEnd = (event: React.PointerEvent) => {
    pointers.current.delete(event.pointerId);

    if (pointers.current.size < 2 && pinch.current !== null) {
      pinch.current = null;

      if (pinched.current) {
        pinched.current = false;
        pinchEndedAt.current = performance.now();
      }
    }
  };

  const zoomed = zoom > 1;

  return (
    <dialog
      ref={ref}
      aria-label={current.alt === "" ? "عارض الصور" : current.alt}
      // Every way out is one `close` event — see `Modal`.
      onClose={onClose}
      onKeyDown={(event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          close();
        }

        // Zoomed, the arrows move ACROSS the picture; fitted, between pictures.
        const pan = PAN[event.key];

        if (zoomed && pan !== undefined) {
          event.preventDefault();
          stage.current?.scrollBy?.({ left: pan[0], top: pan[1] });

          return;
        }

        if (event.key === "ArrowLeft") (rtl ? next : previous)();
        if (event.key === "ArrowRight") (rtl ? previous : next)();
      }}
      onClick={(event) => {
        // The backdrop and the empty stage around the picture — never the
        // picture or a control.
        if (event.target === ref.current || (event.target as HTMLElement).dataset.lightboxStage !== undefined) {
          close();
        }
      }}
      className="fixed inset-0 m-0 h-auto max-h-none w-auto max-w-none border-0 bg-overlay p-0 text-ink"
    >
      <div
        ref={stage}
        data-lightbox-stage=""
        className="absolute inset-0 overflow-auto [touch-action:pan-x_pan-y]"
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerEnd}
        onPointerCancel={onPointerEnd}
        onPointerLeave={onPointerEnd}
      >
        <div data-lightbox-stage="" className="flex min-h-full min-w-full items-center justify-center p-4 sm:p-14">
          {failed ? (
            <p className="rounded-xl bg-surface-raised px-4 py-3 text-sm text-ink">تعذّر تحميل الصورة.</p>
          ) : (
            <button
              type="button"
              onClick={toggleZoom}
              aria-label={zoomed ? "تصغير الصورة" : "تكبير الصورة"}
              aria-pressed={zoomed}
              className={
                "shrink-0 rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary " +
                (zoomed ? "cursor-zoom-out" : "cursor-zoom-in")
              }
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                ref={image}
                key={current.src}
                src={current.src}
                alt={current.alt}
                draggable={false}
                onError={() => setFailed(true)}
                style={zoomed && baseWidth.current > 0 ? { width: baseWidth.current * zoom } : undefined}
                className={
                  zoomed
                    ? "block max-h-none max-w-none rounded-lg"
                    : "block max-h-[calc(100dvh-7rem)] max-w-[calc(100vw-2rem)] rounded-lg object-contain sm:max-w-[calc(100vw-7rem)]"
                }
              />
            </button>
          )}
        </div>
      </div>

      <div className="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-between gap-3 p-3">
        {images.length > 1 ? (
          <span className="rounded-full bg-surface-raised px-3 py-1 text-xs text-ink">
            <bdi>{`${arabicNumber(index + 1)} من ${arabicNumber(images.length)}`}</bdi>
          </span>
        ) : (
          <span />
        )}

        {/* Focus lands on the way out, not on the picture: a keyboard reader
            opening a viewer is one Enter from closing it again. */}
        <button
          ref={closeButton}
          type="button"
          onClick={close}
          aria-label="إغلاق عارض الصور"
          className={CONTROL}
        >
          <CloseIcon className="h-5 w-5" />
        </button>
      </div>

      {images.length > 1 && (
        <>
          <button
            type="button"
            onClick={previous}
            disabled={!hasPrevious}
            aria-label="الصورة السابقة"
            className={CONTROL + " absolute start-3 top-1/2 -translate-y-1/2"}
          >
            <ChevronStartIcon className="h-5 w-5" />
          </button>

          <button
            type="button"
            onClick={next}
            disabled={!hasNext}
            aria-label="الصورة التالية"
            className={CONTROL + " absolute end-3 top-1/2 -translate-y-1/2"}
          >
            <ChevronEndIcon className="h-5 w-5" />
          </button>
        </>
      )}
    </dialog>
  );
}

const CONTROL =
  "pointer-events-auto flex h-11 w-11 items-center justify-center rounded-full border border-line bg-surface-raised text-ink shadow-lg transition hover:border-primary disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none";

function spread(points: Map<number, { x: number; y: number }>): number {
  const [a, b] = [...points.values()];

  if (a === undefined || b === undefined) return 0;

  return Math.hypot(a.x - b.x, a.y - b.y);
}

function isRightToLeft(ref: RefObject<HTMLElement | null>): boolean {
  const scoped = ref.current?.closest("[dir]")?.getAttribute("dir");
  const direction = scoped ?? (typeof document === "undefined" ? "rtl" : document.documentElement.dir);

  return direction !== "ltr";
}

/**
 * A set of pictures and the viewer over them: `open(i)` from any picture's
 * press, and `lightbox` rendered once beside them.
 */
export function useImageLightbox(images: readonly LightboxImage[]): {
  open: (index: number) => void;
  lightbox: ReactNode;
} {
  const [index, setIndex] = useState<number | null>(null);

  return {
    open: setIndex,
    lightbox: (
      <ImageLightbox images={images} index={index} onIndexChange={setIndex} onClose={() => setIndex(null)} />
    ),
  };
}

/**
 * One picture that opens in the viewer when pressed. The picture itself is the
 * caller's `<img>`, unchanged — this adds the press, the keyboard and the label.
 */
export function ViewableImage({
  src,
  alt,
  layout = "inline",
  children,
}: {
  src: string;
  /** What the picture is — the viewer's label and the press's. */
  alt: string;
  /** `fill` takes the whole of a sized frame (a cover); `inline` hugs the picture. */
  layout?: "inline" | "fill";
  children: ReactNode;
}) {
  const { open, lightbox } = useImageLightbox([{ src, alt }]);

  return (
    <>
      <button
        type="button"
        onClick={() => open(0)}
        aria-label={alt === "" ? "عرض الصورة مكبّرة" : `عرض الصورة مكبّرة: ${alt}`}
        className={
          (layout === "fill" ? "block h-full w-full" : "block") +
          " cursor-zoom-in rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        }
      >
        {children}
      </button>
      {lightbox}
    </>
  );
}

/**
 * Every `<img>` inside HTML rendered from Markdown opens the viewer, and the
 * arrows walk the pictures of THAT body in order.
 *
 * ⚠️ NOTHING IS INJECTED. The body stays exactly what the server rendered (raw
 * HTML stripped at the parse); this only reads the pictures already there and
 * adds a press to each — `tabindex`, `role` and a label through `setAttribute`,
 * which never parses markup — and one delegated click on the container.
 *
 * ⚠️ AND THE `__html` OBJECT COMES FROM HERE, MEMOISED. React replaces the whole
 * body whenever `dangerouslySetInnerHTML` is a NEW object — measured: one
 * re-render (this hook's own, as it records the pictures) wiped every attribute
 * it had just added. Spread `body` onto the element; do not build the object
 * beside it.
 */
export function useLightboxIn<T extends HTMLElement>(html: string): {
  body: { ref: RefObject<T | null>; dangerouslySetInnerHTML: { __html: string } };
  lightbox: ReactNode;
} {
  const ref = useRef<T>(null);
  const [images, setImages] = useState<LightboxImage[]>([]);
  const { open, lightbox } = useImageLightbox(images);
  const inner = useMemo(() => ({ __html: html }), [html]);

  useEffect(() => {
    const container = ref.current;

    if (container === null) return;

    const nodes = () => [...container.querySelectorAll("img")];

    setImages(nodes().map((node) => ({ src: node.currentSrc || node.src, alt: node.alt })));

    nodes().forEach((node) => {
      node.setAttribute("tabindex", "0");
      node.setAttribute("role", "button");
      node.setAttribute("aria-label", node.alt === "" ? "عرض الصورة مكبّرة" : `عرض الصورة مكبّرة: ${node.alt}`);
      node.style.cursor = "zoom-in";
    });

    // Asked at the moment of the press, never from a list taken earlier.
    const indexOf = (target: EventTarget | null) =>
      target instanceof HTMLImageElement ? nodes().indexOf(target) : -1;

    const onClick = (event: MouseEvent) => {
      const at = indexOf(event.target);

      if (at >= 0) {
        event.preventDefault();
        open(at);
      }
    };

    const onKey = (event: KeyboardEvent) => {
      if (event.key !== "Enter" && event.key !== " ") return;

      const at = indexOf(event.target);

      if (at >= 0) {
        event.preventDefault();
        open(at);
      }
    };

    container.addEventListener("click", onClick);
    container.addEventListener("keydown", onKey);

    return () => {
      container.removeEventListener("click", onClick);
      container.removeEventListener("keydown", onKey);
    };
  }, [inner, open]);

  return { body: { ref, dangerouslySetInnerHTML: inner }, lightbox };
}
