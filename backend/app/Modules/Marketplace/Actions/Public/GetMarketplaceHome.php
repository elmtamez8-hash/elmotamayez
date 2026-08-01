<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\DTOs\CourseFilterDTO;
use App\Modules\Marketplace\DTOs\TeacherFilterDTO;
use App\Modules\Marketplace\Http\Resources\PublicCourseCardResource;
use App\Modules\Marketplace\Http\Resources\PublicTeacherCardResource;
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
            'testimonials' => $this->testimonials(),
            'faqs' => $this->faqs(),
        ];
    }

    /**
     * Editorial content with no admin surface yet. Kept here rather than hardcoded
     * in the React tree so moving it to the CMS later touches one file.
     *
     * @return list<array{name: string, role: string, quote: string, photo_url: string|null}>
     */
    private function testimonials(): array
    {
        return [
            [
                'name' => 'نورة العلي',
                'role' => 'وليّة أمر',
                'quote' => 'ابني تحسّن في الرياضيات خلال شهرين. أهم ما أعجبني أنني أرى تقييمات المدرّس قبل الحجز.',
                'photo_url' => null,
            ],
            [
                'name' => 'عبدالله المري',
                'role' => 'طالب ثانوية',
                'quote' => 'الحصص المباشرة مرنة وأقدر أعيد الحصص المسجّلة قبل الاختبار مباشرة.',
                'photo_url' => null,
            ],
            [
                'name' => 'مريم الكواري',
                'role' => 'وليّة أمر',
                'quote' => 'درجة الثقة وفّرت عليّ وقت البحث — أعرف التزام المدرّس بالمواعيد قبل أن أحجز.',
                'photo_url' => null,
            ],
        ];
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
