/**
 * What the local participant may PUBLISH, read from the permissions the server
 * signed — never from a flag of ours (owner decisions 2026-09-30).
 *
 * The host's «كتم» and «اكتم الجميع» are permission changes now: the provider
 * pushes the new set to the student's browser, and this is how the room page
 * learns her microphone was taken. A button that reads a local flag instead
 * would offer a microphone the server has already refused.
 *
 * ⚠️ THE NUMBERS MIRROR THE PROVIDER'S PROTOCOL ENUM (`TrackSource`) and are
 * spelled here rather than imported: the package that declares them is a
 * transitive dependency, not one of ours, and an import from it would break on
 * any upgrade that moves it. `LiveKitBroadcastProvider` writes the same values.
 *
 * ⚠️ AN EMPTY LIST MEANS «EVERY SOURCE» — the host's ticket carries none, and a
 * student's always names the camera — so emptiness is read as permission, not
 * as refusal.
 */
export const SOURCE_MICROPHONE = 2;
export const SOURCE_SCREEN_SHARE = 3;

/** The two fields read here — the library's type is wider. */
export interface PublishPermissions {
  canPublish: boolean;
  canPublishSources: number[];
}

/**
 * Whether this source may be published.
 *
 * `undefined` permissions mean the room has not connected yet — nothing has
 * been refused, so the caller decides what an unknown answer draws.
 */
export function mayPublish(
  permissions: PublishPermissions | undefined,
  source: typeof SOURCE_MICROPHONE | typeof SOURCE_SCREEN_SHARE,
): boolean | undefined {
  if (permissions === undefined) return undefined;
  if (!permissions.canPublish) return false;

  const sources = permissions.canPublishSources ?? [];

  return sources.length === 0 || sources.includes(source);
}
