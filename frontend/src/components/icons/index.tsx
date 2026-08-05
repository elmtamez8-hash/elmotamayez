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
  IconAlertTriangle,
  IconArrowUp,
  IconBook2,
  IconChevronDown,
  IconChevronLeft,
  IconChevronRight,
  IconMenu2,
  IconMoodEmpty,
  IconMoon,
  IconSearch,
  IconStarFilled,
  IconSun,
  IconBrandInstagram,
  IconBrandWhatsapp,
  IconBrandX,
  IconBrandYoutube,
  IconBuilding,
  IconBuildingBank,
  IconCertificate,
  IconCheck,
  IconClipboardText,
  IconFileText,
  IconHome,
  IconInfoCircle,
  IconLogout,
  IconReceiptRefund,
  IconSchool,
  IconSettings,
  IconShieldCheck,
  IconShoppingBag,
  IconTag,
  IconTrash,
  IconUserPlus,
  IconUsers,
  IconX,
  type Icon as TablerIcon,
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
export const CertificateIcon = wrap(IconCertificate, "h-5 w-5");
export const OrdersIcon = wrap(IconShoppingBag, "h-5 w-5");
export const WorkspaceIcon = wrap(IconBuilding, "h-5 w-5");
export const MembersIcon = wrap(IconUsers, "h-5 w-5");
export const SettingsIcon = wrap(IconSettings, "h-5 w-5");
export const LogoutIcon = wrap(IconLogout, "h-4 w-4");
export const TrashIcon = wrap(IconTrash, "h-4 w-4");

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
export const ChevronStartIcon = wrap(IconChevronRight, "h-4 w-4");
export const ChevronEndIcon = wrap(IconChevronLeft, "h-4 w-4");
export const SearchIcon = wrap(IconSearch, "h-6 w-6");
export const MenuIcon = wrap(IconMenu2, "h-6 w-6");
// IconX is the close cross. IconBrandX is the Twitter/X logo — one letter
// apart in Tabler's naming, and the wrong one turns a menu button into an ad.
export const CloseIcon = wrap(IconX, "h-6 w-6");
export const MoonIcon = wrap(IconMoon, "h-5 w-5");
export const SunIcon = wrap(IconSun, "h-5 w-5");
export const AlertIcon = wrap(IconAlertTriangle, "h-6 w-6");
export const EmptyIcon = wrap(IconMoodEmpty, "h-6 w-6");
export const TrustShieldIcon = wrap(IconShieldCheck, "h-3.5 w-3.5");

/**
 * The rating star is the one icon that must be filled, not stroked: a hollow
 * star at 14px reads as "empty" and this component draws both states.
 */
export const StarIcon = wrap(IconStarFilled, "h-4 w-4");
