<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CourseResource\Pages;
use App\Models\User;
use App\Modules\Courses\Actions\ReviewCoursePromoVideo;
use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Enums\CourseVisibility;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Enums\Currency;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return 'الكورسات';
    }

    public static function getModelLabel(): string
    {
        return 'كورس';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الكورسات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التعريف')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('العنوان')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->label('المُعرِّف')
                            ->maxLength(255),
                        Select::make('subject_id')
                            ->label('المادّة')
                            ->relationship('subject', 'name')
                            ->searchable()
                            ->preload(),
                        /*
                        | ⚠️ المجموعةُ ومقابلُها العربيُّ يعيشان في النوعِ لا هنا. كانت
                        | الحالةُ مكتوبةً ثلاثَ مرّاتٍ في هذا الملفِّ وحدَه — في النموذجِ
                        | وفي المرشِّحِ وفي الشارة — فطبعَتِ الشارةُ `published` خاماً
                        | بينما النموذجُ يعرضُها بالإنجليزيّة.
                        |
                        | ⚠️ وقائمةُ الظهورِ كانت **خيارَين** بينما `CourseVisibility`
                        | تحملُ ثلاثة: `hidden` موجودةٌ في قاعدةِ البيانات، ولا سبيلَ إلى
                        | بلوغِها ولا إلى الخروجِ منها من الشاشة. اشتقاقُ الخياراتِ من
                        | `cases()` يجعلُ ذلك مستحيلاً بالبناء.
                        */
                        Select::make('status')
                            ->label('الحالة')
                            ->options(CourseStatus::options())
                            ->required(),
                        Select::make('visibility')
                            ->label('الظهور')
                            ->options(CourseVisibility::options())
                            ->required(),
                        /*
                        | ⚠️ أعضاءُ مساحةِ **المقرَّرِ**، لا مساحةِ من يقرأُ الشاشة.
                        | كانت القائمةُ تُبنى من `WorkspaceContext::current()`، وهو
                        | `null` لمديرِ المنصّةِ (يرجعُ إلى `users.last_workspace_id`
                        | ولا شيءَ يكتبُه له) — فتخرجُ فارغةً، ويعرضُ الحقلُ القيمةَ
                        | الخامَّ: رقمُ المستخدِمِ `37` مكانَ بريدِه، على شاشةِ تعديلٍ
                        | حيّة. والقراءةُ من `$record` تُصلِحُ الأمرَينِ معاً: تملأُ
                        | القائمةَ لأيِّ قارئ، وتمنعُ إسنادَ المقرَّرِ إلى شخصٍ من
                        | مساحةٍ أخرى.
                        */
                        Select::make('created_by')
                            ->label('أنشأه')
                            ->options(function (?Course $record): array {
                                $workspace = $record instanceof Course
                                    ? $record->workspace
                                    : app(WorkspaceContext::class)->current();

                                if ($workspace === null) {
                                    return [];
                                }

                                return $workspace->members()
                                    ->pluck('users.email', 'users.id')
                                    ->all();
                            })
                            ->searchable(),
                    ]),

                Section::make('التسعير')
                    ->columns(2)
                    ->schema([
                        // Minor units since 007: the field takes 4999, not 49.99. A
                        // `numeric` input here would accept a decimal and store a
                        // hundredth of what the operator typed.
                        TextInput::make('price_minor')
                            ->label('السعر')
                            ->integer()
                            ->helperText('بالوحدات الصغرى — ٤٩٫٩٩ ر.ق تُكتب 4999')
                            ->default(0),
                        /*
                        | ⚠️ الافتراضُ كانَ `'USD'` — بقيّةٌ من هيكلِ لارافيل لا من
                        | المنتَج — وكانَ الحقلُ نصّاً حرّاً. فكلُّ كورسٍ يُنشَأُ دونَ
                        | لمسِ الحقلِ كانَ يُسعَّرُ بالدولارِ ويُعرَضُ به، بينما عملةُ
                        | المنصّةِ هي `config('billing.currency')` — الريالُ القطريّ.
                        */
                        Select::make('currency')
                            ->label('العملة')
                            ->options(Currency::options())
                            ->required()
                            ->default((string) config('billing.currency')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('العنوان')->searchable()->sortable()->wrap(),
                /*
                | ⚠️ بلا `searchable()` ولا `sortable()` على عمودِ `name`، وبلا تقييدِ
                | أعمدةٍ في التحميلِ المسبق: `users` لا عمودَ فيه بهذا الاسم — إنّه
                | سِمةٌ محسوبةٌ فوقَ `first_name` و`last_name`. تحميلٌ مقيَّدٌ يطبعُ
                | فراغاً في كلِّ صفٍّ بردٍّ ٢٠٠ ولا خطأ، وبحثٌ عليه يبني SQL على عمودٍ
                | لا وجودَ له.
                */
                TextColumn::make('workspace.name')
                    ->label('المدرّس / الأكاديميّة')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('subject.name')
                    ->label('المادّة')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state): string => CourseStatus::labelFor($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('price_minor')
                    ->label('السعر')
                    ->money(fn (Course $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                /*
                | العدُّ من `withCount` لا من إغلاقٍ داخلَ العمود: المورِدُ يُنفَّذُ مرّةً
                | لكلِّ صفّ، فاستعلامٌ داخلَه هو N+1 بالبناء لا بالصدفة.
                */
                TextColumn::make('enrollments_count')
                    ->label('المسجَّلون')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('أنشأه')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                /*
                | حالةُ الفيديو الترويجيّ (٠١٨). لونُ `pending` تحذيرٌ لا رمادٌ:
                | صفٌّ ينتظرُ قراراً بشريّاً، ورماديُّه يجعلُه يختفي في القائمة.
                */
                TextColumn::make('promo_video_status')->label('الفيديو الترويجي')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Course::PROMO_PENDING => 'بانتظار المراجعة',
                        Course::PROMO_APPROVED => 'معتمَد',
                        Course::PROMO_REJECTED => 'مرفوض',
                        default => 'لا يوجد',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Course::PROMO_PENDING => 'warning',
                        Course::PROMO_APPROVED => 'success',
                        Course::PROMO_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('أُنشئ في')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(CourseStatus::options()),
                SelectFilter::make('visibility')
                    ->label('الظهور')
                    ->options(CourseVisibility::options()),
                SelectFilter::make('promo_video_status')
                    ->label('الفيديو الترويجي')
                    ->options([
                        Course::PROMO_PENDING => 'بانتظار المراجعة',
                        Course::PROMO_APPROVED => 'معتمَد',
                        Course::PROMO_REJECTED => 'مرفوض',
                        Course::PROMO_NONE => 'لا يوجد',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                /*
                | مراجعةُ الفيديو الترويجيّ (٠١٨ · FR-006).
                |
                | ⚠️ الحارسُ مُكرَّرٌ هنا وفي الإجراءِ معاً، وهذا ليس تكراراً:
                | `Gate::before` يمرّرُ المديرَ الأعلى فوقَ كلِّ سياسة، وقائمةُ
                | Filament لا تستشيرُ سياسةَ الصفِّ أصلاً — سابقتا
                | `CreditPackageResource` و`OrderResource` كلتاهما مكتوبتان.
                |
                | ويظهرُ الإجراءُ لصفٍّ فيه ما يُراجَع وحدَه: زرٌّ يُجيبُ «لا يوجدُ
                | فيديو» زرٌّ يَعِدُ ثمّ يمنع.
                */
                Action::make('reviewPromoVideo')
                    ->label('مراجعة الفيديو')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->visible(fn (Course $record): bool => $record->promo_video_status !== Course::PROMO_NONE
                        && auth()->user()?->can(Permissions::MARKETPLACE_PROMO_REVIEW) === true)
                    ->schema([
                        Select::make('decision')
                            ->label('القرار')
                            ->required()
                            ->options([
                                Course::PROMO_APPROVED => 'اعتماد',
                                Course::PROMO_REJECTED => 'رفض',
                            ]),
                        TextInput::make('reason')
                            ->label('سبب الرفض')
                            ->helperText('مطلوب مع الرفض — ورفضٌ بلا سببٍ يُجيبه المدرّس بلصقِ الرابطِ نفسِه.')
                            ->maxLength(1000),
                    ])
                    ->action(function (Course $record, array $data): void {
                        $reviewer = auth()->user();

                        /*
                        | اللوحةُ محميّةٌ بالجلسة، فهذا لا يقع عمليّاً — لكنّ `null`
                        | يمرُّ صامتاً إلى الإجراء فيَختِمُ `reviewed_by` بلا أحد،
                        | وسجلُّ المراجعةِ يفقدُ مَن قرّر.
                        */
                        if (! $reviewer instanceof User) {
                            return;
                        }

                        app(ReviewCoursePromoVideo::class)->handle(
                            $reviewer,
                            (string) $record->uuid,
                            (string) $data['decision'],
                            $data['reason'] ?? null,
                        );
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['workspace', 'subject', 'creator'])
            ->withCount(['enrollments']);
    }

    public static function getRelations(): array
    {
        return [
            CourseResource\RelationManagers\EnrollmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
