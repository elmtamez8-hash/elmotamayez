<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollmentResource\Pages;
use App\Modules\Learning\Models\Enrollment;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'completed' => 'Completed',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                    ])
                    ->required(),
                Select::make('course_id')
                    ->relationship('course', 'title')
                    ->disabled(),
                Select::make('student_user_id')
                    ->relationship('student', 'email')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.title')->searchable()->sortable(),
                TextColumn::make('student.email')->searchable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'completed' => 'info',
                        'expired' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('progress_pct')->suffix('%')->sortable(),
                TextColumn::make('enrolled_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'completed' => 'Completed',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->actions([
                EditAction::make(),
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
