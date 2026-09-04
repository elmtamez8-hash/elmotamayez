<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * المدرّسونَ بعددِ طلابِهم — للمنصّةِ كلِّها أو داخلَ مادّةٍ أو مرحلة.
 *
 * ⛔ **العدَدُ يتبعُ المرشِّح، ولا يبقى ثابتاً بجانبِه.** مدرّسٌ يُدرِّسُ ثلاثَ
 * موادَّ لو عُرِضَ إجماليُّ طلابِه تحتَ مرشِّحِ «الفيزياء» لَتصدَّرَ قائمةَ
 * الفيزياءِ بطلابِ الرياضيّات — رقمٌ صحيحٌ في خانةٍ خاطئة، وهو أسوأُ من رقمٍ
 * غائب. فالمرشِّحُ يدخلُ الاستعلامَ الفرعيَّ نفسَه.
 *
 * ⚠️ **وطلابٌ متمايزون لا تسجيلات**: طالبٌ في كورسَينِ لمدرّسٍ واحدٍ شخصٌ واحد،
 * وعدُّ التسجيلاتِ يُضاعِفُ من درسَ أكثرَ ويجعلُ الترتيبَ ترتيبَ كثرةِ الكورساتِ
 * لا كثرةِ الطلاب.
 *
 * ⚠️ **و`students_taught_count` على الملفِّ لا يُقرَأُ هنا**: تلك سِمةٌ يُزامِنُها
 * `SyncTeacherCountersJob` بتعريفِها الخاصّ، وقراءتُها تحتَ مرشِّحٍ تُعطي رقماً
 * لا يستجيبُ له — هجاءانِ لسؤالٍ واحد، وهو العطلُ الذي سجّلَه هذا المستودعُ
 * مرّاتٍ.
 *
 * ⚠️ **و`courses.grade_level` نصٌّ لا مفتاح**: العمودُ يحملُ الـslug، فالمرشِّحُ
 * يُقارِنُ بالـslug ولا بمعرِّفِ صفٍّ في `grade_levels`.
 */
class TopTeachersWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected static ?string $heading = 'المدرّسون بعدد الطلاب';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->teachers())
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->defaultSort('students', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('المدرّس')->placeholder('—')->searchable(['search_name']),
                TextColumn::make('students')->label('الطلاب')->badge()->color('primary')->sortable(),
                TextColumn::make('courses_count')->label('الكورسات')->sortable(),
                TextColumn::make('average_rating')
                    ->label('التقييم')
                    ->formatStateUsing(fn (mixed $state, TeacherProfile $record): string => $record->reviews_count === 0 || $state === null
                        ? '—'
                        : number_format((float) $state, 2).' / 5')
                    ->description(fn (TeacherProfile $record): string => $record->reviews_count === 0
                        ? 'لا تقييمات بعد'
                        : $record->reviews_count.' تقييماً')
                    ->sortable(),
                TextColumn::make('completed_sessions_count')->label('حصص مُسلَّمة')->sortable()->toggleable(),
                TextColumn::make('approval_status')
                    ->label('الاعتماد')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => match ((string) $state) {
                        TeacherProfile::STATUS_APPROVED => 'معتمَد',
                        'suspended' => 'موقوف',
                        default => 'قيد المراجعة',
                    })
                    ->color(fn (mixed $state): string => match ((string) $state) {
                        TeacherProfile::STATUS_APPROVED => 'success',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                /*
                | ⛔ كلُّ مرشِّحٍ يحملُ `query()` خاصّتَه. بدونِها يرتدُّ Filament
                | إلى `where('<اسم المرشّح>', $value)` على النموذجِ الجذر — أي
                | `teacher_profiles.subject`، وهو عمودٌ لا وجودَ له: الصفحةُ
                | تسقطُ بخطأِ SQL عندَ أوّلِ اختيار. قِيسَ قبلَ الشحن.
                */
                SelectFilter::make('subject')
                    ->label('المادّة')
                    ->options(fn (): array => Subject::query()->where('is_active', true)->pluck('name_ar', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->teachingWhere(
                        $query,
                        'teacher_profile_subject.subject_id',
                        $data['value'] ?? null,
                    )),
                SelectFilter::make('grade_level')
                    ->label('المرحلة')
                    ->options(fn (): array => GradeLevel::query()->pluck('name_ar', 'slug')->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->teachesStage($query, $data['value'] ?? null)),
            ]);
    }

    /** @return Builder<TeacherProfile> */
    private function teachers(): Builder
    {
        $subject = $this->filterValue('subject');
        $grade = $this->filterValue('grade_level');

        $narrow = function (QueryBuilder $sub) use ($subject, $grade): void {
            if ($subject !== null) {
                $sub->where('courses.subject_id', $subject);
            }

            if ($grade !== null) {
                $sub->where('courses.grade_level', $grade);
            }
        };

        return TeacherProfile::query()
            ->withoutWorkspaceScope()
            // ⚠️ الأعمدةُ التي يقرؤها المُسنِدُ، لا السِمةُ التي تُطبَع: `users`
            // لا عمودَ `name` فيها، و`user:id,uuid,name` يُصيّرُ اسماً فارغاً
            // برمزِ ٢٠٠ — شُحِنَ هذا الهجاءُ ستَّ مرّاتٍ في أربعِ وحدات.
            ->with('user:id,uuid,first_name,last_name')
            ->addSelect(['students' => Enrollment::query()->withoutWorkspaceScope()
                ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                ->whereColumn('courses.teacher_profile_id', 'teacher_profiles.id')
                ->where($narrow)
                ->selectRaw('COUNT(DISTINCT enrollments.student_user_id)')->limit(1)])
            ->addSelect(['courses_count' => Course::query()->withoutWorkspaceScope()
                ->whereColumn('courses.teacher_profile_id', 'teacher_profiles.id')
                ->where($narrow)
                ->selectRaw('COUNT(*)')->limit(1)]);
    }

    /**
     * «يُدرِّسُ هذه المادّة» — من جدولِ الإسنادِ لا من كورساتِه.
     *
     * ⚠️ الإسنادُ هو ما يُعلِنُه المدرّسُ عن نفسِه، والكورساتُ ما أنشأَه فعلاً.
     * مدرّسُ فيزياءٍ لم يُنشئْ كورساً بعدُ يبقى في القائمةِ بصفرٍ صادق، وحذفُه
     * يجعلُ «مدرّسو الفيزياء» تعني «من نشرَ كورسَ فيزياء».
     *
     * @param  Builder<TeacherProfile>  $query
     * @return Builder<TeacherProfile>
     */
    private function teachingWhere(Builder $query, string $column, mixed $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        return $query->whereExists(fn (QueryBuilder $sub) => $sub->from('teacher_profile_subject')
            ->whereColumn('teacher_profile_subject.teacher_profile_id', 'teacher_profiles.id')
            ->where($column, $value));
    }

    /**
     * «له كورسٌ في هذه المرحلة».
     *
     * ⚠️ من الكورساتِ لا من `teacher_profile_grade_level`: المرحلةُ التي يُعلِنُها
     * المدرّسُ سلسلةُ إسنادٍ ثانيةٌ بمعرِّفاتِ صفوف، بينما العدَدُ المعروضُ بجانبَه
     * محسوبٌ من `courses.grade_level` النصّيّ — فمرشِّحٌ يقرأُ الأوّلَ وعدَدٌ يقرأُ
     * الثاني هجاءانِ لسؤالٍ واحدٍ يعرضانِ مدرّساً بصفرٍ لا يُفسِّرُه شيء.
     *
     * @param  Builder<TeacherProfile>  $query
     * @return Builder<TeacherProfile>
     */
    private function teachesStage(Builder $query, mixed $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        return $query->whereExists(fn (QueryBuilder $sub) => $sub->from('courses')
            ->whereColumn('courses.teacher_profile_id', 'teacher_profiles.id')
            ->where('courses.grade_level', $value));
    }

    /**
     * قيمةُ مرشِّحٍ من حالةِ الجدول — أو `null` حينَ لا اختيار.
     *
     * ⚠️ تُقرَأُ داخلَ إغلاقِ `query()` لا في `table()`: الأوّلُ يُقيَّمُ عندَ كلِّ
     * تصيير، والثاني مرّةً — فقراءةٌ هناك تُجمِّدُ العدَدَ على أوّلِ حالةٍ رآها
     * الجدولُ ولا يستجيبُ للمرشِّحِ بعدَها أبداً.
     */
    private function filterValue(string $name): ?string
    {
        $value = $this->getTableFilterState($name)['value'] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
