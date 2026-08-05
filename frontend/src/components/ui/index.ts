/**
 * The shared component library. Both `(public)` and `(app)` consume it, and every
 * phase after this one builds on it instead of inventing a third visual pattern.
 *
 * Rule that keeps it shared: components here take no free-form `className`.
 * Appearance is controlled by a closed set of variants — see
 * specs/002-arabic-rtl-app-shell/contracts/ui-components.md.
 */

export {};
