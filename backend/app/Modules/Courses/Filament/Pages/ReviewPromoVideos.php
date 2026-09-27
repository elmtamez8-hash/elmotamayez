<?php

declare(strict_types=1);

namespace App\Modules\Courses\Filament\Pages;

use App\Filament\Contracts\AwaitsDecision;
use App\Filament\NavigationGroups;
use App\Models\User;
use App\Modules\Courses\Actions\ReviewCoursePromoVideo;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * «فيديوهات تنتظر المراجعة» — شاشةُ مسؤولِ الامتثالِ لمراجعةِ الفيديو الترويجيّ
 * (٠١٨ · FR-006).
 *
 * ⛔ **الصلاحيّةُ كانت لمسؤولِ الامتثالِ والزرُّ لم يكنْ له.** `marketplace.promo.review`
 * في مصفوفةِ `compliance-officer`، وواجهتُها الوحيدةُ كانت إجراءَ صفٍّ على قائمةِ
 * الكورسات — وتلك القائمةُ لمديرِ المنصّةِ وحدَه (كلُّ صلاحيّاتِ الكورساتِ صلاحيّاتُ
 * مساحة). فالمسؤولُ يحملُ الصلاحيّةَ ولا يصلُ شاشةً تستعملُها، وكلُّ فيديو يبقى
 * «بانتظار المراجعة» إلى أن يمرَّ مديرُ المنصّةِ من هناك مصادفةً.
 *
 * ⚠️ **والقائمةُ بلا نطاق.** مسؤولُ الامتثالِ الذي يملكُ مساحةً سياقُه محلولٌ إلى
 * `last_workspace_id`، فقائمةٌ تحتَ النطاقِ تعرضُ فيديوهاتِ مساحتِه وحدَها صامتة —
 * الطبقةُ الخامسةُ من طبقاتِ ٠٢٤. والقرارُ يمرُّ بـ{@see ReviewCoursePromoVideo}
 * الذي يقرأُ الكورسَ بلا نطاقٍ ويسألُ الصلاحيّةَ ثانيةً.
 */
