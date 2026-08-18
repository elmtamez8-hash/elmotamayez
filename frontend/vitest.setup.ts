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
