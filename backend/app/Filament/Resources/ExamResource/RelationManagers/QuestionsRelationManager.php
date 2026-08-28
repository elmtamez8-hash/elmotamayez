<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamResource\RelationManagers;

use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Enums\QuestionType;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * أسئلةُ الورقةِ بترتيبِها — قراءةً فقط.
 *
 * ⚠️ السؤالُ مملوكٌ للبنكِ لا للورقة (سبيك ٠٠٨)، والإضافةُ من هنا تكتبُ على
 * `exam_items` مباشرةً فتتجاوزُ `guardExactlyOneCorrect()`: خيارٌ صحيحٌ ثانٍ يعني
 * سؤالاً لا تُرضيه نقرةٌ واحدةٌ أبداً — كلُّ طالبٍ مخطئٌ فيه إلى الأبد.
 *
 * والتفسيرُ (`explanation`) غائبٌ عن الأعمدةِ عمداً: هو مفتاحُ الإجابةِ نثراً.
 */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    /*
    | ⚠️ `$title` يُسمّي التبويب، وما دونَه يقرأُ `$modelLabel` — وافتراضُه
    | **اسمُ العلاقةِ نفسُه**، فكانت حالةُ الفراغِ تقولُ «لا يوجد questions» تحتَ
    | تبويبٍ عربيّ.
    */
    protected static ?string $modelLabel = 'سؤال';

    protected static ?string $pluralModelLabel = 'الأسئلة';

    protected static ?string $title = 'الأسئلة';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedQuestionMarkCircle;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('content')
                    ->label('نصّ السؤال')
                    ->wrap()
                    ->limit(140)
                    ->searchable(),
                TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn (mixed $state): string => QuestionType::labelFor(is_scalar($state) ? (string) $state : null)),
                TextColumn::make('difficulty')->label('الصعوبة')->badge()
                    ->formatStateUsing(fn (mixed $state): string => Difficulty::labelFor(
                        $state instanceof Difficulty ? $state->value : (is_scalar($state) ? (string) $state : null),
                    ))
                    ->color(fn (mixed $state): string => match ((string) ($state instanceof Difficulty ? $state->value : $state)) {
                        'easy' => 'success',
                        'hard' => 'danger',
                        default => 'warning',
                    })
                    ->toggleable(),
                TextColumn::make('points')->label('الدرجة'),
                IconColumn::make('is_active')->label('مفعَّل')->boolean(),
            ]);
    }
}