class ReviewPromoVideos extends Page implements AwaitsDecision, HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.review-promo-videos';

    protected static ?string $slug = 'review-promo-videos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroups::DECISIONS;

    protected static ?int $navigationSort = 60;

    /**
     * ⚠️ صلاحيّةُ **منصّة**، لا يحملُها دورُ مساحةٍ أبداً — فلا يفتحُ مالكُ مساحةٍ
     * هذه الشاشةَ ليعتمدَ فيديو نفسِه. وتُعادُ داخلَ الفعلِ لأنّ إخفاءَ زرٍّ ليس
     * حراسة.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can(Permissions::MARKETPLACE_PROMO_REVIEW) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'فيديوهات تنتظر المراجعة';
    }

    public function getTitle(): string
    {
        return 'فيديوهات تنتظر المراجعة';
    }

    /**
     * عددُ ما ينتظرُ القرار — الرقمُ الوحيدُ الذي يقولُ للمسؤولِ إنّ مدرّساً ينتظرُه.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::decisionQueueVisible()) {
            return null;
        }

        $waiting = static::pendingCount();

        return $waiting === 0 ? null : (string) $waiting;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    /** ما ينتظرُ القرارَ وحدَه — المعتمَدُ في المرشِّحِ الثاني لا يُعَدّ. */
    public static function pendingCount(): int
    {
        return self::withVideoIn([Course::PROMO_PENDING])->count();
    }

    public static function decisionQueueVisible(): bool
    {
        return static::canAccess();
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<Course>
     */
    private static function withVideoIn(array $statuses): Builder
    {
        return Course::query()
            ->withoutWorkspaceScope()
            ->whereIn('promo_video_status', $statuses)
            ->whereNotNull('promo_video_id');
    }

    /*
    | ⛔ **المعتمَدُ كانَ يختفي من الشاشةِ لحظةَ اعتمادِه، ولا بابَ للرجوع.** فيديو
    | اعتُمِدَ ثمّ تبيّنَ أنّه لا يصلحُ — بدّلَ صاحبُ القناةِ محتواه، أو وصلَ بلاغ —
    | كانَ يبقى على الصفحةِ العامّةِ ولا زرَّ في اللوحةِ يسحبُه (قرارُ المالكِ
    | ٢٠٢٦-٠٩-٢٧). فالجدولُ يقرأُ الحالتَينِ، والمرشِّحُ يبدأُ على «تنتظر المراجعة»
    | فيبقى الطابورُ طابوراً.
    |
    | ⚠️ والسحبُ رفضٌ يمرُّ بـ{@see ReviewCoursePromoVideo} نفسِه، بسببٍ إلزاميّ يُحفَظُ
    | في سجلِّ النشاط. لا إشعارَ يُرسَلُ به للمدرّسِ اليوم، فلا يَعِدُ النصُّ بذلك.
    */
    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::withVideoIn([Course::PROMO_PENDING, Course::PROMO_APPROVED])
                ->with('workspace')
                ->latest('updated_at'))
            ->emptyStateHeading('لا فيديوهات هنا')
            ->filters([
                SelectFilter::make('promo_video_status')
                    ->label('الحالة')
                    ->options([
                        Course::PROMO_PENDING => 'تنتظر المراجعة',
                        Course::PROMO_APPROVED => 'المعتمدة',
                    ])
                    ->default(Course::PROMO_PENDING)
                    ->selectablePlaceholder(false),
            ], layout: FiltersLayout::AboveContent)
            ->columns([
                TextColumn::make('title')->label('الكورس')->wrap()
                    ->description(fn (Course $record): ?string => $record->workspace?->name),
                TextColumn::make('promo_video_id')->label('الفيديو')
                    ->formatStateUsing(fn (): string => 'مشاهدة على يوتيوب')
                    ->url(fn (Course $record): string => 'https://www.youtube.com/watch?v='.rawurlencode((string) $record->promo_video_id))
                    ->openUrlInNewTab(),
                TextColumn::make('updated_at')->label('أُرسِل في')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('promo_video_reviewed_at')->label('اعتُمد في')->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->showsApproved()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('اعتماد')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Course $record): bool => $record->promo_video_status === Course::PROMO_PENDING)
                    ->authorize(fn (): bool => static::canAccess())
                    ->action(fn (Course $record) => $this->decide($record, Course::PROMO_APPROVED, null, 'اعتُمد الفيديو')),
                Action::make('reject')
                    ->label('رفض')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Course $record): bool => $record->promo_video_status === Course::PROMO_PENDING)
                    ->authorize(fn (): bool => static::canAccess())
                    ->schema([
                        // مطلوبٌ في الفعلِ نفسِه أيضاً: رفضٌ بلا سببٍ يُجيبُه المدرّسُ بلصقِ الرابطِ نفسِه.
                        Textarea::make('reason')->label('سبب الرفض')->required()->rows(3)->maxLength(1000),
                    ])
                    ->action(fn (Course $record, array $data) => $this->decide(
                        $record,
                        Course::PROMO_REJECTED,
                        (string) ($data['reason'] ?? ''),
                        'رُفض الفيديو',
                    )),
                Action::make('withdraw')
                    ->label('سحب الاعتماد')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Course $record): bool => $record->promo_video_status === Course::PROMO_APPROVED)
                    ->authorize(fn (): bool => static::canAccess())
                    ->modalDescription('يختفي الفيديو من صفحة الكورس العامّة فوراً، ويُحفَظ السبب في سجلّ النشاط.')
                    ->schema([
                        Textarea::make('reason')->label('سبب سحب الاعتماد')->required()->rows(3)->maxLength(1000),
                    ])
                    ->action(fn (Course $record, array $data) => $this->decide(
                        $record,
                        Course::PROMO_REJECTED,
                        (string) ($data['reason'] ?? ''),
                        'سُحب اعتماد الفيديو',
                    )),
            ]);
    }

    /** هل المرشِّحُ على «المعتمدة» الآن؟ */
    private function showsApproved(): bool
    {
        return ($this->tableFilters['promo_video_status']['value'] ?? null) === Course::PROMO_APPROVED;
    }

    private function decide(Course $record, string $decision, ?string $reason, string $done): void
    {
        $reviewer = Auth::user();

        // اللوحةُ محميّةٌ بالجلسة، لكنّ `null` يمرُّ صامتاً فيَختِمُ `reviewed_by` بلا أحد.
        if (! $reviewer instanceof User) {
            return;
        }

        try {
            app(ReviewCoursePromoVideo::class)->handle($reviewer, (string) $record->uuid, $decision, $reason);
        } catch (DomainException|AuthorizationException $refused) {
            Notification::make()->danger()->title('تعذّر التنفيذ')->body($refused->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($done)->send();
    }
}
