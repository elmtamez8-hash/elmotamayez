import { render, screen } from "@testing-library/react";
import { createElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ErrorBoundary from "@/app/error";
import { StaleChunkRecovery } from "@/components/app/StaleChunkRecovery";

import {
  STALE_CHUNK_GUARD_MS,
  STALE_CHUNK_RELOAD_KEY,
  isChunkLoadError,
  reloadOnceForStaleChunk,
} from "./stale-chunk";

/*
| تبويبةٌ مفتوحةٌ عبرَ نشرةٍ تطلبُ حزمةً حذفَها البناءُ الجديد (قِيسَ على الإنتاجِ
| ٢٠٢٦-٠٩-٢٤). ما يُقاسُ هنا ثلاثةُ أشياءَ لا أكثر: فشلُ الحزمةِ يُعيدُ التحميلَ
| **مرّةً واحدة**، وفشلٌ ثانٍ داخلَ النافذةِ **لا** يُعيد (وإلّا فهي حلقةٌ على جهازِ
| المستخدم)، وخطأٌ عاديٌّ لا يُعيدُ أبداً.
*/

function chunkError(): Error {
  const error = new Error("Loading chunk 5613 failed.\n(error: https://x/_next/static/chunks/5613-0374f08bd3eff5a8.js)");
  error.name = "ChunkLoadError";

  return error;
}

let reload: ReturnType<typeof vi.fn>;

beforeEach(() => {
  window.sessionStorage.clear();
  reload = vi.fn();
  vi.stubGlobal("location", { ...window.location, reload });
  vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("isChunkLoadError", () => {
  it("يعرفُ كلَّ صيغِ الحزمةِ المفقودة", () => {
    expect(isChunkLoadError(chunkError())).toBe(true);
    expect(isChunkLoadError(new Error("Loading CSS chunk 12 failed."))).toBe(true);
    expect(isChunkLoadError(new Error("Failed to load chunk /_next/static/chunks/a.js"))).toBe(true);
    expect(isChunkLoadError(new TypeError("Failed to fetch dynamically imported module: https://x/a.js"))).toBe(true);
    expect(isChunkLoadError(new TypeError("error loading dynamically imported module"))).toBe(true);
    expect(isChunkLoadError(new TypeError("Importing a module script failed."))).toBe(true);
  });

  it("لا يخلطُ خطأً عاديّاً بها", () => {
    expect(isChunkLoadError(new Error("Cannot read properties of undefined"))).toBe(false);
    expect(isChunkLoadError(null)).toBe(false);
    expect(isChunkLoadError(undefined)).toBe(false);
  });
});

describe("reloadOnceForStaleChunk", () => {
  it("يُعيدُ التحميلَ مرّةً واحدةً ولا يُعيدُ ثانيةً داخلَ النافذة", () => {
    const t0 = 1_000_000;

    expect(reloadOnceForStaleChunk(chunkError(), t0)).toBe(true);
    expect(reloadOnceForStaleChunk(chunkError(), t0 + 5_000)).toBe(false);

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("يُعيدُ من جديدٍ بعدَ انقضاءِ النافذة — نشرةٌ لاحقةٌ هي عطلٌ جديد", () => {
    const t0 = 1_000_000;

    reloadOnceForStaleChunk(chunkError(), t0);
    reloadOnceForStaleChunk(chunkError(), t0 + STALE_CHUNK_GUARD_MS + 1);

    expect(reload).toHaveBeenCalledTimes(2);
  });

  it("لا يُعيدُ التحميلَ على خطأٍ عاديّ", () => {
    expect(reloadOnceForStaleChunk(new Error("انفجار"))).toBe(false);
    expect(reload).not.toHaveBeenCalled();
    expect(window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)).toBeNull();
  });

  it("لا يُعيدُ حين يتعذّرُ التخزين — بلا دليلٍ على أنّنا لم نُعِد للتوّ", () => {
    vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
      throw new Error("SecurityError");
    });

    expect(reloadOnceForStaleChunk(chunkError())).toBe(false);
    expect(reload).not.toHaveBeenCalled();
  });
});

describe("حدُّ الخطأ (وقتَ التصيير)", () => {
  it("حزمةٌ مفقودةٌ تُعيدُ التحميلَ مرّةً واحدة، والثانيةُ تعرضُ شاشةَ الخطأ", () => {
    const first = render(createElement(ErrorBoundary, { error: chunkError(), reset: vi.fn() }));

    expect(reload).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("status").textContent).toContain("أحدث نسخة");
    first.unmount();

    // The reloaded document failed again: the guard hands over to the error UI.
    render(createElement(ErrorBoundary, { error: chunkError(), reset: vi.fn() }));

    expect(reload).toHaveBeenCalledTimes(1);
    expect(screen.getByText("تعذّر عرض هذه الصفحة")).toBeTruthy();
  });

  it("خطأٌ عاديٌّ لا يُعيدُ التحميلَ ويعرضُ الشاشةَ كما هي", () => {
    render(createElement(ErrorBoundary, { error: new Error("انفجار"), reset: vi.fn() }));

    expect(reload).not.toHaveBeenCalled();
    expect(screen.getByText("تعذّر عرض هذه الصفحة")).toBeTruthy();
  });
});

describe("StaleChunkRecovery (وقتَ التنقّل)", () => {
  function rejection(reason: unknown): Event {
    const event = new Event("unhandledrejection") as Event & { reason: unknown };
    Object.defineProperty(event, "reason", { value: reason });

    return event;
  }

  it("رفضٌ غيرُ ملتقَطٍ لحزمةٍ مفقودةٍ يُعيدُ مرّةً واحدةً فقط", () => {
    render(createElement(StaleChunkRecovery));

    window.dispatchEvent(rejection(chunkError()));
    window.dispatchEvent(rejection(chunkError()));

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("فشلُ تحميلِ سكربتٍ من /_next/static يُعيد، وخطأٌ عاديٌّ لا", () => {
    render(createElement(StaleChunkRecovery));

    window.dispatchEvent(rejection(new Error("انفجار")));
    window.dispatchEvent(new ErrorEvent("error", { error: new Error("انفجار"), message: "انفجار" }));
    expect(reload).not.toHaveBeenCalled();

    const script = document.createElement("script");
    script.src = "https://x/_next/static/chunks/5613-0374f08bd3eff5a8.js";
    document.body.appendChild(script);
    script.dispatchEvent(new Event("error"));

    expect(reload).toHaveBeenCalledTimes(1);
    script.remove();
  });
});
