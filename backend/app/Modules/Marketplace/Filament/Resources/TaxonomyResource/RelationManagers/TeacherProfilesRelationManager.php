<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TaxonomyResource\RelationManagers;

use App\Modules\Marketplace\Filament\Resources\TaxonomyResource;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use App\Modules\Marketplace\Policies\TaxonomyPolicy;
use App\Shared\Scopes\WorkspaceScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * مَن يدرّسُ هذه المادّةَ أو هذه المرحلة — للقراءةِ فقط.
 *
 * ملفٌّ واحدٌ يخدمُ الشاشتَين لأنّ العلاقةَ اسمُها واحدٌ على النموذجَين، تماماً
 * كما يخدمُ {@see TaxonomyResource}
 * نموذجَين بنموذجٍ واحد.
 *
 * ⚠️ لا ربطَ ولا فكَّ ربطٍ من هنا. الجدولُ الوسيطُ يُكتَبُ مباشرةً بلا مرورٍ
 * بالنموذج، فلا شيءَ يُبطِلُ ذاكرةَ السوقِ العامّ: عدّادُ «كم مدرّساً في هذه
 * المادّة» مخزَّنٌ في `MarketplaceCache` ولا يُحدَّثُ إلّا من Action تلمسُه —
 * فيبقى الزائرُ يقرأُ الرقمَ القديمَ ويبقى المدرّسُ مصنَّفاً في مادّةٍ لم يخترها،
 * دونَ أن يُخطِرَه أحد.
 */
class TeacherProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'teacherProfiles';

    protected static ?string $title = 'المدرّسون';

    public function table(Table $table): Table
    {
        return $table
            /*
            | المادّةُ منصّيّةٌ منذ 009 بينما ملفُّ المدرّسِ يحملُ `workspace_id`،
            | و`WorkspaceContext::id()` يرتدُّ إلى `last_workspace_id` حتّى للمشرِفِ
            | العامّ. بلا التخطّي تعرضُ الشاشةُ مدرّسي مساحةٍ واحدةٍ تحتَ صفٍّ يخصُّ
            | المنصّةَ كلَّها — وتمرُّ خضراءَ في أيِّ اختبارٍ بمساحةٍ واحدة.
            */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(WorkspaceScope::class)
                ->with('workspace'))
            ->defaultSort('search_name')
            ->columns([
                TextColumn::make('search_name')->label('المدرّس')->searchable()->sortable()->placeholder('—'),

                TextColumn::make('workspace.name')->label('مساحة العمل')->toggleable(),

                TextColumn::make('approval_status')
                    ->label('الاعتماد')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TeacherProfileResource::approvalLabel($state))
                    ->color(fn (string $state): string => TeacherProfileResource::approvalColor($state))
                    ->sortable(),

                IconColumn::make('is_publicly_listed')->label('معروض في السوق')->boolean(),

                // ⚠️ `null` تعني «قيد البناء» ولا تعني صفراً: مدرّسٌ جديدٌ ليس
                // مدرّساً غيرَ موثوق (FR-024).
                TextColumn::make('trust_score')
                    ->label('درجة الثقة')
                    ->placeholder('قيد البناء')
                    ->sortable(),

                TextColumn::make('completed_sessions_count')->label('حصص مكتملة')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('approval_status')
                    ->label('الاعتماد')
                    ->options(TeacherProfileResource::APPROVAL_STATUSES),

                TernaryFilter::make('is_publicly_listed')->label('معروض في السوق'),
            ]);
    }

    /**
     * يُقرأُ بإذنِ مراجعةِ المدرّسين، لا بإذنِ إدارةِ التصنيف.
     *
     * الصفحةُ الحاويةُ تحرسُها {@see TaxonomyPolicy}،
     * وما يُعرَضُ هنا صفوفُ ملفّاتِ المدرّسين — سؤالٌ آخرُ وله حارسُه.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return TeacherProfileResource::canViewAny();
    }
}
