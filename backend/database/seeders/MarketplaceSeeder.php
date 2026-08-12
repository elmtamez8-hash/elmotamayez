<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Seeder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds the shared taxonomy into every workspace.
 *
 * Subjects and grade levels are tenant-owned rows, but the *slugs* are a platform
 * vocabulary: public filtering matches on slug so a "math" filter finds teachers
 * across every participating workspace.
 */
class MarketplaceSeeder extends Seeder
{
    /** Walks COMMENTS across every teacher seeded — see the note on that list. */
    private int $commentCursor = 0;

    /** @var array<string, string|null> published demo asset paths, keyed folder/file */
    private array $publishedAssets = [];

    /** @var list<array{slug: string, name_ar: string, icon: string}> */
    private const SUBJECTS = [
        ['slug' => 'math', 'name_ar' => 'الرياضيات', 'icon' => 'calculator'],
        ['slug' => 'physics', 'name_ar' => 'الفيزياء', 'icon' => 'beaker'],
        ['slug' => 'chemistry', 'name_ar' => 'الكيمياء', 'icon' => 'beaker'],
        ['slug' => 'biology', 'name_ar' => 'الأحياء', 'icon' => 'beaker'],
        ['slug' => 'arabic', 'name_ar' => 'اللغة العربية', 'icon' => 'book-open'],
        ['slug' => 'english', 'name_ar' => 'اللغة الإنجليزية', 'icon' => 'language'],
        ['slug' => 'french', 'name_ar' => 'اللغة الفرنسية', 'icon' => 'language'],
        ['slug' => 'islamic-studies', 'name_ar' => 'التربية الإسلامية', 'icon' => 'book-open'],
        ['slug' => 'computer-science', 'name_ar' => 'الحاسب الآلي', 'icon' => 'computer-desktop'],
    ];

    /** @var list<array{slug: string, name_ar: string}> */
    private const GRADE_LEVELS = [
        ['slug' => 'primary', 'name_ar' => 'المرحلة الابتدائية'],
        ['slug' => 'preparatory', 'name_ar' => 'المرحلة الإعدادية'],
        ['slug' => 'secondary', 'name_ar' => 'المرحلة الثانوية'],
        ['slug' => 'university', 'name_ar' => 'المرحلة الجامعية'],
    ];

