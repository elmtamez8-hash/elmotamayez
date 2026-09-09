"use client";

import { useEffect, useRef } from "react";

import { Button } from "@/components/ui/Button";

/**
 * A question asked in a window of ours, never in one of the browser's.
 *
 * ⚠️ THE PROBLEM IT SOLVES IS A GREY BOX IN THE MIDDLE OF AN ARABIC PRODUCT.
 * `window.confirm` is not ours in any respect a reader can see: its buttons read
 * «OK»/«Cancel» in English on some Arabic Android builds, it lays out
 * left-to-right inside a right-to-left page, it cannot be styled, and it asks a
 * permanent deletion in exactly the same voice it asks for a new item's title —
 * which is precisely what teaches people to press OK without reading. It also
 * FREEZES the page: no presence heartbeat, no notification, nothing painted,
 * for as long as it is open.
 *
 * ⚠️ AND IT IS A NATIVE `<dialog>` OPENED WITH `showModal()`, WHICH IS WHY IT
 * COULD BE BUILT AT ALL. `ConfirmButton` and `PurchaseDialog` both refused a
 * modal in as many words, and their reason was the PRICE — «a focus trap, a
 * scroll lock and an escape handler for a one-word question». The platform pays
 * that price now: `showModal()` traps focus, makes everything behind it inert,
 * and wires Escape, with no line of ours to get wrong. `PromoVideoButton` named
 * this the upgrade path before it was taken.
 *
 * ⚠️ `ConfirmButton` STAYS, and the difference is not stylistic. In a live
 * lesson a window that covers the screen is the very harm that component was
 * written to avoid — a teacher cannot see the class while answering a dialog.
 * A two-press arm belongs on a control pressed mid-lesson; a window belongs on a
 * question asked while nothing else is happening.
 */
export function Modal({
  open,
  title,
  message,
  confirmLabel,
  onConfirm,
  onCancel,
  tone = "primary",
  busy = false,
  children,
}: {
  open: boolean;
  /** Announced to a screen reader on open — the question, not the trigger. */
  title: string;
  message?: string;
  /** What the confirm button will DO, said plainly. Never «موافق». */
  confirmLabel: string;
  onConfirm: () => void;
  /** Reached by all three ways out: the button, Escape, and a click outside. */
  onCancel: () => void;
  /** `danger` for anything that cannot be taken back. No free-form className. */
  tone?: "danger" | "primary";
  busy?: boolean;
  children?: React.ReactNode;
}) {
  const ref = useRef<HTMLDialogElement>(null);

  /*
    ⚠️ `showModal()` AND NOT THE `open` ATTRIBUTE. `<dialog open>` renders an
    ordinary in-flow box: no focus trap, no inert background, no backdrop and no
    Escape. Every property this component exists for comes from the method.
  */
  useEffect(() => {
    const dialog = ref.current;

    if (dialog === null) return;

    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  if (!open) return null;

  return (
    <dialog
      ref={ref}
      aria-labelledby="modal-title"
      /*
        ⚠️ ONE LISTENER ON `close`, WHICH IS EVERY WAY OUT AT ONCE. The browser
        fires `cancel` then `close` for Escape, and `close` alone for our own
        button and for a click outside — so cancelling has ONE spelling here
        instead of three that drift apart, and the one that gets forgotten is
        always the keyboard.
      */
      onClose={onCancel}
      /*
        ⚠️ ONLY WHEN THE TARGET IS THE DIALOG ITSELF. A click anywhere on the
        backdrop is reported against the `<dialog>` element, because the visible
        card is a child that covers part of it — so without this comparison every
        press inside the window closes it, INCLUDING the confirm button, and the
        action never runs at all. The feature reads as broken rather than as
        over-eager, which is why this is a contract clause (C-06) and not a
        detail.
      */
      onClick={(event) => {
        if (event.target === ref.current) ref.current?.close();
      }}
      className="w-full max-w-md rounded-2xl border border-line bg-surface-raised p-6 text-start text-ink shadow-xl"
    >
      <h2 id="modal-title" className="text-lg font-bold">
        {title}
      </h2>

      {message !== undefined && (
        <p className="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink-muted">{message}</p>
      )}

      {/*
        The panel a reader types into — an INSET ground, not more white.

        ⚠️ `Field`'s own control is `bg-surface-raised`, and so is this card. A
        white box on a white card has no edge but its hairline border, so the one
        thing the window is asking for is the least visible thing in it. `surface`
        under `surface-raised` reads as inset in BOTH themes, and it is the right
        way round in each: limestone under white in the light theme, and #191315
        under #241c1f in the dark one — the raised token is lighter there too.

        ⚠️ Tokens from `@theme` only. A colour class naming a token that was never
        defined paints NOTHING, silently, and this tree has shipped that four
        times.

        ⚠️ AND `p-4` IS WHAT KEEPS THE SCROLLBAR AWAY. A focused field draws its
        ring OUTSIDE its own box; with the scroller flush to the content that ring
        overflowed by three pixels — measured — so a window holding a single text
        input grew a scrollbar the moment it opened, which is every time. The cap
        and `overflow-y-auto` stay for the long case: a list of losses must scroll
        inside the window rather than push the buttons off the bottom of a phone,
        where the only way out left is Escape and a touch screen has none.
      */}
      {children !== undefined && (
        <div className="mt-5 max-h-64 overflow-y-auto rounded-xl border border-line bg-surface p-4">
          {children}
        </div>
      )}

      <div className="mt-6 flex flex-wrap justify-end gap-2">
        {/* ⚠️ CANCEL IS FIRST IN THE DOM, so it is where focus lands when the
            window opens. On something that cannot be taken back, the safe path
            is the default one (FR-007) — and `autofocus` on a field inside
            `children` still wins when there is one, which is what US3 needs. */}
        <Button variant="ghost" onClick={() => ref.current?.close()}>
          إلغاء
        </Button>

        <Button variant={tone === "danger" ? "danger" : "primary"} loading={busy} onClick={onConfirm}>
          {confirmLabel}
        </Button>
      </div>
    </dialog>
  );
}
