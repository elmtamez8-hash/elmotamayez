<?php

declare(strict_types=1);

namespace App\Modules\Courses\Filament\Pages;

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
class ReviewPromoVideos extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.review-promo-videos';

    protected static ?string $slug = 'review-promo-videos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

    protected static ?int $navigationSort = 15;

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
        if (! static::canAccess()) {
            return null;
        }

        $waiting = self::pending()->count();

        return $waiting === 0 ? null : (string) $waiting;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    /** @return Builder<Course> */
    private static function pending(): Builder
    {
        return Course::query()
            ->withoutWorkspaceScope()
            ->where('promo_video_status', Course::PROMO_PENDING)
            ->whereNotNull('promo_video_id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::pending()->with('workspace')->latest('updated_at'))
            ->emptyStateHeading('لا فيديوهات تنتظر المراجعة')
            ->columns([
                TextColumn::make('title')->label('الكورس')->wrap()
                    ->description(fn (Course $record): ?string => $record->workspace?->name),
                TextColumn::make('promo_video_id')->label('الفيديو')
                    ->formatStateUsing(fn (): string => 'مشاهدة على يوتيوب')
                    ->url(fn (Course $record): string => 'https://www.youtube.com/watch?v='.rawurlencode((string) $record->promo_video_id))
                    ->openUrlInNewTab(),
                TextColumn::make('updated_at')->label('أُرسِل في')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('اعتماد')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => static::canAccess())
                    ->action(fn (Course $record) => $this->decide($record, Course::PROMO_APPROVED, null)),
                Action::make('reject')
                    ->label('رفض')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize(fn (): bool => static::canAccess())
                    ->schema([
                        // مطلوبٌ في الفعلِ نفسِه أيضاً: رفضٌ بلا سببٍ يُجيبُه المدرّسُ بلصقِ الرابطِ نفسِه.
                        Textarea::make('reason')->label('سبب الرفض')->required()->rows(3)->maxLength(1000),
                    ])
                    ->action(fn (Course $record, array $data) => $this->decide(
                        $record,
                        Course::PROMO_REJECTED,
                        (string) ($data['reason'] ?? ''),
                    )),
            ]);
    }

    private function decide(Course $record, string $decision, ?string $reason): void
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

        Notification::make()->success()
            ->title($decision === Course::PROMO_APPROVED ? 'اعتُمد الفيديو' : 'رُفض الفيديو')
            ->send();
    }
}
