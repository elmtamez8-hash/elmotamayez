import Link from "next/link";

export default function PublicNotFound() {
  return (
    <div className="mx-auto flex max-w-2xl flex-col items-center px-4 py-24 text-center sm:px-6">
      <h1 className="mb-3 text-3xl font-extrabold text-ink">غير متاح</h1>
      <p className="mb-8 leading-relaxed text-ink-muted">
        الصفحة التي تبحث عنها غير موجودة، أو أن المدرّس لم يعد متاحاً على المنصة.
      </p>
      <div className="flex flex-wrap justify-center gap-3">
        <Link
          href="/teachers"
          className="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
        >
          تصفّح المدرّسين
        </Link>
        <Link
          href="/"
          className="rounded-xl border border-line px-5 py-2.5 text-sm font-semibold text-ink transition hover:border-primary hover:text-primary-ink"
        >
          العودة للرئيسية
        </Link>
      </div>
    </div>
  );
}
