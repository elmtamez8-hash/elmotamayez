/**
 * The project's icon vocabulary, mapped onto Tabler Icons (MIT).
 *
 * Why a mapping layer rather than importing IconBrandWhatsapp everywhere:
 * components name what the icon *means* (CheckIcon, BankIcon), so swapping the
 * underlying set later is one edit here instead of a grep across the tree — and
 * a reviewer can see the whole icon vocabulary in one screen.
 *
 * Tabler ships ES modules and every icon is its own component, so re-exporting
 * named imports stays tree-shakable — nothing here pulls in the other 5,900.
 *
 * Accessibility: icons are decorative by default. They sit beside text that
 * already says what they mean, and announcing them again is noise. Pass `title`
 * only when the icon is the sole label — Tabler then renders a <title> and the
 * element takes an accessible name.
 */
import {
  IconAbc,
  IconAlertTriangle,
  IconAtom,
  IconArrowUp,
  IconBell,
  IconBook2,
  IconBrandInstagram,
  IconBrandWhatsapp,
  IconBrandX,
  IconBrandYoutube,
  IconBuilding,
  IconBuildingBank,
  IconCalendarCheck,
  IconCalendarEvent,
  IconCalendarTime,
  IconCertificate,
  IconClock,
  IconChartHistogram,
  IconEraser,
  IconEye,
  IconEyeOff,
  IconBarbell,
  IconChartLine,
  IconCheck,
  IconGift,
  IconTrophy,
  IconChevronDown,
  IconChevronLeft,
  IconChevronRight,
  IconChevronUp,
  IconChecklist,
  IconClipboardList,
  IconLock,
  IconClipboardText,
  IconDatabase,
  IconDeviceDesktop,
  IconDna,
  IconCoins,
  IconDiscountOff,
  IconFeather,
  IconFileText,
  IconFlask,
  IconHome,
  IconHourglass,
  IconWorld,
  IconInfinity,
  IconInfoCircle,
  IconLanguage,
  IconLogout,
  IconMath,
  IconMicroscope,
  IconMenu2,
  IconMessageCircle,
  IconMoon,
  IconReceipt2,
  IconReceiptOff,
  IconReceiptRefund,
  IconRepeatOff,
  IconBuildingMosque,
  IconSchool,
  IconSettings,
  IconShieldCheck,
  IconShieldLock,
  IconPackage,
  IconShoppingBag,
  IconStarFilled,
  IconSun,
  IconTag,
  IconTrash,
  IconUserPlus,
  IconUserSearch,
  IconUsers,
  IconUsersGroup,
  IconVocabulary,
  IconWallet,
  IconX,
  type Icon as TablerIcon,
  IconWriting,
} from "@tabler/icons-react";

export type IconProps = {
  className?: string;
  /** Set only when the icon carries meaning no adjacent text conveys. */
  title?: string;
};

/**
 * Tabler defaults to size={24}, which fights a `className="h-4 w-4"` by setting
 * width/height attributes the class then has to override. Dropping size to
 * undefined lets the class win outright.
 */
function wrap(Base: TablerIcon, fallback: string) {
  return function Wrapped({ className = fallback, title }: IconProps) {
    return (
      <Base
        className={className}
        title={title}
        aria-hidden={title ? undefined : true}
        focusable="false"
        size={undefined}
        stroke={1.7}
      />
    );
  };
}

export const CheckIcon = wrap(IconCheck, "h-4 w-4");
export const ArrowUpIcon = wrap(IconArrowUp, "h-5 w-5");

export const WhatsAppIcon = wrap(IconBrandWhatsapp, "h-6 w-6");
export const XIcon = wrap(IconBrandX, "h-5 w-5");
export const InstagramIcon = wrap(IconBrandInstagram, "h-5 w-5");
export const YouTubeIcon = wrap(IconBrandYoutube, "h-5 w-5");

