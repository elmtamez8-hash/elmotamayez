"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Course } from "@/lib/types";
import Link from "next/link";

export default function CoursesPage() {
  const [courses, setCourses] = useState<Course[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [deleting, setDeleting] = useState<string | null>(null);

  useEffect(() => {
    api.get<{ data: Course[] }>("/courses")
      .then((res) => setCourses(res.data ?? []))
      .catch((err: unknown) => setError(errorMessage(err, "Could not load courses")))
      .finally(() => setLoading(false));
  }, []);

  const filtered = search
    ? courses.filter((c) => c.title.toLowerCase().includes(search.toLowerCase()))
    : courses;

  const handleDelete = async (uuid: string, title: string) => {
    if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;
    setDeleting(uuid);
    try {
      await api.delete(`/courses/${uuid}`);
      setCourses(courses.filter((c) => c.uuid !== uuid));
    } catch {
      // ignore — permission or constraint error
    } finally {
      setDeleting(null);
    }
  };

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold">Course Catalog</h2>
          <p className="text-gray-600">Browse and enroll in available courses</p>
        </div>
        <div className="flex items-center gap-3">
          <input
            type="text"
            placeholder="Search courses..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          />
          <Link href="/manage/courses/new" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
            + New Course
          </Link>
        </div>
      </div>

      {filtered.length === 0 ? (
        <div className="rounded-xl bg-white p-12 text-center shadow-sm ring-1 ring-gray-200">
          <p className="text-gray-500">No courses found.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {filtered.map((course) => (
            <div
              key={course.uuid}
              className="group relative overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200 transition hover:shadow-md"
            >
              <Link href={`/manage/courses/${course.uuid}`} className="block">
                <div className="h-32 bg-gradient-to-br from-indigo-400 to-purple-500" />
                <div className="p-5">
                  <div className="mb-2 flex items-center gap-2">
                    {course.is_free ? (
                      <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">Free</span>
                    ) : (
                      <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                        {course.currency} {course.price}
                      </span>
                    )}
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 capitalize">
                      {course.status}
                    </span>
                  </div>
                  <h3 className="mb-1 font-semibold group-hover:text-indigo-600">{course.title}</h3>
                  <p className="line-clamp-2 text-sm text-gray-500">{course.description}</p>
                </div>
              </Link>
              <button
                onClick={() => handleDelete(course.uuid, course.title)}
                disabled={deleting === course.uuid}
                className="absolute right-3 top-3 rounded-lg bg-white/90 p-1.5 text-gray-500 shadow-sm opacity-0 transition group-hover:opacity-100 hover:text-red-600 disabled:opacity-50"
                title="Delete course"
              >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                </svg>
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
