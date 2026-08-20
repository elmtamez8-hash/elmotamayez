<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SC-008: 50,000 published teachers and 10,000 published courses, so the listing
 * queries can be measured against the volume the criterion names rather than
 * against six demo rows.
 *
 * Not part of DatabaseSeeder — run it deliberately:
 *
 *     php artisan db:seed --class=MarketplaceLoadSeeder
 *     php artisan marketplace:benchmark
 *
 * Rows are built with raw chunked inserts, not factories. 50k factory calls take
 * minutes and generate 50k model events for data nobody will read; the point
 * here is the shape and the volume, not realism.
 */
class MarketplaceLoadSeeder extends Seeder
{
    private const TEACHERS = 50_000;

    private const COURSES = 10_000;

    private const CHUNK = 1_000;

    /** @var list<string> */
    private const FIRST_NAMES = [
        'أحمد', 'سارة', 'محمد', 'نورة', 'عبدالله', 'مريم', 'خالد', 'لطيفة',
        'يوسف', 'هند', 'راشد', 'شيخة', 'فاطمة', 'علي', 'منى', 'بدر',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'المنصوري', 'الهاشمي', 'العتيبي', 'الدوسري', 'البلوشي', 'الكواري',
        'العطية', 'المري', 'الهاجري', 'النعيمي', 'السليطي',
    ];

    public function run(): void
    {
        /** @var list<int> $workspaces */
        $workspaces = Workspace::query()->limit(20)->pluck('id')->map(intval(...))->values()->all();

        if ($workspaces === []) {
            $this->command->error('No workspaces. Run migrate:fresh --seed first.');

            return;
        }

        // Spread across workspaces on purpose: the listing merges every
        // participating workspace, so a single-tenant dataset would measure a
        // query the marketplace never runs.
        Workspace::query()->whereIn('id', $workspaces)
            ->update(['participates_in_marketplace' => true]);

        app(WorkspaceContext::class)->forWorkspace(
            $workspaces[0],
            function () use ($workspaces): void {
                $this->seedTeachers($workspaces);
                $this->seedCourses($workspaces);
            },
        );

        // Without this the benchmark measures the wrong thing. A freshly bulk
        // loaded database has no planner statistics, so SQLite picks plans
        // heuristically and every listing came out 3–6× slower — an artefact of
        // the load, not a property of the queries. 114 ms here changed
        // "subject filter: 2148 ms FAIL" into "588 ms PASS".
        DB::statement(match (DB::getDriverName()) {
            'sqlite' => 'ANALYZE',
            default => 'ANALYZE TABLE teacher_profiles, users, courses, teacher_profile_subject, teacher_profile_grade_level',
        });

        MarketplaceCache::flush();

        $this->command->info(sprintf(
            'Load data seeded: %s teachers, %s courses across %d workspaces.',
            number_format(self::TEACHERS),
            number_format(self::COURSES),
            count($workspaces),
        ));
    }

