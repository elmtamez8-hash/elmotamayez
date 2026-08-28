<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExamResource\RelationManagers;

use App\Modules\Assessments\Enums\AttemptStatus;
use App\Modules\Assessments\Models\Attempt;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * كلُّ محاولةٍ على هذهِ الورقة — قراءةً فقط.
 *
 * ⚠️ الدرجةُ تُكتَبُ من `GradeAttempt` مقابلَ اللقطةِ المحفوظةِ في `attempt_items`،
 * لا من هنا. حقلُ درجةٍ قابلٌ للتحريرِ في هذهِ الشاشةِ يعني ورقةً صُحِّحَتْ مرّتَينِ
 * بإجابتَينِ مختلفتَين، بلا أثرٍ يقولُ أيُّهما الصحيح.
 */
class AttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'attempts';

    /*
    | ⚠️ `$title` يُسمّي التبويب، وما دونَه يقرأُ `$modelLabel` — وافتراضُه
    | **اسمُ العلاقةِ نفسُه**، فكانت حالةُ الفراغِ تقولُ «لا يوجد attempts» تحتَ
    | تبويبٍ عربيّ.
    */
    protected static ?string $modelLabel = 'محاولة';

    protected static ?string $pluralModelLabel = 'المحاولات';

    protected static ?string $title = 'المحاولات';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPencilSquare;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('student.email')->label('الطالب')->searchable()->copyable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => AttemptStatus::labelFor($state))
                    ->color(fn (string $state): string => match (AttemptStatus::tryFrom($state)) {
                        AttemptStatus::Graded => 'success',
                        AttemptStatus::InProgress => 'info',
                        AttemptStatus::Submitted,
                        AttemptStatus::PendingGrading,
                        AttemptStatus::Grading => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('score')
                    ->label('الدرجة')
                    ->placeholder('—')
                    ->formatStateUsing(fn (mixed $state, Attempt $record): string => $state === null
                        ? '—'
                        : (string) (int) $state.' / '.(string) (int) $record->max_score),
                IconColumn::make('passed')->label('ناجح')->boolean(),
                IconColumn::make('is_practice')->label('تدريب')->boolean()->toggleable(),
                TextColumn::make('started_at')->label('البداية')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('submitted_at')->label('التسليم')->dateTime('Y-m-d H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                /*
                | التدريبُ ليس نتيجة: `ExamController@index` يستثنيه عندَ عرضِ نتائجِ
                | الطالب، فخلطُ الاثنَينِ في عمودٍ واحدٍ هنا يجعلُ «نسبةَ النجاح»
                | التي يقرؤها المشرِّفُ رقماً لا يطابقُ ما يراه أحد.
                */
                TernaryFilter::make('is_practice')
                    ->label('تدريب')
                    ->placeholder('الكلّ')
                    ->trueLabel('تدريب فقط')
                    ->falseLabel('مُحتسَبة فقط'),
            ]);
    }
}
