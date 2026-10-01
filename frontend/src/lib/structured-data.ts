import { absoluteHttpUrl } from "@/components/seo/JsonLd";
import type { CourseDetail, TeacherDetail } from "@/lib/public-api";
import { SITE_URL, siteUrl } from "@/lib/site";
import { htmlToText } from "@/lib/html-text";

/**
 * The schema.org objects the public pages embed through `<JsonLd>` (the
 * component does the escaping; these only build the data).
 *
 * ⚠️ EVERY FIELD IS ONE THE PAGE ALREADY SHOWS, and nothing is stated that the
 * payload did not carry. An `aggregateRating` with no reviews behind it, or an
 * `offers` with an invented price, is the kind of markup a search engine
 * penalises the whole site for — so each optional block is added only when its
 * data is really there, and left out rather than filled with a zero.
 */

type Ld = Record<string, unknown>;

/** The platform itself — the home page's block. */
export function organizationLd(identity: {
  name: string;
  supportWhatsapp: string;
  contactEmail: string;
  legalName: string;
}): Ld {
  const contact =
    identity.supportWhatsapp !== "" || identity.contactEmail !== ""
      ? {
          contactPoint: {
            "@type": "ContactPoint",
            contactType: "customer support",
            availableLanguage: "ar",
            ...(identity.supportWhatsapp !== "" ? { telephone: `+${identity.supportWhatsapp}` } : {}),
            ...(identity.contactEmail !== "" ? { email: identity.contactEmail } : {}),
          },
        }
      : {};

  return {
    "@context": "https://schema.org",
    "@type": "EducationalOrganization",
    name: identity.name,
    ...(identity.legalName !== "" ? { legalName: identity.legalName } : {}),
    url: SITE_URL,
    logo: siteUrl("/brand/icon-512.png"),
    ...contact,
  };
}

/** A teacher's public profile. `url` is the page's own canonical. */
export function personLd(teacher: TeacherDetail, url: string): Ld {
  const image = absoluteHttpUrl(teacher.photo_url);
  const subjects = teacher.subjects.map((subject) => subject.name);

  return {
    "@context": "https://schema.org",
    "@type": "Person",
    name: teacher.name,
    url,
    ...(image !== null ? { image } : {}),
    ...(teacher.headline ? { jobTitle: teacher.headline } : {}),
    ...(teacher.bio ? { description: teacher.bio.slice(0, 300) } : {}),
    ...(subjects.length > 0 ? { knowsAbout: subjects } : {}),
    ...(teacher.teaching_languages.length > 0 ? { knowsLanguage: teacher.teaching_languages } : {}),
    // Only with real reviews behind it: a rating of nothing is not a rating.
    ...(teacher.average_rating !== null && teacher.reviews_count > 0
      ? {
          aggregateRating: {
            "@type": "AggregateRating",
            ratingValue: teacher.average_rating,
            reviewCount: teacher.reviews_count,
            bestRating: 5,
            worstRating: 1,
          },
        }
      : {}),
  };
}

/**
 * A course page. `provider` is the platform — it sells the course — and the
 * teacher is the `instructor`, each linked to the page that describes it.
 */
export function courseLd(course: CourseDetail, url: string, platformName: string): Ld {
  const image = absoluteHttpUrl(course.cover_url);
  const teacherUrl = course.teacher
    ? siteUrl(`/teachers/${encodeURIComponent(course.teacher.slug ?? course.teacher.uuid)}`)
    : null;

  return {
    "@context": "https://schema.org",
    "@type": "Course",
    name: course.title,
    // Google requires a description; the title stands in when the teacher wrote none.
    description: (htmlToText(course.description_html) || course.title).slice(0, 300),
    url,
    inLanguage: "ar",
    ...(image !== null ? { image } : {}),
    provider: { "@type": "Organization", name: platformName, sameAs: SITE_URL },
    ...(course.teacher && teacherUrl
      ? { instructor: { "@type": "Person", name: course.teacher.name, url: teacherUrl } }
      : {}),
    ...(course.subject ? { about: course.subject.name } : {}),
    /*
     * ⛔ NO `offers`, EVER. A course is sold through a plan and never priced on
     * its own (owner decision 2026-09-25 — `CourseRail` prints no price either),
     * so `price_minor` prices nothing a buyer can pay; in markup it would be an
     * offer a search result advertises and the checkout does not honour. Free
     * is stated only on the server's `free_enrollment`, exactly as the rail does.
     */
    ...(course.free_enrollment ? { isAccessibleForFree: true } : {}),
  };
}
