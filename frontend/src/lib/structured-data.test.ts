import { describe, expect, it, vi } from "vitest";

vi.mock("@/lib/platform", () => ({ platformName: async () => "المتميز" }));

import type { CourseDetail, TeacherDetail } from "@/lib/public-api";
import { publicPageMetadata } from "@/lib/seo";
import { SITE_URL } from "@/lib/site";
import { courseLd, organizationLd, personLd } from "./structured-data";

describe("publicPageMetadata", () => {
  it("names the bare path as canonical and og:url, and keeps the whole card", async () => {
    const meta = await publicPageMetadata({
      path: "/teachers",
      title: "المدرسون",
      description: "وصف",
      image: "/marketplace/banner-teachers.webp",
    });

    expect(meta.alternates?.canonical).toBe(`${SITE_URL}/teachers`);
    // Next does not deep-merge `openGraph`: every field the layout set must be here.
    expect(meta.openGraph).toMatchObject({
      url: `${SITE_URL}/teachers`,
      siteName: "المتميز",
      locale: "ar_QA",
      type: "website",
      images: [{ url: `${SITE_URL}/marketplace/banner-teachers.webp` }],
    });
  });
});

describe("organizationLd", () => {
  it("states the support line only when there is one", () => {
    const withLine = organizationLd({ name: "المتميز", supportWhatsapp: "97455501234", contactEmail: "", legalName: "" });
    const without = organizationLd({ name: "المتميز", supportWhatsapp: "", contactEmail: "", legalName: "" });

    expect(withLine).toMatchObject({ "@type": "EducationalOrganization", url: SITE_URL });
    expect(withLine.contactPoint).toMatchObject({ telephone: "+97455501234" });
    expect(without).not.toHaveProperty("contactPoint");
  });
});

const teacher = {
  uuid: "t-1",
  slug: "ahmad",
  name: "أحمد",
  headline: "مدرّس رياضيات",
  photo_url: "https://cdn.example.com/a.jpg",
  subjects: [{ slug: "math", name: "الرياضيات" }],
  teaching_languages: ["ar"],
  bio: null,
  average_rating: 4.8,
  reviews_count: 12,
} as unknown as TeacherDetail;

describe("personLd", () => {
  it("carries a rating only with reviews behind it", () => {
    expect(personLd(teacher, `${SITE_URL}/teachers/ahmad`)).toMatchObject({
      "@type": "Person",
      name: "أحمد",
      jobTitle: "مدرّس رياضيات",
      knowsAbout: ["الرياضيات"],
      aggregateRating: { ratingValue: 4.8, reviewCount: 12 },
    });

    expect(personLd({ ...teacher, reviews_count: 0 }, "x")).not.toHaveProperty("aggregateRating");
  });

  it("drops a photo that is not an absolute http(s) URL", () => {
    expect(personLd({ ...teacher, photo_url: "javascript:alert(1)" }, "x")).not.toHaveProperty("image");
  });
});

describe("courseLd", () => {
  const course = {
    uuid: "c-1",
    slug: "algebra",
    title: "الجبر",
    description: null,
    cover_url: null,
    subject: { slug: "math", name: "الرياضيات", icon: null },
    teacher: { uuid: "t-1", slug: "ahmad", name: "أحمد" },
    price_minor: 5000,
    currency: "QAR",
    free_enrollment: false,
  } as unknown as CourseDetail;

  it("names the platform as provider and the teacher as instructor, and never a price", () => {
    const ld = courseLd(course, `${SITE_URL}/courses/algebra`, "المتميز");

    expect(ld).toMatchObject({
      "@type": "Course",
      name: "الجبر",
      description: "الجبر",
      provider: { "@type": "Organization", name: "المتميز" },
      instructor: { "@type": "Person", name: "أحمد", url: `${SITE_URL}/teachers/ahmad` },
    });
    // A course is sold through a plan; `price_minor` prices nothing a buyer can pay.
    expect(ld).not.toHaveProperty("offers");
    expect(ld).not.toHaveProperty("isAccessibleForFree");
  });

  it("says free only on the server's free_enrollment", () => {
    expect(courseLd({ ...course, free_enrollment: true }, "x", "المتميز")).toMatchObject({
      isAccessibleForFree: true,
    });
  });
});