/** Manual bank transfer — the only payment method in the MVP. */
export const BankIcon = wrap(IconBuildingBank, "h-5 w-5");

// Footer link glyphs.
export const UsersIcon = wrap(IconUsers, "h-4 w-4");
export const BookIcon = wrap(IconBook2, "h-4 w-4");
export const TagIcon = wrap(IconTag, "h-4 w-4");
export const InfoIcon = wrap(IconInfoCircle, "h-4 w-4");
export const UserPlusIcon = wrap(IconUserPlus, "h-4 w-4");
export const AcademicCapIcon = wrap(IconSchool, "h-4 w-4");
export const DocumentIcon = wrap(IconFileText, "h-4 w-4");
export const ShieldIcon = wrap(IconShieldCheck, "h-4 w-4");
export const RefundIcon = wrap(IconReceiptRefund, "h-4 w-4");

/**
 * Panel navigation. Inlined SVG path strings used to live in the shell layout —
 * three of them were the same glyph typed twice, and none of them could be
 * reused by a page.
 */
export const HomeIcon = wrap(IconHome, "h-5 w-5");
export const CoursesIcon = wrap(IconBook2, "h-5 w-5");
export const LearningIcon = wrap(IconSchool, "h-5 w-5");
export const ExamIcon = wrap(IconClipboardText, "h-5 w-5");
// ⚠️ NOT `BankIcon` — that one is IconBuildingBank, a FINANCIAL bank, and the
// site footer uses it beside a transfer instruction. A question bank borrowing
// it would put a bank building next to "بنك الأسئلة" in the sidebar, which is
// the exact failure the naming rule at the top of this file exists to prevent.
export const QuestionBankIcon = wrap(IconDatabase, "h-5 w-5");
// Item analysis. A histogram rather than the line already used by
// OngoingReviewIcon: this screen is a distribution across questions, not a
// trend over time, and two identical glyphs in one sidebar are two links the
// reader has to click to tell apart.
export const ItemAnalysisIcon = wrap(IconChartHistogram, "h-5 w-5");
// The mistake notebook. An eraser rather than a warning triangle: the notebook
// is where a mistake gets corrected, and a hazard sign in a sidebar reads as
// something wrong with the product.
export const MistakesIcon = wrap(IconEraser, "h-5 w-5");
// The grading board. A pen rather than a third clipboard: ExamIcon and
// ApplicationIcon already own that shape, and this entry is the one act in the
// sidebar where the teacher WRITES on somebody else's paper.
export const GradingIcon = wrap(IconWriting, "h-5 w-5");
// Homework. A checklist rather than a fourth clipboard or a second document:
// ExamIcon, ApplicationIcon and DocumentIcon already own those shapes, and an
// assignment is the one thing in the sidebar with a box you tick.
export const AssignmentIcon = wrap(IconChecklist, "h-5 w-5");
// The unlock condition. A padlock, because that is what the student meets: the
// screen sets the rule, and the rule is a closed door until it is met.
export const LockIcon = wrap(IconLock, "h-5 w-5");
// Self-training. Not a second clipboard: ExamIcon is already one, and a student
// scanning the sidebar for "the exam" must not have to read two labels to find
// out which clipboard is the teacher's paper and which is their own.
export const PracticeIcon = wrap(IconBarbell, "h-5 w-5");
export const CertificateIcon = wrap(IconCertificate, "h-5 w-5");
export const OrdersIcon = wrap(IconShoppingBag, "h-5 w-5");
/*
 * The book store (011 · US1). A BOOK, not a second shopping bag: `OrdersIcon`
 * is already one and `ShopIcon` is the rewards gift, so a third trolley would
 * make a student read three labels to find out which of them sells books.
 */
export const StoreIcon = wrap(IconBook2, "h-5 w-5");
// The parcel itself — the teacher's fulfilment queue, which is a different job
// from pricing the goods and carries a different permission.
export const ShipmentIcon = wrap(IconPackage, "h-5 w-5");
/*
 * Invitations (011 · US3). A person WITH A PLUS — not `MembersIcon`, which is
 * the workspace's team, and not `ShopIcon`, which is the rewards gift: the whole
 * of this screen is «bring somebody who is not here yet».
 */
