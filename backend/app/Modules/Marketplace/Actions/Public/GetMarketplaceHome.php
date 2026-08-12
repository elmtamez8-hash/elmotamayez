<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\DTOs\CourseFilterDTO;
use App\Modules\Marketplace\DTOs\TeacherFilterDTO;
use App\Modules\Marketplace\Http\Resources\PublicCourseCardResource;
use App\Modules\Marketplace\Http\Resources\PublicTeacherCardResource;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Cache;

/**
 * One aggregated payload for the home page.
 *
 * The page needs six sections; six round trips from the server component would
 * put first paint out of reach of SC-007 (content visible in under two seconds on
 * a mid-tier mobile connection).
 */
class GetMarketplaceHome extends Action
{
    public function __construct(
        private readonly GetMarketplaceStats $stats,
        private readonly ListPublicTeachers $teachers,
        private readonly ListPublicTaxonomy $taxonomy,
        private readonly ListPublicCourses $courses,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $featured = Cache::remember(
            MarketplaceCache::key('home:featured'),
            MarketplaceCache::ttl(),
            fn () => PublicTeacherCardResource::collection(
                $this->teachers->handle(TeacherFilterDTO::fromArray([
                    'sort' => TeacherFilterDTO::SORT_TRUST,
                    'per_page' => 6,
                ]))->items(),
            )->resolve(),
        );

        $featuredCourses = Cache::remember(
            MarketplaceCache::key('home:featured-courses'),
            MarketplaceCache::ttl(),
            fn () => PublicCourseCardResource::collection(
                $this->courses->handle(CourseFilterDTO::fromArray([
                    'sort' => CourseFilterDTO::SORT_POPULAR,
                    'per_page' => 6,
                ]))->items(),
            )->resolve(),
        );

        return [
            'stats' => $this->stats->handle(),
            'featured_teachers' => $featured,
            'featured_courses' => $featuredCourses,
            'subjects' => $this->taxonomy->handle(ListPublicTaxonomy::SUBJECTS),
            'testimonials' => Cache::remember(
                MarketplaceCache::key('home:testimonials'),
                MarketplaceCache::ttl(),
                fn () => $this->testimonials(),
            ),
            'faqs' => $this->faqs(),
        ];
    }

    /**
     * Real reviews, written by students who took a session.
     *
     * ⚠️ THIS METHOD USED TO RETURN THREE HARDCODED QUOTES. They were attributed
     * to invented people carrying real Qatari family names — نورة العلي,
     * عبدالله المري, مريم الكواري — and served to every visitor with nothing
     * marking them as samples. PRODUCT.md's `Evidence on Hand` bans exactly
     * that: the product is pre-launch with no customers, and any quote on a
     * surface is labelled demo data or does not appear.
     *
     * The shape returned here is PublicFieldAllowlist::REVIEW, unchanged — the
     * same one the teacher's own page publishes. Reusing it rather than
     * inventing a second `{name, role, quote}` shape means the allowlist
     * already governs these fields, and a name shown here is the same
     * `studentDisplayName()` ("أحمد م.") the reviewer already agreed to on the
     * teacher page: enough to show a real person wrote it, not enough to
     * identify them to the teacher they just rated (FR-021).
     *
     * ⚠️ The query starts from publiclyListed(), like every other public read
     * in this module. Review carries BelongsToWorkspace, and WorkspaceScope is
     * INERT for a guest — so without this guard the home page would publish
     * reviews of teachers who never agreed to be listed.
     *
     * On launch day this returns an empty list and the section does not render.
     * That is the honest state, and it is the one the carousel already handles.
     *
     * Each quote carries the teacher it is ABOUT — name, photo and slug — so the
     * card has a face without publishing the reviewer's. See the note on
     * PublicFieldAllowlist::REVIEW for why that direction is the only one open.
     *
     * @return list<array{student_display_name: string, rating: int, comment: string, created_at: string, teacher_slug: string|null, teacher_name: string|null, teacher_photo_url: string|null}>
     */
    private function testimonials(): array
    {
        // array_values, not ->all(): a Collection's keys survive ->all(), so the
        // result is an array<int, …> and not the list the return type promises.
        // It also decides how this serialises — a non-list becomes a JSON object
        // instead of an array, and the carousel would receive something it
        // cannot map over.
        return array_values(Review::query()
            ->withoutWorkspaceScope()
            ->where('is_visible', true)
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->where('rating', '>=', 4)
            ->whereIn(
                'teacher_profile_id',
                TeacherProfile::query()->publiclyListed()->select('teacher_profiles.id'),
            )
            // teacherProfile.user eager loaded together: a Resource-style read of
            // the teacher inside the map would be one query per quote, which is
            // six on every home page render.
            ->with(['student:id,first_name,last_name', 'teacherProfile.user'])
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(function (Review $review): array {
                $profile = $review->teacherProfile;

                return [
                    'student_display_name' => $review->studentDisplayName(),
                    'rating' => $review->rating,
                    'comment' => (string) $review->comment,
                    'created_at' => $review->created_at?->toIso8601String() ?? '',
                    'teacher_slug' => $profile?->slug,
                    'teacher_name' => $profile?->user?->name,
                    'teacher_photo_url' => $profile?->photo_path === null
                        ? null
                        : asset('storage/'.$profile->photo_path),
                ];
            })
            ->all());
    }

    /** @return list<array{question: string, answer: string}> */
    private function faqs(): array
    {
        return [
            [
                'question' => 'كيف أختار المدرّس المناسب؟',
                'answer' => 'فلتر حسب المادة والمرحلة والسعر، ثم قارن بين المدرّسين بدرجة الثقة وتقييمات الطلاب السابقين. كل ملف يعرض العوامل التي بُنيت عليها الدرجة.',
            ],
            [
                'question' => 'ما معنى درجة الثقة؟',
                'answer' => 'رقم من 100 يجمع تقييمات الطلاب، الالتزام بالمواعيد، نسبة إكمال الحصص دون إلغاء، ومدة العمل على المنصة. المدرّس الجديد تظهر درجته «قيد التكوين» حتى تتوفّر بيانات كافية.',
            ],
            [
                'question' => 'هل الحصص مباشرة أم مسجّلة؟',
                'answer' => 'الاثنان. تختار حصصاً مباشرة فردية أو جماعية حسب جدول المدرّس، أو كورساً مسجّلاً تشاهده في وقتك.',
            ],
            [
                'question' => 'كيف يتم اعتماد المدرّسين؟',
                'answer' => 'كل مدرّس يقدّم مؤهلاته ويراجعها فريقنا الأكاديمي يدوياً قبل ظهوره على المنصة. المدرّس غير المعتمد لا يظهر في نتائج البحث.',
            ],
            [
                'question' => 'هل يمكن لوليّ الأمر متابعة أبنائه؟',
                'answer' => 'نعم، يمكن لوليّ الأمر ربط حسابات أبنائه بحسابه وتلقّي تقارير أسبوعية وتنبيهات بمواعيد الحصص.',
            ],
        ];
    }
}