    public function run(): void
    {
        Workspace::query()->each(function (Workspace $workspace): void {
            // forWorkspace(), not set(): a seeder runs outside an HTTP request and
            // set() would leave the context pointing at the last workspace seeded.
            app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
                foreach (self::SUBJECTS as $i => $subject) {
                    Subject::query()->updateOrCreate(
                        ['workspace_id' => $workspace->getKey(), 'slug' => $subject['slug']],
                        [...$subject, 'sort_order' => $i, 'is_active' => true],
                    );
                }

                foreach (self::GRADE_LEVELS as $i => $level) {
                    GradeLevel::query()->updateOrCreate(
                        ['workspace_id' => $workspace->getKey(), 'slug' => $level['slug']],
                        [...$level, 'sort_order' => $i, 'is_active' => true],
                    );
                }
            });
        });

        if (! app()->environment('production')) {
            $this->seedDemoMarketplace();
        }
    }

    /**
     * Publish the first workspace and give it teachers, so the public pages have
     * something to render locally.
     *
     * Never runs in production: publishing a real workspace to the marketplace is
     * an explicit opt-in decision (FR-001), not something a seeder makes for you.
     */
    private function seedDemoMarketplace(): void
    {
        $workspace = Workspace::query()->orderBy('id')->first();

        if ($workspace === null) {
            return;
        }

        $workspace->forceFill(['participates_in_marketplace' => true])->save();

        app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): void {
            $subjects = Subject::query()->pluck('id', 'slug');
            $levels = GradeLevel::query()->pluck('id', 'slug');

            foreach (self::DEMO_TEACHERS as $demo) {
                $user = User::factory()->create([
                    'first_name' => $demo['first_name'],
                    'last_name' => $demo['last_name'],
                    'platform_role' => 'teacher',
                ]);

                $profile = TeacherProfile::factory()
                    ->published()
                    ->create([
                        'workspace_id' => $workspace->getKey(),
                        'user_id' => $user->getKey(),
                        'headline' => $demo['headline'],
                        'bio' => $demo['bio'],
                        'qualifications' => $demo['qualifications'],
                        'years_experience' => $demo['years'],
                        'hourly_rate' => $demo['rate'],
                        'teaching_languages' => ['ar', 'en'],
                        'is_verified' => $demo['verified'],
                        'photo_path' => $this->publishAsset('teachers', $demo['photo']),
                        // Hand-written, overriding TeacherSlug's generated
                        // `ahmd-almnswry`: Arabic writes no short vowels, so the
                        // transliterator cannot recover them. This is what the
                        // column is fillable FOR, and the demo proves it works.
                        'slug' => $demo['slug'],
                    ]);

                // One teacher is left with no score so the "building" state is
                // visible locally without editing the database by hand.
                if ($demo['score'] !== null) {
                    $profile->forceFill([
                        'trust_score' => $demo['score'],
                        'trust_score_factors' => [
                            'student_rating' => $demo['score'] + 6,
                            'punctuality' => $demo['score'] - 2,
                            'completion' => $demo['score'] + 3,
                            'tenure' => 100,
                            'complaints_penalty' => 0,
                        ],
                        'trust_score_calculated_at' => now(),
                        'completed_sessions_count' => $demo['sessions'],
                        'students_taught_count' => (int) round($demo['sessions'] / 3),
                        'reviews_count' => $demo['reviews'],
                        'average_rating' => $demo['rating'],
                        'response_rate' => 95,
                        'attendance_rate' => $demo['score'] - 2,
                        'first_session_at' => now()->subYear(),
                    ])->save();
                }

                foreach ($demo['subjects'] as $slug) {
                    if (isset($subjects[$slug])) {
                        $profile->subjects()->syncWithoutDetaching([$subjects[$slug]]);
                    }
                }

                foreach ($demo['levels'] as $slug) {
                    if (isset($levels[$slug])) {
                        $profile->gradeLevels()->syncWithoutDetaching([$levels[$slug]]);
                    }
                }

                // One published course per demo teacher so the courses page and the
                // home carousel have real rows rather than an empty state.
                Course::factory()->published()->create([
                    'workspace_id' => $workspace->getKey(),
                    'created_by' => $user->getKey(),
                    'title' => $demo['course']['title'],
                    'slug' => Str::slug($demo['course']['title'].'-'.$user->getKey()),
                    'description' => $demo['bio'],
                    'price' => $demo['course']['price'],
                    'price_before_discount' => $demo['course']['price_before'],
                    'currency' => 'QAR',
                    'language' => 'ar',
                    'course_type' => $demo['course']['type'],
                    'duration_seconds' => $demo['course']['hours'] * 3600,
                    'cover_path' => $this->publishAsset('courses', $demo['course']['cover']),
                ]);

                $this->seedReviews($workspace, $profile, $demo['reviews'], (float) $demo['rating']);

                foreach ($demo['availability'] as [$day, $start, $end]) {
                    AvailabilitySlot::query()->create([
                        'workspace_id' => $workspace->getKey(),
                        'teacher_profile_id' => $profile->getKey(),
                        'day_of_week' => $day,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]);
                }
            }
        });

        MarketplaceCache::flush();

        $this->command->info('Marketplace demo seeded: '.count(self::DEMO_TEACHERS).' published teachers.');
    }

    /**
     * Copy a committed demo image into the public disk and return its stored path.
     *
     * `photo_path` and `cover_path` are read back as `asset('storage/'.$path)`, so
     * the file has to sit under `storage/app/public` — the uploads directory,
     * which is gitignored. Committing the source under `database/seeders/assets`
     * and copying on seed is what makes a fresh checkout render the same page as
     * this one; a path pointing at a file nobody shipped is a broken image with a
     * plausible name.
     *
     * Idempotent by design: the seeder is re-run against an existing database far
     * more often than against an empty one.
     */
    private function publishAsset(string $folder, string $file): ?string
    {
        // Memoised: the six student avatars are shared across a hundred-odd
        // reviews, and copying the same bytes once per row is a hundred writes
        // to say one thing.
        if (array_key_exists($folder.'/'.$file, $this->publishedAssets)) {
            return $this->publishedAssets[$folder.'/'.$file];
        }

        $source = database_path('seeders/assets/'.$folder.'/'.$file);

        if (! is_file($source)) {
            // Not an exception: a missing demo image must never stop the seed that
            // creates the accounts everyone signs in with. The card falls back to
            // initials, which is exactly what it does for a real teacher who has
            // not uploaded a photo.
            $this->command->warn("Demo asset missing, skipped: {$folder}/{$file}");

            return $this->publishedAssets[$folder.'/'.$file] = null;
        }

        Storage::disk('public')->putFileAs(
            'marketplace/'.$folder,
            new File($source),
            $file,
        );

        return $this->publishedAssets[$folder.'/'.$file] = 'marketplace/'.$folder.'/'.$file;
    }

    /**
     * Real review rows behind the numbers already on the profile.
     *
     * The ratings are chosen to land on the demo average rather than drawn at
     * random: the profile shows the mean, the star distribution and the comments
     * on one screen, and three sources disagreeing reads as a bug in the page.
     */
    private function seedReviews(Workspace $workspace, TeacherProfile $profile, int $count, float $target): void
    {
        if ($count === 0) {
            return;
        }

        $ratings = array_fill(0, $count, 3);
        $remaining = (int) round($target * $count) - 3 * $count;

        for ($i = 0; $i < $count && $remaining > 0; $i++) {
            $step = min(2, $remaining);
            $ratings[$i] += $step;
            $remaining -= $step;
        }

        foreach ($ratings as $index => $rating) {
            // Arabic names, not the factory's faker defaults: the profile renders
            // these as "أحمد م." beside Arabic copy, and an English name there is
            // the kind of demo detail that gets screenshotted.
            $student = User::factory()->create([
                'platform_role' => 'student',
                'first_name' => self::STUDENT_FIRST_NAMES[$index % count(self::STUDENT_FIRST_NAMES)],
                'last_name' => self::STUDENT_LAST_NAMES[$index % count(self::STUDENT_LAST_NAMES)],
            ]);

            // The avatar has to agree with the NAME, not just cycle: the six
            // portraits run m,f,m,f,m,f and STUDENT_FIRST_NAMES alternates the
            // same way, so both stay in step for every index because six is even.
            // Reorder either list and أحمد gets a photo of a woman.
            $student->studentProfile()->create([
                'avatar_path' => $this->publishAsset(
                    'students',
                    'student-'.(($index % 6) + 1).'.webp',
                ),
            ]);

            Review::query()->create([
                'workspace_id' => $workspace->getKey(),
                'teacher_profile_id' => $profile->getKey(),
                'student_id' => $student->getKey(),
                'rating' => $rating,
                // The cursor is a property, not a local: the home carousel reads
                // the latest six reviews ACROSS teachers, so a counter that reset
                // per teacher would still hand it the same opening sentence six
                // times over.
                'comment' => $index % 3 === 0
                    ? self::COMMENTS[$this->commentCursor++ % count(self::COMMENTS)]
                    : null,
            ]);
        }

        $profile->forceFill([
            'reviews_count' => $count,
            'average_rating' => round(array_sum($ratings) / $count, 2),
        ])->save();
    }

    /** @var list<string> */
    private const STUDENT_FIRST_NAMES = [
        'أحمد', 'سارة', 'محمد', 'نورة', 'عبدالله', 'مريم', 'خالد', 'لطيفة',
        'يوسف', 'هند', 'راشد', 'شيخة',
    ];

    /** @var list<string> */
    private const STUDENT_LAST_NAMES = [
        'مبارك', 'الكواري', 'العطية', 'المري', 'الهاجري', 'النعيمي', 'السليطي',
    ];

    /**
     * ⚠️ Read `$written`, not `$index`, when picking from this list.
     *
     * The old line was `$index % 3 === 0 ? COMMENTS[$index % count(COMMENTS)]`.
     * Only every third review got a comment, so `$index` was always a multiple of
     * three — and with three entries, `$index % 3` was always **0**. Every
     * commented review on the platform carried the same sentence, and the home
     * carousel, which takes the latest six across all teachers, showed one quote
     * repeated six times behind six different names.
     *
     * The pool is also longer than the stride now, because a pool whose length
     * divides the stride reproduces the same collapse with different numbers.
     *
     * @var list<string>
     */
    private const COMMENTS = [
        'شرح واضح وصبور جداً مع ابني، تحسّنت درجاته خلال شهرين.',
        'يلتزم بالمواعيد ويرسل ملخّصاً بعد كل حصة.',
        'أسلوبه عملي ويركّز على نقاط الضعف بدل إعادة المنهج كله.',
        'ابنتي كانت تكره المادة، والآن تسألني متى الحصة القادمة.',
        'التقرير الأسبوعي وحده يستحق الاشتراك — أعرف تماماً أين وصلنا.',
        'يشرح الفكرة بأكثر من طريقة حتى تصل، ولا يستعجل الطالب أبداً.',
        'حجزنا حصة تجريبية للتجربة فقط، وأكملنا الفصل كاملاً معه.',
        'ملتزمة ومنظّمة، وترسل تمارين إضافية عند طلبها بلا تردّد.',
        'أفضل ما فيه أنه يصحّح طريقة الحل لا الإجابة فقط.',
        'تعاملها مع الأطفال هادئ ومشجّع، وابني صار يشارك من نفسه.',
        'استفدنا كثيراً في مراجعة الامتحان النهائي، والدرجة تحسّنت فعلاً.',
    ];

    /** @var list<array<string, mixed>> */
    private const DEMO_TEACHERS = [
        [
            'first_name' => 'أحمد', 'last_name' => 'المنصوري',
            'headline' => 'مدرّس رياضيات وفيزياء للمرحلة الثانوية',
            'bio' => "أدرّس الرياضيات والفيزياء منذ اثني عشر عاماً، وأركّز على بناء الفهم قبل الحفظ.\n\nأبدأ كل باقة باختبار تشخيصي قصير لتحديد الفجوات، ثم أبني خطة أسبوعية واضحة يتابعها وليّ الأمر.",
            'qualifications' => ['ماجستير الرياضيات التطبيقية — جامعة قطر', 'دبلوم تربوي', 'معتمد في مناهج IGCSE'],
            'years' => 12, 'rate' => '180.00', 'verified' => true,
            'score' => 91, 'sessions' => 640, 'reviews' => 48, 'rating' => 4.8,
            'subjects' => ['math', 'physics'], 'levels' => ['secondary', 'university'],
            'photo' => 'ahmed-almansouri.webp',
            'slug' => 'ahmed-almansouri',
            'course' => ['title' => 'الرياضيات للثانوية العامة — الفصل الأول', 'price' => '750.00', 'price_before' => '950.00', 'type' => 'group', 'hours' => 24, 'cover' => 'mathematics.webp'],
            'availability' => [[0, '13:00:00', '17:00:00'], [2, '13:00:00', '17:00:00'], [4, '10:00:00', '14:00:00']],
        ],
        [
            'first_name' => 'فاطمة', 'last_name' => 'الهاشمي',
            'headline' => 'معلّمة لغة عربية وتربية إسلامية',
            'bio' => "أعمل مع طلاب المرحلتين الابتدائية والإعدادية على القواعد والتعبير والإملاء.\n\nحصصي تفاعلية وقصيرة التركيز، وأرسل تقريراً أسبوعياً لوليّ الأمر.",
            'qualifications' => ['بكالوريوس اللغة العربية — جامعة القاهرة', 'خبرة عشر سنوات في مدارس قطر'],
            'years' => 10, 'rate' => '120.00', 'verified' => true,
            'score' => 84, 'sessions' => 410, 'reviews' => 31, 'rating' => 4.6,
            'subjects' => ['arabic', 'islamic-studies'], 'levels' => ['primary', 'preparatory'],
            'photo' => 'fatima-alhashimi.webp',
            'slug' => 'fatima-alhashimi',
            'course' => ['title' => 'النحو العربي المبسّط', 'price' => '400.00', 'price_before' => null, 'type' => 'individual', 'hours' => 12, 'cover' => 'arabic-grammar.webp'],
            'availability' => [[1, '14:00:00', '18:00:00'], [3, '14:00:00', '18:00:00']],
        ],
        [
            'first_name' => 'سارة', 'last_name' => 'العتيبي',
            'headline' => 'مدرّسة كيمياء وأحياء — مناهج دولية',
            'bio' => 'متخصّصة في الكيمياء العضوية والأحياء لطلاب الثانوية والسنة التحضيرية الجامعية، بخبرة في مناهج IB و A-Level.',
            'qualifications' => ['ماجستير الكيمياء الحيوية', 'خبرة في مناهج IB و A-Level'],
            'years' => 7, 'rate' => '200.00', 'verified' => true,
            'score' => 76, 'sessions' => 180, 'reviews' => 14, 'rating' => 4.3,
            'subjects' => ['chemistry', 'biology'], 'levels' => ['secondary', 'university'],
            'photo' => 'sara-alotaibi.webp',
            'slug' => 'sara-alotaibi',
            'course' => ['title' => 'الكيمياء العضوية من الصفر', 'price' => '620.00', 'price_before' => null, 'type' => 'recorded', 'hours' => 18, 'cover' => 'chemistry.webp'],
            'availability' => [[5, '09:00:00', '13:00:00'], [6, '09:00:00', '13:00:00']],
        ],
        [
            'first_name' => 'خالد', 'last_name' => 'الدوسري',
            'headline' => 'مدرّس لغة إنجليزية ومحادثة',
            'bio' => 'أركّز على الطلاقة في المحادثة والتحضير لاختبارات IELTS، بحصص عملية قائمة على الحوار لا على الحفظ.',
            'qualifications' => ['شهادة CELTA', 'بكالوريوس الأدب الإنجليزي'],
            'years' => 5, 'rate' => '150.00', 'verified' => false,
            // No score: this is what a newly approved teacher looks like (FR-024).
            'score' => null, 'sessions' => 4, 'reviews' => 1, 'rating' => null,
            'subjects' => ['english'], 'levels' => ['preparatory', 'secondary'],
            'photo' => 'khaled-aldosari.webp',
            'slug' => 'khaled-aldosari',
            'course' => ['title' => 'اللغة الإنجليزية للمحادثة — مستوى متوسط', 'price' => '480.00', 'price_before' => '600.00', 'type' => 'group', 'hours' => 16, 'cover' => 'english.webp'],
            'availability' => [[0, '18:00:00', '21:00:00'], [3, '18:00:00', '21:00:00']],
        ],
        [
            'first_name' => 'منى', 'last_name' => 'البلوشي',
            'headline' => 'مدرّسة حاسب آلي وبرمجة للمبتدئين',
            'bio' => 'أعلّم أساسيات البرمجة بلغة بايثون وتفكير الخوارزميات لطلاب الإعدادية والثانوية عبر مشاريع صغيرة.',
            'qualifications' => ['بكالوريوس علوم الحاسب', 'مدرّبة معتمدة في تعليم البرمجة للأطفال'],
            'years' => 6, 'rate' => '170.00', 'verified' => true,
            'score' => 68, 'sessions' => 95, 'reviews' => 9, 'rating' => 4.0,
            'subjects' => ['computer-science', 'math'], 'levels' => ['preparatory', 'secondary'],
            'photo' => 'mona-albalushi.webp',
            'slug' => 'mona-albalushi',
            'course' => ['title' => 'أساسيات البرمجة بلغة بايثون', 'price' => '890.00', 'price_before' => null, 'type' => 'recorded', 'hours' => 30, 'cover' => 'python.webp'],
            'availability' => [[1, '16:00:00', '20:00:00'], [4, '16:00:00', '20:00:00']],
        ],
    ];
}