export const ReferralIcon = wrap(IconUserPlus, "h-5 w-5");
export const WorkspaceIcon = wrap(IconBuilding, "h-5 w-5");
export const MembersIcon = wrap(IconUsers, "h-5 w-5");
export const SettingsIcon = wrap(IconSettings, "h-5 w-5");
export const LogoutIcon = wrap(IconLogout, "h-4 w-4");
/*
  ⚠️ NOT `HomeIcon` — that one is `/dashboard`, and it is already in the nav
  above. A signed-in person's «home» is their own screen, so the PUBLIC site
  needs a different glyph or the sidebar shows one icon meaning two places.
*/
export const SiteIcon = wrap(IconWorld, "h-4 w-4");
export const TrashIcon = wrap(IconTrash, "h-4 w-4");
export const BellIcon = wrap(IconBell, "h-5 w-5");
export const FamilyIcon = wrap(IconUsersGroup, "h-5 w-5");
/** Spec 010 — the private chat between a student and their teacher's side. */
export const MessagesIcon = wrap(IconMessageCircle, "h-5 w-5");
/** The student's own timetable across every teacher. */
export const ScheduleIcon = wrap(IconCalendarTime, "h-5 w-5");
/** A time of day, beside a row whose day is already stated by its heading. */
export const ClockIcon = wrap(IconClock, "h-4 w-4");
/** The teacher's calendar of taught sessions. */
export const SessionsIcon = wrap(IconCalendarEvent, "h-5 w-5");
/** The teacher's own statement — their contract, never a student's payment. */
export const SettlementIcon = wrap(IconReceipt2, "h-5 w-5");
/**
 * The student's credits. A coin rather than a receipt: the balance is counted in
 * credits and carries no money at all, and a receipt glyph would promise a figure
 * the screen deliberately never shows.
 */
export const CreditsIcon = wrap(IconCoins, "h-5 w-5");

/**
 * Directional icons carry their direction in the NAME, not in a CSS flip.
 *
 * The product is Arabic-only and RTL-only (FR-004), so direction never changes
 * at runtime and there is nothing to flip — `ChevronStartIcon` simply *is* the
 * right-pointing chevron. This also makes FR-013 unbreakable by construction:
 * there is no rule that could catch the search lens or the play triangle,
 * because no rule exists.
 */
export const ChevronDownIcon = wrap(IconChevronDown, "h-5 w-5");
// Up and down do NOT get direction-neutral names the way start/end do: the
// vertical axis is the same in both writing directions, and calling this
// "ChevronStart" would be a lie the first time someone reads it.
export const ChevronUpIcon = wrap(IconChevronUp, "h-5 w-5");
export const ChevronStartIcon = wrap(IconChevronRight, "h-4 w-4");
export const ChevronEndIcon = wrap(IconChevronLeft, "h-4 w-4");
export const MenuIcon = wrap(IconMenu2, "h-6 w-6");
// IconX is the close cross. IconBrandX is the Twitter/X logo — one letter
// apart in Tabler's naming, and the wrong one turns a menu button into an ad.
export const CloseIcon = wrap(IconX, "h-6 w-6");
// Spec 022 · FR-014 — the show/hide toggle on a password field. Two icons for
// two STATES, never one rotated: an eye with a line through it is the only
// widely-read way of saying «hidden», and a screen reader is told in words by
// the button's own aria-label.
export const EyeIcon = wrap(IconEye, "h-5 w-5");
export const EyeOffIcon = wrap(IconEyeOff, "h-5 w-5");
export const MoonIcon = wrap(IconMoon, "h-5 w-5");
export const SunIcon = wrap(IconSun, "h-5 w-5");
export const AlertIcon = wrap(IconAlertTriangle, "h-6 w-6");
export const TrustShieldIcon = wrap(IconShieldCheck, "h-3.5 w-3.5");

