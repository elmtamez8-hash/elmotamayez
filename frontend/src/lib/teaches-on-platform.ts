import type { User } from "@/lib/types";

/**
 * Whether this account teaches here — the one spelling of «a teacher never buys».
 *
 * ⚠️ NOT `isLearner()`, AND THAT IS A PERSON. `platform_role` is null for
 * thirty-seven accounts (founders, officers, and students a teacher or a seeder
 * created), so «not a learner» hid every purchase button from a real student
 * whose column was never written. This reads `workspaces`, which the server
 * builds from the pivot ROLE with `student` excluded — the same predicate as
 * `User::teachesOnPlatform()`, which is what the purchase doors refuse on. One
 * question, one answer, on the screen and at the door.
 *
 * ⚠️ A MODULE OF ITS OWN, importing nothing but a type, because the public
 * course page's client leaves (`MyCohort`, `PrivateSessionRequestForm`) are
 * tested against a partial mock of `@/lib/api`; `auth-context` re-exports it for
 * the components that already read the session from there.
 *
 * `null` (a visitor) teaches nothing.
 */
export function teachesOnPlatform(user: Pick<User, "workspaces"> | null): boolean {
  return (user?.workspaces?.length ?? 0) > 0;
}
