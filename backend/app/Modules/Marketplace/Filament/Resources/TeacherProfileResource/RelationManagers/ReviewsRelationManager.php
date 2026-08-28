<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\RelationManagers;

use App\Modules\Marketplace\Actions\ModerateReview;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use App\Modules\Marketplace\Models\Review;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Support\DisplayName;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * تقييماتُ هذا المدرّس — للقراءةِ فقط.
 *
 * ⚠️ لا إخفاءَ ولا حذفَ ولا تعديلَ من هنا. إخفاءُ تقييمٍ يمرُّ بـ
 * {@see ModerateReview}: هي التي تُطلِقُ `ReviewModerated`، والحدثُ هو ما
 * يُعيدُ حسابَ `average_rating` ودرجةِ الثقةِ خلفَه. كتابةُ `is_visible`
 * مباشرةً من جدولٍ هنا تُخفي التقييمَ عن الزائرِ ويبقى محسوباً في متوسّطِ
 * المدرّسِ وفي درجةِ ثقتِه — إلى الأبد، بلا خطأٍ في أيِّ مكان.
 *
 * ⚠️ والاسمُ مختصَر: {@see DisplayName::forStudent()} هو ما يقفُ بينَ المدرّسِ
 * ومعرفةِ مَن كتبَ أيَّ تقييم (FR-021).
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = 'التقييمات';

    /**
     * البابُ مكتوبٌ صراحةً، ولا يُترَكُ لـ`ReviewPolicy`.
     *
     * ⚠️ تلك السياسةُ لا تحملُ `viewAny()` إطلاقاً — فيها `create()` و
     * `moderate()` وحدَهما — والتبويبُ يسألُها افتراضيّاً. فالنتيجةُ أنّ من يحملُ
     * `marketplace.teachers.review` ويقفُ على صفحةِ المدرّسِ لا يرى تبويبَ
     * التقييماتِ أصلاً، بينما المشرِفُ العامُّ يراه — لأنّ `Gate::before` يمرُّ
     * فوقَ كلِّ سياسةٍ ولا يمرُّ فوقَه أحدٌ غيرُه. بابٌ يتغيّرُ بحسبِ مَن يقفُ
     * عليه دونَ أن يقولَ ذلك أحدٌ هو بابٌ بلا حارس.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeacherProfileResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            /*
            | `Review` تحملُ `workspace_id`، فنطاقُ مساحةِ العملِ يُطبَّقُ داخلَ
            | استعلامِ العلاقةِ حتّى وقد تخطّاه الاستعلامُ الأب: التخطّي لكلِّ
            | نموذجٍ على حدة. وبدونِه يفتحُ المشرِفُ ملفَّ مدرّسٍ من مساحةٍ أخرى
            | فيقرأُ «لا تقييمات» فوقَ عشرات.
            |
            | و`with('student')` لأنّ الاسمَ المختصَرَ يُشتَقُّ لكلِّ صفّ: بدونَه
            | استعلامُ مستخدِمٍ لكلِّ سطر.
            */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->with('student'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student_display')
                    ->label('الطالب')
                    ->state(fn (Review $record): string => DisplayName::forStudent($record->student)),

                TextColumn::make('rating')
                    ->label('التقييم')
                    ->badge()
                    ->color(fn (int $state): string => $state >= 4 ? 'success' : ($state >= 3 ? 'warning' : 'danger'))
                    ->sortable(),

                /*
                | ⚠️ المحاورُ الثلاثةُ فارغةٌ في كلِّ تقييمٍ كُتِبَ قبلَ 010، والفراغُ
                | ليس صفراً: «—» تقولُ «لم يُسأل»، والصفرُ يقولُ «أسوأُ درجة».
                */
                TextColumn::make('punctuality')->label('الالتزام')->placeholder('—')->toggleable(),
                TextColumn::make('clarity')->label('الوضوح')->placeholder('—')->toggleable(),
                TextColumn::make('engagement')->label('التفاعل')->placeholder('—')->toggleable(),

                TextColumn::make('comment')
                    ->label('التعليق')
                    ->placeholder('—')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state),

                IconColumn::make('is_visible')->label('ظاهر')->boolean(),

                TextColumn::make('period_start')->label('الفترة')->date('Y-m-d')->sortable()->toggleable(),

                TextColumn::make('created_at')->label('كُتب')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_visible')->label('ظاهر'),
            ]);
    }
}
