"use client";

import { createContext, useContext, type ReactNode } from "react";

import { PLATFORM_NAME_FALLBACK } from "./platform";

/**
 * The product's name, handed down from the server (spec 022 follow-up).
 *
 * ⚠️ FETCHED ONCE IN THE ROOT LAYOUT AND PASSED DOWN — never fetched per
 * component. The name is read by the sidebar, the public header, the footer, the
 * login screen and every `aria-label` on a wordmark; a client-side `fetch` in
 * each of those is one request per component per page for a string that changes
 * once a year, which is the `ParticipantsPanel` defect in miniature.
 *
 * ⚠️ AND IT IS A CONTEXT RATHER THAN A PROP CHAIN, because the readers are five
 * levels apart and most of them are `"use client"` islands the server cannot
 * reach with a prop.
 *
 * The default is the real name and not an empty string: a provider that has not
 * mounted yet — a client component rendered outside the tree, a test — should
 * spell the product, not nothing.
 */
const PlatformNameContext = createContext<string>(PLATFORM_NAME_FALLBACK);

export function PlatformProvider({ name, children }: { name: string; children: ReactNode }) {
  return <PlatformNameContext.Provider value={name}>{children}</PlatformNameContext.Provider>;
}

/** The product's name, for a client component. Server code calls `platformName()`. */
export function usePlatformName(): string {
  return useContext(PlatformNameContext);
}
