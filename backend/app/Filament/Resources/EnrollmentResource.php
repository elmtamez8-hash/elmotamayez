<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollmentResource\Pages;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
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
use UnitEnum;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

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
                    ->description('الطالبُ والمقرّرُ ثابتان — التسجيلُ يُنشَأُ من اعتمادِ الطلبِ لا من هنا.')
                    ->columns(2)
                    ->schema([
                        Select::make('course_id')
                            ->label('المقرّر')
                            ->relationship('course', 'title')
                            ->disabled(),
                        Select::make('student_user_id')
                            ->label('الطالب')
                            ->relationship('student', 'email')
                            ->disabled(),
                        Select::make('status')
                            ->label('الحالة')
                            ->options(EnrollmentStatus::options())
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('enrolled_at', 'desc')
            ->columns([
                TextColumn::make('course.title')
                    ->label('المقرّر')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('course.workspace.name')
                    ->label('المدرّس')
                    ->placeholder('—')
                    ->toggleable(),
                // ⚠️ بلا بحثٍ ولا ترتيب: `name` سِمةٌ محسوبةٌ لا عمود. {@see CourseResource}
                TextColumn::make('student.name')
                    ->label('اسم الطالب')
                    ->placeholder('—'),
                TextColumn::make('student.email')
                    ->label('بريد الطالب')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('نُسخ البريد'),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['course.workspace', 'student']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrollments::route('/'),
            'edit' => Pages\EditEnrollment::route('/{record}/edit'),
        ];
    }
}
