<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Filament\Widgets;

use App\Models\User;
use App\Modules\Analytics\Filament\Widgets\Concerns\PlatformWideWidget;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * ترتيبُ الطلاب — الأعلى تحصيلاً والأدنى، بمرشِّحاتِ المادّةِ والكورسِ والمدرّسِ
 * والبلد.
 *
 * ⛔ **«التقييم» هنا معرَّفٌ لا مخترَع**: متوسّطُ درجاتِ الاختباراتِ المصحَّحةِ
 * غيرِ التدريبيّة، ورأسُ العمودِ يقولُ ذلك بلفظِه. رقمٌ مركَّبٌ من عدّةِ إشاراتٍ
 * لا يحملُه جدولٌ هو رقمٌ لا يستطيعُ أحدٌ تدقيقَه — ولا الردَّ عليه حينَ يعترضُ
 * وليُّ أمر.
 *
 * ⚠️ **واستعلاماتٌ فرعيّةٌ لا `GROUP BY`.** مُرقِّمُ صفحاتِ Filament ينادي
 * `count()` على الاستعلامِ نفسِه، ومع تجميعٍ يعودُ عددَ صفوفِ المجموعاتِ لا
 * عددَها — فيُعلِنُ الجدولُ عدداً خاطئاً ويُرقِّمُ صفحاتٍ لا وجودَ لها.
 *
 * ⚠️ **و`is_practice = false`**: التدريبُ يُصحَّحُ فوراً ويُعرَضُ شرحُه، فإدخالُه
 * في المتوسّطِ يقيسُ من تمرَّنَ أكثرَ لا من تعلَّمَ أكثر.
 *
 * ⚠️ **و`NULLIF(max_score, 0)`**: لقطةُ الأسئلةِ تُكتَبُ عندَ البدء، وامتحانٌ
 * بلا عناصرَ يتركُ صفراً في المقام — والقسمةُ عليه تُسقِطُ الاستعلامَ على MySQL
 * بدلَ أن تتخطّى صفّاً واحداً.
 *
 * ⚠️ **والبلدُ حقلٌ حرٌّ فارغٌ في أغلبِ الحسابات** (‏١٧ من ١٩ على الإنتاج
 * 2026-09-04)، فله خانةُ «غير محدَّد» ولا يُحذَفُ حاملوه: جدولٌ يُسقِطُ من لم
 * يُسألْ عن بلدِه يعرضُ منصّةً بطالبَين.
 */
class StudentPerformanceWidget extends BaseWidget
{
    use PlatformWideWidget;

    protected static ?string $heading = 'ترتيب الطلاب';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->students())
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('name')->label('الطالب')->searchable(['first_name', 'last_name']),
                TextColumn::make('country')->label('البلد')->placeholder('غير محدَّد')->toggleable(),
                TextColumn::make('exam_average')
                    ->label('متوسّط الاختبارات المصحَّحة')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : number_format((float) $state, 1).'٪')
                    ->color(fn (mixed $state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state >= 75 => 'success',
                        (float) $state >= 50 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('graded_attempts')->label('أوراق مصحَّحة')->sortable(),
                TextColumn::make('progress')
                    ->label('التقدّم')
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : number_format((float) $state, 0).'٪')
                    ->sortable(),
                TextColumn::make('absences')
                    ->label('الغياب')
                    ->color(fn (mixed $state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('rank')
                    ->label('الترتيب')
                    ->options(['top' => 'الأعلى تحصيلاً', 'bottom' => 'الأدنى تحصيلاً'])
                    ->default('top')
                    ->selectablePlaceholder(false)
                    /*
                    | ⚠️ الأدنى يشترطُ ورقةً مصحَّحةً واحدةً على الأقلّ. بدونِها
                    | يتصدَّرُ «المتأخّرين» كلُّ من لم يجلسْ لامتحانٍ قطّ — طالبٌ
                    | سجَّلَ أمسِ يُقدَّمُ على من رسبَ فعلاً، وهو عكسُ ما تُبحَثُ
                    | عنه هذه القائمة.
                    */
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'top') {
                        'bottom' => $query->whereNotNull('exam_average')->orderBy('exam_average'),
                        default => $query->orderByDesc('exam_average'),
                    }),
                SelectFilter::make('subject')
                    ->label('المادّة')
                    ->options(fn (): array => Subject::query()->where('is_active', true)->pluck('name_ar', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->enrolledIn($query, 'subject_id', $data['value'] ?? null)),
                SelectFilter::make('course')
                    ->label('الكورس')
                    ->options(fn (): array => Course::query()->withoutWorkspaceScope()->pluck('title', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->enrolledIn($query, 'id', $data['value'] ?? null)),
                SelectFilter::make('teacher')
                    ->label('المدرّس')
                    ->options(fn (): array => TeacherProfile::query()->withoutWorkspaceScope()
                        ->with('user:id,first_name,last_name')->get()
                        ->mapWithKeys(fn (TeacherProfile $profile): array => [
                            $profile->getKey() => trim((string) $profile->user?->name),
                        ])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->enrolledIn($query, 'teacher_profile_id', $data['value'] ?? null)),
                SelectFilter::make('country')
                    ->label('البلد')
                    ->options(fn (): array => User::query()->whereNotNull('country')
                        ->distinct()->orderBy('country')->pluck('country', 'country')->all()),
            ]);
    }

    /** @return Builder<User> */
    private function students(): Builder
    {
        $graded = Attempt::query()->withoutWorkspaceScope()
            ->whereColumn('exam_attempts.student_user_id', 'users.id')
            ->where('is_practice', false)
            ->whereNotNull('score');

        return User::query()
            ->where('platform_role', 'student')
            ->addSelect(['exam_average' => (clone $graded)
                ->selectRaw('AVG(score / NULLIF(max_score, 0) * 100)')->limit(1)])
            ->addSelect(['graded_attempts' => (clone $graded)->selectRaw('COUNT(*)')->limit(1)])
            ->addSelect(['progress' => Enrollment::query()->withoutWorkspaceScope()
                ->whereColumn('enrollments.student_user_id', 'users.id')
                ->selectRaw('AVG(progress_pct)')->limit(1)])
            ->addSelect(['absences' => Attendance::query()->withoutWorkspaceScope()
                ->whereColumn('attendances.student_user_id', 'users.id')
                ->where('status', 'absent')
                ->selectRaw('COUNT(*)')->limit(1)]);
    }

    /**
     * «مسجَّلٌ في كورسٍ يحملُ هذه القيمة» — بلا ضمِّ صفوفٍ إلى الجدول.
     *
     * ⚠️ `whereExists` لا `join`: الضمُّ يُكرِّرُ الطالبَ مرّةً لكلِّ تسجيلٍ له،
     * فيظهرُ في القائمةِ مرّتَينِ بالرقمِ نفسِه ويُفسِدُ عدَّ الصفحات.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function enrolledIn(Builder $query, string $column, mixed $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        return $query->whereExists(function (QueryBuilder $sub) use ($column, $value): void {
            $sub->from('enrollments')
                ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                ->whereColumn('enrollments.student_user_id', 'users.id')
                ->where('courses.'.$column, $value);
        });
    }
}
