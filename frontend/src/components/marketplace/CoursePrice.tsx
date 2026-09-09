"use client";

import { isLearner, useAuth } from "@/lib/auth-context";
import { formatMinorMoney } from "@/lib/labels";

/**
 * The price, shown to the people who would pay it and to nobody else.
 *
 * ⚠️ A TEACHER READING ANOTHER TEACHER'S PRICE IS THE WHOLE POINT — and «even
 * the course's own teacher» is deliberate, because a rule with an exception for
 * whoever owns the row is a rule that leaks through every assistant, every
 * co-teacher and every account that once taught the subject. The teacher sets
 * and reads their own price on `/manage/courses`, which is the screen for it.
 *
 * ⚠️ AND A SIGNED-OUT VISITOR IS ON THE SAME SIDE OF THE LINE, because a price
 * visible without an account is a price visible to every teacher on the
 * platform in one private window.
 *
 * ⚠️ `isLearner` AND NOT A PERMISSION. A guardian holds zero permissions exactly
 * as a student does — a permission-shaped predicate cannot tell a guardian from
 * staff, and would hide the price from the person most likely to be paying it.
 *
 * ⚠️ THIS IS A DISPLAY RULE, NOT A GUARD, AND SAYING SO IS THE POINT. The course
 * detail page is a SERVER component fed by the public marketplace endpoint, which
 * has no reader to be — so the field still travels in that public JSON and a
 * teacher who opens the network tab can read it. Withholding it properly means
 * an auth-aware public endpoint, which is a different change; this one is about
 * what the product SHOWS. Nothing here should ever be cited as protection.
 */
export function CoursePrice({
  priceMinor,
  currency,
}: {
  priceMinor: number | null;
  currency: string | null;
}) {
  const { user } = useAuth();

  if (priceMinor === null || currency === null || !isLearner(user)) return null;

  return (
    <p className="text-xl font-black text-primary-ink">
      {priceMinor === 0 ? "مجاني" : formatMinorMoney(priceMinor, currency)}
    </p>
  );
}
