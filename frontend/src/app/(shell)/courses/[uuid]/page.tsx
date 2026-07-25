"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Course } from "@/lib/types";
import { useRouter } from "next/navigation";
import { use } from "react";

interface CourseDetail extends Course {
  sections?: Array<{
    id: number;
    title: string;
    order: number;
    is_published: boolean;
    chapters?: Array<{
      id: number;
      title: string;
      order: number;
      lessons?: Array<{
        uuid: string;
        title: string;
        type: string;
        order: number;
        is_preview: boolean;
        duration_seconds: number;
      }>;
    }>;
  }>;
}

export default function CourseDetailPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const router = useRouter();
  const [course, setCourse] = useState<CourseDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [enrolling, setEnrolling] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<CourseDetail>(`/courses/${uuid}`)
      .then(setCourse)
      .catch(() => setError("Course not found"))
      .finally(() => setLoading(false));
  }, [uuid]);

  const handleEnroll = async () => {
    setEnrolling(true);
    setError("");
    try {
      await api.post(`/courses/${uuid}/enroll`);
      router.push("/enrollments");
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Enrollment failed";
      setError(msg);
    } finally {
      setEnrolling(false);
    }
  };

  if (loading) return <div className="text-gray-400">Loading...</div>;
  if (!course) return <div className="text-red-500">{error}</div>;

  return (
    <div className="space-y-6">
      <div className="rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 p-8 text-white">
        <h2 className="mb-2 text-3xl font-bold">{course.title}</h2>
        <p className="mb-4 max-w-2xl text-indigo-100">{course.description}</p>
        <div className="flex items-center gap-4">
          {course.is_free ? (
            <span className="rounded-full bg-white/20 px-3 py-1 text-sm font-medium">Free</span>
          ) : (
            <span className="rounded-full bg-white/20 px-3 py-1 text-sm font-medium">
              {course.currency} {course.price}
            </span>
          )}
          <span className="text-sm text-indigo-100">
            {course.is_sequential ? "Sequential learning" : "Flexible learning"}
          </span>
        </div>
      </div>

      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="flex gap-3">
        <a href={`/courses/${uuid}/edit`} className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
          Edit Course
        </a>
        {course.is_free ? (
          <button
            onClick={handleEnroll}
            disabled={enrolling}
            className="rounded-lg bg-indigo-600 px-6 py-2.5 font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50"
          >
            {enrolling ? "Enrolling..." : "Enroll Free"}
          </button>
        ) : (
          <button
            onClick={() => router.push(`/courses/${uuid}/order`)}
            className="rounded-lg bg-amber-600 px-6 py-2.5 font-medium text-white transition hover:bg-amber-700"
          >
            Purchase ({course.currency} {course.price})
          </button>
        )}
      </div>

      {course.sections && course.sections.length > 0 && (
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
          <h3 className="mb-4 font-semibold">Course Content</h3>
          <div className="space-y-4">
            {course.sections.map((section) => (
              <div key={section.id}>
                <h4 className="mb-2 text-sm font-medium text-gray-700">{section.title}</h4>
                {section.chapters?.map((chapter) => (
                  <div key={chapter.id} className="ml-4 space-y-1">
                    <p className="text-xs text-gray-500">{chapter.title}</p>
                    {chapter.lessons?.map((lesson) => (
                      <div key={lesson.uuid} className="ml-4 flex items-center gap-2 text-sm">
                        <span className="text-gray-400">•</span>
                        <span className={lesson.is_preview ? "text-indigo-600" : "text-gray-600"}>
                          {lesson.title}
                        </span>
                        {lesson.is_preview && (
                          <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-600">Preview</span>
                        )}
                      </div>
                    ))}
                  </div>
                ))}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
