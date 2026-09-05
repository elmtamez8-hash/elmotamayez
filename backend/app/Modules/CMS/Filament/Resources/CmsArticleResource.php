<?php

declare(strict_types=1);

namespace App\Modules\CMS\Filament\Resources;

use App\Modules\CMS\Filament\Resources\CmsArticleResource\Pages;
use App\Modules\CMS\Models\Article;
use App\Modules\Tenancy\Support\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The blog, from the panel (011 · US5).
 *
 * ⚠️ THE CLASS IS `CmsArticleResource` AND NOT `ArticleResource` ON PURPOSE. This
 * module already has an `Http\Resources\ArticleResource`, and two classes with one
 * basename in one module is a `use` statement nobody reads twice — the same reason
 * `ClassSession` is not called `Session`.
 *
 * ⚠️ AND `canViewAny()` IS DECLARED HERE BECAUSE `ArticlePolicy` HAS NO `viewAny()`.
 * `Resource::canViewAny()` delegates to the policy and FALLS THROUGH TO
 * `Response::allow()` when the method does not exist — so without this line the
 * screen opens for everyone who gets past the panel's own door, including an
 * assistant teacher. `PanelResourceDoorTest` fails the build over exactly that.
 * One door, never two: the rule that test writes down is «أحدُهما، لا كلاهما», so
 * a `viewAny()` must NOT also be added to the policy — a resource declaring one
 * answer over a policy declaring another is how `PlatformStaffResource` once
 * opened the platform's delegation screen to a workspace owner.
 *
 * ⚠️ THE QUERY STAYS WORKSPACE-SCOPED, unlike `PlanResource`'s. Every teacher can
 * reach this panel, and an article is the teacher's own writing — an unscoped list
 * would hand one teacher another's drafts with an edit button beside each. That is
 * the opposite call from the pricing queue, where the reader is a platform officer
 * and a scoped list silently shows one arbitrary teacher's rows as the whole queue.
 */
class CmsArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'المحتوى والتعلّم';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'مقال';

    protected static ?string $pluralModelLabel = 'المدوّنة';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permissions::CMS_VIEW) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permissions::CMS_CREATE) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can(Permissions::CMS_UPDATE) === true;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can(Permissions::CMS_DELETE) === true;
    }

    /** Read by the two form fields that decide whether the public sees the row. */
    private static function canPublish(): bool
    {
        return auth()->user()?->can(Permissions::CMS_PUBLISH) === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المقال')
                ->columns(2)
                ->schema([
                    TextInput::make('title')->label('العنوان')->required()->maxLength(255)->columnSpanFull(),

                    /*
                    | ⚠️ EMPTY MEANS «BUILD IT FROM THE TITLE», AND THE HELP TEXT
                    | HAS TO SAY THE OTHER HALF: it is never rebuilt afterwards.
                    | `doNotGenerateSlugsOnUpdate()` is deliberate — a published URL
                    | that changes is a URL that 404s, and every share of it is a
                    | dead link — but a teacher who renames an article and expects
                    | the address to follow will otherwise think it is broken.
                    */
                    TextInput::make('slug')
                        ->label('الرابط')
                        ->maxLength(255)
                        ->helperText('اتركْه فارغاً ليُبنى من العنوان. لا يتغيّرُ بعدَ ذلك مع تغيُّرِ العنوان — '
                            .'الرابطُ المنشورُ الذي يتبدَّلُ رابطٌ مكسور.'),

                    /*
                    | ⛔ THE PANEL IS THE DOOR PEOPLE ACTUALLY USE, AND IT HAD NO
                    | GATE ON THIS FIELD. `canEdit()` asks `cms.update`, which the
                    | matrix gives an assistant-teacher — while `cms.publish` and
                    | `cms.delete` are the teacher's alone. So an assistant
                    | published to the teacher's public blog, and unpublished a
                    | live post, by changing one select. The API half of this is
                    | closed in `ArticleController::update()`; a rule spelled at
                    | one door is a rule the other door does not have.
                    |
                    | ⚠️ `disabled()` AND NOT `visible()`: a writer who may not
                    | publish still needs to SEE whether the article is live —
                    | hiding the field makes «why is my draft not on the blog» a
                    | question with nothing on screen to answer it. Filament does
                    | not dehydrate a disabled field, so the stored value is kept
                    | on edit and the column default (`draft`) applies on create;
                    | the client cannot send one either way.
                    */
                    Select::make('status')
                        ->label('الحالة')
                        ->options(['draft' => 'مسوّدة', 'published' => 'منشور'])
                        ->default('draft')
                        ->required()
                        ->disabled(fn (): bool => ! self::canPublish())
                        ->helperText(fn (): ?string => self::canPublish()
                            ? null
                            : 'النشرُ والسحبُ لمن يحملُ صلاحيةَ النشر.'),

                    // Scheduled into the future de-lists a live article without
                    // touching `status` at all — `publicListingConstraints()` asks
                    // `published_at <= now()` — so it is the same capability.
                    DateTimePicker::make('published_at')
                        ->label('تاريخ النشر')
                        ->disabled(fn (): bool => ! self::canPublish())
                        ->helperText('تاريخٌ في المستقبلِ يعني مقالاً مجدولاً: لا يظهرُ للعامّةِ حتى يحلّ.'),

                    Textarea::make('excerpt')->label('المقتطف')->rows(2)->maxLength(500)->columnSpanFull(),

                    /*
                    | غلافُ المقال — ويُخزَّنُ **مساراً نسبيّاً على القرصِ العامّ**
                    | لا رابطاً كاملاً، وهو ما يقرؤه `PublicArticleResource`
                    | ببناءِ `asset('storage/'.$path)`.
                    |
                    | ⚠️ `->disk('public')` صراحةً: القرصُ الافتراضيُّ في
                    | `config/filesystems.php` قد يكونُ خاصّاً، والصورةُ المحفوظةُ
                    | هناك تُرفَعُ بنجاحٍ ثمّ لا تُفتَحُ من أيِّ صفحةٍ عامّة —
                    | يظهرُ العطلُ عندَ القارئِ لا عندَ من رفع.
                    |
                    | ⚠️ و`->image()` لا `acceptedFileTypes` وحدَها: الأولى تُفعِّلُ
                    | معاينةَ الصورةِ في المحرِّر، وغلافٌ يُختارُ بلا رؤيتِه هو
                    | غلافٌ خاطئٌ يُكتشَفُ على الصفحةِ الحيّة.
                    |
                    | ⚠️ والنسبةُ ‏١٦:٩ مقصوصةٌ هنا لا في CSS: البطاقةُ والمقالُ
                    | يعرضانِها بنسبتَينِ مختلفتَين، وصورةٌ طويلةٌ تُقَصُّ في
                    | المتصفّحِ من وسطِها فيضيعُ ما اختارَه الكاتبُ ليُرى.
                    */
                    FileUpload::make('cover_path')
                        ->label('صورة الغلاف')
                        ->helperText('تظهر في بطاقة المقال وفي أعلى الصفحة وفي بطاقة المشاركة. الأفضل ١٦:٩.')
                        ->image()
                        ->imageEditor()
                        ->imageCropAspectRatio('16:9')
                        ->disk('public')
                        ->directory('article-covers')
                        ->maxSize(4096)
                        ->columnSpanFull(),

                    Textarea::make('body')
                        ->label('النصّ')
                        ->rows(14)
                        ->columnSpanFull()
                        // Markdown, rendered per response and never stored as HTML:
                        // raw tags are STRIPPED rather than escaped, so the
                        // allowlist is the Markdown feature set and there is no
                        // sanitiser configuration to get wrong.
                        ->helperText('يُكتَبُ بصيغةِ Markdown. وسومُ HTML الخامُّ تُحذَفُ عندَ العرض.'),
                ]),

            Section::make('محرّكات البحث')
                ->description('يظهرُ هذا في نتيجةِ البحثِ وفي بطاقةِ المشاركة، لا في الصفحة.')
                ->columns(2)
                ->schema([
                    TextInput::make('seo_title')->label('عنوان محرّكات البحث')->maxLength(255),
                    TextInput::make('canonical_url')
                        ->label('الرابط الأساسي')
                        ->url()
                        ->maxLength(500)
                        ->helperText('يُترَكُ فارغاً إلّا إذا نُشِرَ النصُّ نفسُه في مكانٍ آخرَ أصلاً.'),
                    /*
                    | ⚠️ **الخلاصةُ ليست المقتطف، ولذلك حقلٌ ثانٍ لا إعادةُ استعمال.**
                    | المقتطفُ إغراءٌ يُقرَأُ تحتَ العنوان؛ هذه فقرةٌ مكتفيةٌ بنفسِها
                    | يقتبسُها محرّكُ الإجابةِ حرفيّاً بلا ما حولَها. تُعرَضُ في صندوقِ
                    | «باختصار» أعلى المقالِ وتُرسَلُ في `abstract` ضمنَ البيانات
                    | المنظَّمة — أي أنّ ما يُقرَأُ هو ما يُقتَبَس.
                    */
                    Textarea::make('summary')
                        ->label('باختصار (خلاصة يقتبسها محرّك الإجابة)')
                        ->helperText('فقرة واحدة تُجيب عن سؤال المقال بلا سياق حولها. تظهر أعلى المقال.')
                        ->rows(3)
                        ->maxLength(600)
                        ->columnSpanFull(),

                    /*
                    | ⚠️ **أسئلةٌ مُهيكَلةٌ لا عناوينُ سؤالٍ في المتن.** ‏`FAQPage`
                    | يحتاجُ زوجاً صريحاً، واستخراجُه بتخمينِ «ما يشبهُ السؤال» من
                    | الـMarkdown يُنتِجُ بياناتٍ منظَّمةً تصفُ ما ليس في الصفحة.
                    | والزوجُ الناقصُ مُصفّىً في المورِدِ لا هنا: سؤالٌ بلا جوابٍ في
                    | البياناتِ المنظَّمةِ أسوأُ من غيابِ القسم.
                    */
                    Repeater::make('faq')
                        ->label('أسئلة شائعة عن هذا المقال')
                        ->helperText('تظهر أسفل المقال وتُرسَل كـFAQPage لمحرّكات البحث والإجابة.')
                        ->schema([
                            TextInput::make('question')->label('السؤال')->required()->maxLength(255),
                            Textarea::make('answer')->label('الجواب')->required()->rows(2)->maxLength(1000),
                        ])
                        ->addActionLabel('أضف سؤالاً')
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                        ->default([])
                        ->columnSpanFull(),

                    /*
                    | ⚠️ مُفعَّلٌ افتراضاً، والمفتاحُ للاستثناء: إيقافُ الفهرسةِ
                    | قرارٌ نادرٌ (صفحةُ حملةٍ، نسخةٌ مكرّرةٌ عمداً)، وافتراضٌ معكوسٌ
                    | يُخفي كلَّ مقالٍ سبقَ هذا الحقلَ من نتائجِ البحثِ صامتاً.
                    */
                    Toggle::make('is_indexable')
                        ->label('اسمحْ لمحرّكات البحث بفهرسة المقال')
                        ->default(true)
                        ->columnSpanFull(),

                    Textarea::make('seo_description')->label('وصف محرّكات البحث')->rows(2)->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                // ⚠️ عمودُ صورةٍ لا نصُّ مسار: الغلافُ يُراجَعُ بالنظرِ إليه،
                // ومسارٌ مكتوبٌ يُخبِرُ أنّ حقلاً مُلِئَ لا أنّ الصورةَ صحيحة.
                ImageColumn::make('cover_path')->label('الغلاف')->disk('public')->square(),
                TextColumn::make('title')->label('العنوان')->searchable()->limit(60),
                TextColumn::make('slug')->label('الرابط')->searchable()->limit(40)->toggleable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'published' ? 'منشور' : 'مسوّدة')
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                TextColumn::make('published_at')->label('تاريخ النشر')->dateTime()->sortable()
                    ->placeholder('—'),
                TextColumn::make('category.name')->label('التصنيف')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(['draft' => 'مسوّدة', 'published' => 'منشور']),
            ])
            ->recordActions([EditAction::make()]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCmsArticles::route('/'),
            'create' => Pages\CreateCmsArticle::route('/create'),
            'edit' => Pages\EditCmsArticle::route('/{record}/edit'),
        ];
    }
}
