<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\NavigationGroups;
use App\Filament\Resources\EnrollmentResource\Pages;
use App\Filament\Support\RecordLink;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ChangeEnrollmentStatus;
use App\Shared\Scopes\WorkspaceScope;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroups::CONTENT;

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return 'التسجيلات';
    }

    public static function getModelLabel(): string
    {
        return 'تسجيل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'التسجيلات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التسجيل')
                    ->description('الطالبُ والكورسُ ثابتان — التسجيلُ يُنشَأُ من اعتمادِ الطلبِ لا من هنا.')
                    ->columns(2)
                    ->schema([
                        Select::make('course_id')
                            ->label('الكورس')
                            ->relationship('course', 'title')
                            ->disabled(),
                        Select::make('student_user_id')
                            ->label('الطالب')
                            ->relationship('student', 'email')
                            ->disabled(),
                        // ⚠️ «ملغى» لا يُختارُ من هنا. الإلغاءُ يمرُّ بـ«عكس الدفعة» على
                        // الطلب (`ReverseCourseOrder`)، وهو الذي يُطلِقُ
                        // `CourseAccessWithdrawn` فتُحرَّرُ المقاعدُ ويخرجُ الطالبُ من
                        // مجموعته — تعديلُ العمودِ وحدَه يتركُ الاثنين معلّقَين. وصفٌّ
                        // مُلغى أصلاً يُعرَضُ بحالتِه ويُقفَلُ الحقل: إعادةُ فتحِه شراءٌ
                        // جديد (`EnrollStudent::handOver()`)، لا تعديلُ حالة. والقائمةُ
                        // المُرشَّحةُ تُشكِّلُ طلباً واحداً لا الذي يليه، فالحارسُ الثاني
                        // في `EditEnrollment::mutateFormDataBeforeSave()`.
                        Select::make('status')
                            ->label('الحالة')
                            ->options(fn (?Enrollment $record): array => self::statusOptionsFor($record))
                            ->disabled(fn (?Enrollment $record): bool => self::isCancelled($record))
                            ->helperText(fn (?Enrollment $record): ?string => match (true) {
                                self::isCancelled($record) => 'أُلغِيَ هذا التسجيلُ بعكسِ دفعته، ولا يُعادُ فتحُه من هنا.',
                                self::isLapsedSubscription($record) => 'انتهى وصولُ هذا الاشتراك، ولا يُعادُ فتحُه من هنا — يعودُ بتجديد الاشتراك.',
                                $record?->source === 'subscription' => 'اختيارُ «منتهٍ» يُغلقُ هذا الكورسَ وحدَه ويُحرِّرُ مقاعدَ الطالبِ في حصصِه القادمة.',
                                $record === null => null,
                                default => 'اختيارُ «منتهٍ» يُغلقُ هذا الكورسَ ويُحرِّرُ مقاعدَ الطالبِ في حصصِه القادمة.',
                            })
                            ->required(),
                    ]),
            ]);
    }

    /**
     * كلُّ الحالاتِ عدا «ملغى» — إلّا لصفٍّ مُلغى أصلاً، فيُعرَضُ بحالتِه بدلَ
     * أن يظهرَ الحقلُ فارغاً.
     *
     * @return array<string, string>
     */
    public static function statusOptionsFor(?Enrollment $record): array
    {
        $options = EnrollmentStatus::options();

        if (! self::isCancelled($record)) {
            unset($options[EnrollmentStatus::Cancelled->value]);
        }

        if (self::isLapsedSubscription($record)) {
            foreach (Enrollment::GRANTING_STATUSES as $granting) {
                unset($options[$granting]);
            }
        }

        return $options;
    }

    /**
     * ⚠️ A SUBSCRIPTION ENROLMENT THAT NO LONGER GRANTS IS NOT REOPENED BY HAND.
     * Its access was bought for a period; `expired → active` (or `→ completed`,
     * which grants just the same) from here is that access handed back with no
     * payment behind it and no subscription covering it — nothing would ever
     * close it again, because the nightly sweep closes subscriptions, not rows.
     * The way back is a renewal, which reopens the row through `EnrollStudent`.
     */
    public static function isLapsedSubscription(?Enrollment $record): bool
    {
        // One predicate for the options offered here and the refusal in the Action.
        return ChangeEnrollmentStatus::isLapsedSubscription($record);
    }

    public static function isCancelled(?Enrollment $record): bool
    {
        return $record?->status === EnrollmentStatus::Cancelled->value;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('enrolled_at', 'desc')
            ->columns([
                TextColumn::make('course.title')
                    ->label('الكورس')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Enrollment $record): ?string => RecordLink::to(CourseResource::class, $record->course))
                    ->wrap(),
                TextColumn::make('course.workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->url(fn (Enrollment $record): ?string => RecordLink::to(WorkspaceResource::class, $record->course->workspace))
                    ->toggleable(),
                // ⚠️ بلا بحثٍ ولا ترتيب: `name` سِمةٌ محسوبةٌ لا عمود. {@see CourseResource}
                TextColumn::make('student.name')
                    ->label('اسم الطالب')
                    ->placeholder('—'),
                TextColumn::make('student.email')
                    ->label('بريد الطالب')
                    ->searchable()
                    ->url(fn (Enrollment $record): ?string => RecordLink::to(UserResource::class, $record->student)),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => EnrollmentStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'completed' => 'info',
                        'expired' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('progress_pct')
                    ->label('التقدّم')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => (string) ((int) $state).'٪')
                    ->color(fn (mixed $state): string => match (true) {
                        ((int) $state) >= 100 => 'success',
                        ((int) $state) > 0 => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('enrolled_at')
                    ->label('تاريخ التسجيل')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(EnrollmentStatus::options()),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    /**
     * ⚠️ **مديرُ المنصّةِ وحدَه — ولا ثابتَ في `Permissions` يقولُ ذلك.**
     *
     * كانَ البابُ يسقطُ إلى `EnrollmentPolicy::viewAny()` أي
     * `enrollments.view.all`، وهي صلاحيّةُ **مساحة**: مسؤولُ الماليّةِ الذي يملكُ
     * مساحةً يحملُها هناك بصفِّ `tenant-owner`، والقائمةُ أدناه بلا نطاق — فكانَ
     * يقرأُ تسجيلاتِ كلِّ مدرّسٍ على المنصّة. ولا دورَ منصّيٌّ يحملُ صلاحيّةَ
     * تسجيلٍ في المصفوفة، فالقارئُ الوحيدُ لهذه الشاشةِ هو مديرُ المنصّة.
     *
     * والتعديلُ كذلك: `EnrollmentPolicy` بلا `update()`، وسياسةٌ بلا الدالّةِ في
     * Filament v5 **سماحٌ**، فكانَ التعديلُ مفتوحاً لكلِّ من يرى الشاشة.
     */
    public static function canViewAny(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * ⚠️ **بلا نطاق، لأنّ قارئَها مديرُ المنصّة** ({@see canViewAny()}). سياقُه
     * يرجعُ إلى `users.last_workspace_id` كغيرِه، فبلا التجاوزِ يرى قائمةً قصيرةً
     * تُقرَأُ أسبوعاً هادئاً، واسمَ مساحةٍ فارغاً لكلِّ صفٍّ غريب — الطبقةُ
     * الخامسةُ من طبقاتِ ٠٢٤، الوحيدةُ الصامتةُ تماماً.
     *
     * ⚠️ **والتجاوزُ يُكرَّرُ داخلَ الضمِّ المُسبَق**: إسقاطُه عن الجذرِ يُحرِّرُ
     * القراءةَ الخارجيّةَ وحدَها، و`->with('course')` يعملُ باستعلامٍ ثانٍ يسري
     * عليه نطاقُ `Course` من جديد. `student` لا يحتاجُ تجاوزاً — `users` مملوكٌ
     * للمنصّةِ ولا نطاقَ عليه.
     *
     * `withoutGlobalScope(WorkspaceScope::class)` وليسَ مساعدَ النموذجِ
     * `withoutWorkspaceScope()`: أبُ Filament يردُّ `Builder<Model>`، والنطاقُ
     * المحلّيُّ للنموذجِ غيرُ مُنمَّطٍ عليه. والنداءانِ واحد.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withoutGlobalScope(WorkspaceScope::class);

        return $query->with([
            'course' => fn ($relation) => $relation->withoutGlobalScope(WorkspaceScope::class),
            'course.workspace',
            'student',
        ]);
    }

    /**
     * البحثُ العامّ ببريدِ الطالبِ أو بعنوانِ الكورس.
     *
     * ⚠️ `course.title` بحثٌ بـ`whereHas('course')`، ونطاقُ `Course` كانَ سيعودُ
     * عليه فلا يجدُ إلّا كورساتِ مكانِ القارئ. لا يعود: `Enrollment::course()`
     * تُسقِطُ النطاقَ على العلاقةِ نفسِها، و`whereHas` يحملُ النطاقاتِ المُسقَطةَ
     * من العلاقة (`mergeConstraintsFrom`) — والاختبارُ يقيسُه بمكانَين.
     * والاستعلامُ هو {@see self::getEloquentQuery()} بتجاوزِه وتحميلِه المسبق.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['student.email', 'course.title'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Enrollment $record */
        return $record->course->title.' — '.$record->student->email;
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Enrollment $record */
        return array_filter([
            'المدرّس' => $record->course->workspace?->name,
            'الحالة' => EnrollmentStatus::labelFor($record->status),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollments::route('/'),
            'edit' => Pages\EditEnrollment::route('/{record}/edit'),
        ];
    }
}
