"use client";

import { teachesOnPlatform, useAuth } from "@/lib/auth-context";
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
 * ⚠️ NOT A PERMISSION. A guardian holds zero permissions exactly as a student
 * does — a permission-shaped predicate cannot tell a guardian from staff, and
 * would hide the price from the person most likely to be paying it.
 *
 * ⚠️ AND NOT `isLearner` ANY MORE, which read `platform_role` — null for
 * thirty-seven accounts, students among them, so a real buyer saw a buy button
 * with no price above it. `teachesOnPlatform` is the one predicate the purchase
 * doors refuse on, so the price is shown to exactly the people the door lets buy.
 *
 * ⚠️ THIS IS A DISPLAY RULE, NOT A GUARD, AND SAYING SO IS THE POINT. The course
 * detail page is a SERVER component fed by the public marketplace endpoint, which
 * has no reader to be — so the field still travels in that public JSON and a
 * teacher who opens the network tab can read it. Withholding it properly means
 * an auth-aware public endpoint, which is a different change; this one is about
 * what the product SHOWS. Nothing here should ever be cited as protection.
 */
/*
  ⚠️ A CLOSED SET OF TWO, NOT A `className` PROP. The rule above is what this
  component is FOR, and a free-form class is how a second caller ends up
  re-implementing the rule beside it rather than reusing it — which is exactly
  what nearly happened when the course page grew a rail: the price was about to
  be printed there with its own copy of `isLearner`, and two spellings of one
  question is the defect this repository records a dozen times over.
*/
const SIZES = {
  inline: "text-xl font-black text-primary-ink",
  rail: "text-3xl font-black text-ink",
} as const;

export function CoursePrice({
  priceMinor,
  currency,
  size = "inline",
}: {
  priceMinor: number | null;
  currency: string | null;
  size?: keyof typeof SIZES;
}) {
  const { user } = useAuth();

  if (priceMinor === null || currency === null || user === null || teachesOnPlatform(user)) {
    return null;
  }

  return (
    <p className={SIZES[size]}>
      {priceMinor === 0 ? "مجاني" : formatMinorMoney(priceMinor, currency)}
    </p>
  );
}
