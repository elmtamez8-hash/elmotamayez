/**
 * رابطُ الفيديو التعريفيِّ ← عنوانُ إطارٍ نبنيه بأنفسِنا.
 *
 * ⚠️ **الرابطُ الذي يكتبُه المدرّسُ لا يصلُ إلى `src` أبداً.** هو نصٌّ حرٌّ من لوحةِ
 * مفاتيحِ صاحبِ الملفّ، يُعرَضُ على صفحةٍ عامّةٍ لكلِّ زائر — فتمريرُه كما هو إلى
 * وسمِ إطارٍ يجعلُ من حقلِ نصٍّ في «ملفّي» باباً يُشغِّلُ ما يشاءُ في متصفِّحِ من
 * يقرأ. هذه الدالّةُ **تستخرجُ المعرِّفَ وتبني العنوانَ من ثوابتِنا**، فما لم
 * تفهمْه تُعيدُ عنه `null` ولا تُخمِّن.
 *
 * والقاعدةُ على الخادمِ (`TeacherListingRules`) تقولُ «لا» أبكرَ وبالعربيّة، لكنّها
 * ليست الحارس: هي رسالةٌ لصاحبِ الحقل، وهذا هو الحدُّ.
 *
 * ⚠️ و`youtube-nocookie` عمداً: صفحةُ مدرّسٍ عامّةٌ يفتحُها زائرٌ لم يُسألْ عن شيء،
 * وإطارٌ يزرعُ متتبِّعاً فيها قرارٌ لم يتّخذْه أحد.
 */
export function videoEmbedUrl(raw: string | null | undefined): string | null {
  if (raw === null || raw === undefined || raw.trim() === "") return null;

  let url: URL;

  try {
    url = new URL(raw.trim());
  } catch {
    return null;
  }

  // `javascript:`، `data:` وكلُّ ما ليسَ نقلاً آمناً يسقطُ هنا قبلَ أيِّ شيءٍ آخر.
  if (url.protocol !== "https:") return null;

  const host = url.hostname.replace(/^www\./, "");

  if (host === "youtu.be") {
    return youtube(url.pathname.slice(1));
  }

  if (host === "youtube.com" || host === "m.youtube.com" || host === "youtube-nocookie.com") {
    if (url.pathname === "/watch") return youtube(url.searchParams.get("v") ?? "");
    if (url.pathname.startsWith("/embed/")) return youtube(url.pathname.slice("/embed/".length));
    if (url.pathname.startsWith("/shorts/")) return youtube(url.pathname.slice("/shorts/".length));

    return null;
  }

  if (host === "vimeo.com" || host === "player.vimeo.com") {
    // `vimeo.com/123` و`player.vimeo.com/video/123`: الرقمُ آخرُ مقطعٍ في كليهما.
    const last = url.pathname.split("/").filter(Boolean).at(-1) ?? "";

    return /^\d{6,12}$/.test(last) ? `https://player.vimeo.com/video/${last}` : null;
  }

  return null;
}

/** معرِّفُ يوتيوبَ أحدَ عشرَ محرفاً من طقمٍ مغلق — وأيُّ شيءٍ آخرَ ليسَ معرِّفاً. */
function youtube(id: string): string | null {
  return /^[A-Za-z0-9_-]{11}$/.test(id) ? `https://www.youtube-nocookie.com/embed/${id}` : null;
}
