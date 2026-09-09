import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

/*
  Unmount between tests.

  React Testing Library does this by itself only when Vitest's globals are on. They
  are deliberately off here — every test imports `describe`/`it`/`expect` by name, so
  `npx tsc --noEmit`, which is a gate in this repo, needs no extra `types` entry and
  no test-only tsconfig. The cost is this one file.

  Without it each test renders into the same document and queries start matching the
  PREVIOUS test's markup — which shows up as a passing test that is reading something
  it never rendered.
*/
afterEach(() => cleanup());

/*
  ⛔ jsdom DOES NOT IMPLEMENT `<dialog>`'s TWO OPENING METHODS — measured, not
  assumed, on 2026-09-09 against jsdom 29.1.1:

      { showModal: "undefined", close: "undefined",
        threw: "d.showModal is not a function", openAfter: false }

  `HTMLDialogElement` exists and `open` is a real property; the two functions
  that open and close a modal are simply absent, and calling either THROWS.
  Without this shim every test of a `<dialog>`-based component passes because it
  never reaches anything — the "green because of the wrong condition" shape.

  ⚠️ WHAT THIS SHIM DOES NOT MEASURE, AND MUST NEVER BE READ AS MEASURING: the
  focus trap, the inert background and the Escape-to-cancel behaviour are the
  PLATFORM's, and jsdom has no layout or inertness to reproduce them with. What
  vitest checks is what our component DOES — it opens, it closes on `close`, it
  returns focus, it ignores clicks on its own children. The trap itself is
  guaranteed by the browser, and that is the whole reason spec 033 chose a
  native `<dialog>` over a hand-written overlay: a focus trap we wrote would be
  one our tests could measure and real assistive technology could still defeat.
*/
if (typeof HTMLDialogElement !== "undefined" && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
    this.open = true;
  };

  HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement) {
    this.open = false;
    this.dispatchEvent(new Event("close"));
  };
}
