<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CourseResource\Pages;
use App\Modules\Courses\Models\Course;
use App\Shared\Support\WorkspaceContext;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->maxLength(255),
                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ])
                    ->required(),
                Select::make('visibility')
                    ->options([
                        'private' => 'Private',
                        'public' => 'Public',
                    ])
                    ->required(),
                TextInput::make('price')
                    ->numeric()
                    ->prefix('$')
                    ->default(0),
                TextInput::make('currency')
                    ->maxLength(3)
                    ->default('USD'),
                Select::make('created_by')
                    ->options(function (): array {
                        $workspace = app(WorkspaceContext::class)->current();

                        if ($workspace === null) {
                            return [];
                        }

                        return $workspace->members()->pluck('users.email', 'users.id')->toArray();
                    })
                    ->searchable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('price')->money(fn (Course $record): string => $record->currency),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ]),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