/**
 * The rating star is the one icon that must be filled, not stroked: a hollow
 * star at 14px reads as "empty" and this component draws both states.
 */
export const StarIcon = wrap(IconStarFilled, "h-4 w-4");

/*
| Marketing-page vocabulary.
|--------------------------------------------------------------------------
| The public pricing and about pages describe how the money and the vetting
| actually work, and a wall of paragraphs is not readable on a phone. These
| name the IDEA each block carries, not the picture — `NoSubscriptionIcon`
| survives a change of glyph, `RepeatOffIcon` would not.
|
| The three "no" icons are the crossed-out variants deliberately: the page's
| strongest claim is what it does NOT charge, and a wallet with a line through
| it says that before the sentence under it is read.
*/
export const WalletIcon = wrap(IconWallet, "h-5 w-5");
export const SessionChargedIcon = wrap(IconCalendarCheck, "h-5 w-5");
export const NeverExpiresIcon = wrap(IconInfinity, "h-5 w-5");
export const NoSubscriptionIcon = wrap(IconRepeatOff, "h-5 w-5");
export const NoSignupFeeIcon = wrap(IconReceiptOff, "h-5 w-5");
export const NoCommissionIcon = wrap(IconDiscountOff, "h-5 w-5");
export const ApplicationIcon = wrap(IconClipboardList, "h-5 w-5");
export const HumanReviewIcon = wrap(IconUserSearch, "h-5 w-5");
export const SecureChannelIcon = wrap(IconShieldLock, "h-5 w-5");
export const OngoingReviewIcon = wrap(IconChartLine, "h-5 w-5");

/*
 * Gamification (spec 009).
 *
 * Named for what they mean on the screen, not for the shape: `ShopIcon` is the
 * reward store, and swapping the gift for something else later is one edit here
 * rather than a grep across four pages.
 *
 * ⚠️ `StreakIcon` AND `FocusIcon` STOOD HERE AND WERE NEVER RENDERED. Both were
 * wired for nav entries that ended up choosing a different icon — intended and
 * then superseded, rather than debris — and a comment naming a symbol nobody
 * imports is how the next reader concludes the streak screen exists.
 */
export const ProgressIcon = wrap(IconTrophy, "h-5 w-5");
export const LeaderboardIcon = wrap(IconTrophy, "h-5 w-5");
export const ShopIcon = wrap(IconGift, "h-5 w-5");

/*
 * The school subjects (spec 022 · `TaxonomySeeder::SUBJECTS`).
 *
 * Named for the SUBJECT, not the shape, exactly as everything above: the flask
 * belongs to chemistry, and a physics tile that later wants a different glyph is
 * one edit here. `subjectIcon()` in `SubjectsGrid` is what maps a slug onto one.
 */
export const MathIcon = wrap(IconMath, "h-6 w-6");
export const ScienceIcon = wrap(IconMicroscope, "h-6 w-6");
export const PhysicsIcon = wrap(IconAtom, "h-6 w-6");
export const ChemistryIcon = wrap(IconFlask, "h-6 w-6");
export const BiologyIcon = wrap(IconDna, "h-6 w-6");
export const ArabicIcon = wrap(IconFeather, "h-6 w-6");
export const LanguageIcon = wrap(IconLanguage, "h-6 w-6");
export const VocabularyIcon = wrap(IconVocabulary, "h-6 w-6");
export const MosqueIcon = wrap(IconBuildingMosque, "h-6 w-6");
export const SocialStudiesIcon = wrap(IconUsersGroup, "h-6 w-6");
export const HistoryIcon = wrap(IconHourglass, "h-6 w-6");
export const GeographyIcon = wrap(IconWorld, "h-6 w-6");
export const ComputerIcon = wrap(IconDeviceDesktop, "h-6 w-6");