    /** @param list<int> $workspaces */
    private function seedTeachers(array $workspaces): void
    {
        /*
        | Since spec 009 the taxonomy is platform-wide — one row per slug — so
        | these are flat lists and teachers are spread across them round-robin.
        | They used to be keyed by workspace_id, one row each, which meant every
        | teacher in a workspace shared one subject: a filtered query would then
        | either match everything or nothing, and the load measurement it exists
        | for would be meaningless.
        */
        $subjects = array_values(Subject::query()->orderBy('id')->pluck('id')->map(intval(...))->all());
        $levels = array_values(GradeLevel::query()->orderBy('id')->pluck('id')->map(intval(...))->all());

        $userId = (int) DB::table('users')->max('id');
        $now = now();

        for ($offset = 0; $offset < self::TEACHERS; $offset += self::CHUNK) {
            $users = [];
            $profiles = [];

            for ($i = 0; $i < self::CHUNK; $i++) {
                $n = $offset + $i;
                $workspaceId = $workspaces[$n % count($workspaces)];

                // Distinct surnames, not a shared prefix: a name search whose term
                // matches all 50,000 rows measures a full scan, not a search.
                $firstName = self::FIRST_NAMES[$n % count(self::FIRST_NAMES)];
                $lastName = self::LAST_NAMES[$n % count(self::LAST_NAMES)].'-'.$n;

                $users[] = [
                    'uuid' => (string) Str::uuid(),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => "load-teacher-{$n}@example.test",
                    'password' => '$2y$12$abcdefghijklmnopqrstuv',
                    'platform_role' => 'teacher',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Every tenth teacher has no score: "building" must be part of the
                // measured set, because it is the branch that sorts separately.
                $scored = $n % 10 !== 0;

                $profiles[] = [
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'user_id' => ++$userId,
                    // Set explicitly: these are raw inserts, so the model's saving
                    // hook never runs. Leaving it null would make the name-search
                    // benchmark scan 50,000 NULLs and pass for the wrong reason.
                    'search_name' => $firstName.' '.$lastName,
                    'headline' => 'مدرّس رياضيات وفيزياء',
                    'years_experience' => $n % 20 + 1,
                    'teaching_languages' => json_encode(['ar', 'en']),
                    'qualifications' => json_encode(['بكالوريوس تربية']),
                    'hourly_rate' => 50 + ($n % 250),
                    'currency' => 'QAR',
                    'is_verified' => $n % 4 === 0,
                    'approval_status' => TeacherProfile::STATUS_APPROVED,
                    'is_publicly_listed' => true,
                    'trust_score' => $scored ? 40 + ($n % 60) : null,
                    'average_rating' => $scored ? 3 + ($n % 20) / 10 : null,
                    'reviews_count' => $scored ? $n % 90 + 3 : 0,
                    'completed_sessions_count' => $scored ? $n % 400 + 10 : 2,
                    'cancelled_sessions_count' => $n % 5,
                    'students_taught_count' => $n % 200,
                    'response_rate' => 80 + ($n % 20),
                    'attendance_rate' => 80 + ($n % 20),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('users')->insert($users);
            DB::table('teacher_profiles')->insert($profiles);

            $this->attachTaxonomy($profiles, $subjects, $levels);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @param  list<int>  $subjects
     * @param  list<int>  $levels
     */
    private function attachTaxonomy(array $profiles, array $subjects, array $levels): void
    {
        $ids = DB::table('teacher_profiles')
            ->whereIn('uuid', array_column($profiles, 'uuid'))
            ->pluck('id', 'uuid');

        $subjectRows = [];
        $levelRows = [];

        foreach ($profiles as $n => $profile) {
            $id = $ids[$profile['uuid']] ?? null;

            if ($id === null) {
                continue;
            }

            // Filters run through these pivots, so an unpopulated pivot would make
            // every filtered query trivially fast and the measurement meaningless.
            if ($subjects !== []) {
                $subjectRows[] = ['teacher_profile_id' => $id, 'subject_id' => $subjects[$n % count($subjects)]];
            }

            if ($levels !== []) {
                $levelRows[] = ['teacher_profile_id' => $id, 'grade_level_id' => $levels[$n % count($levels)]];
            }
        }

        if ($subjectRows !== []) {
            DB::table('teacher_profile_subject')->insert($subjectRows);
        }

        if ($levelRows !== []) {
            DB::table('teacher_profile_grade_level')->insert($levelRows);
        }
    }

    /** @param list<int> $workspaces */
    private function seedCourses(array $workspaces): void
    {
        $authors = DB::table('teacher_profiles')
            ->whereIn('workspace_id', $workspaces)
            ->pluck('user_id', 'workspace_id')
            ->all();

        $now = now();

        for ($offset = 0; $offset < self::COURSES; $offset += self::CHUNK) {
            $rows = [];

            for ($i = 0; $i < self::CHUNK; $i++) {
                $n = $offset + $i;
                $workspaceId = $workspaces[$n % count($workspaces)];

                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'title' => "كورس تجريبي {$n}",
                    'slug' => "load-course-{$n}",
                    'description' => 'بيانات حِمل، ليست محتوى حقيقياً.',
                    'price_minor' => (100 + ($n % 400)) * 100,
                    'currency' => 'QAR',
                    'status' => 'published',
                    'visibility' => 'public',
                    'is_sequential' => true,
                    'language' => 'ar',
                    'duration_seconds' => 3600 * ($n % 40 + 1),
                    'course_type' => [Course::TYPE_RECORDED, Course::TYPE_GROUP, Course::TYPE_INDIVIDUAL][$n % 3],
                    'created_by' => $authors[$workspaceId] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('courses')->insert($rows);
        }
    }
}
