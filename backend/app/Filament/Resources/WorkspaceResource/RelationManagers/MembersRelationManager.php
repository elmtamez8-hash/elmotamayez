<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkspaceResource\RelationManagers;

use App\Filament\Resources\UserResource;
use App\Filament\Support\RecordLink;
use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * أعضاءُ مكانِ العملِ ودورُ كلٍّ منهم — قراءةً فقط.
 *
 * ⚠️ **الدورُ عمودٌ ظاهرٌ ومرشِّح، لا افتراض.** `workspace_members` يحملُ صفوفَ
 * الطلّابِ أيضاً، فقائمةٌ بلا دورٍ تعرضُ الطالبَ عضواً في الفريق. الافتراضُ
 * «الفريقُ وحدَه»، والطلّابُ خيارٌ في المرشِّحِ يُختارُ عمداً.
 *
 * ⚠️ **ولا إرفاقَ ولا فكَّ ولا تعديل.** العضويّةُ تُكتَبُ من الدعوةِ وقبولِها
 * وتغييرِ الدور، وكلٌّ منها يمرُّ بـ`StaffAccounts` الذي يرفضُ حسابَ طالبٍ أو وليِّ
 * أمرٍ موظّفاً؛ إرفاقُ Filament يكتبُ الجدولَ الوسيطَ مباشرةً فيتخطّاه.
 * `users` منصّيٌّ بلا نطاق، فلا تجاوزَ مطلوبٌ هنا.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $modelLabel = 'عضو';

    protected static ?string $pluralModelLabel = 'الأعضاء';

    protected static ?string $title = 'الأعضاء';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUsers;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable(['first_name', 'last_name']),
                TextColumn::make('email')
                    ->label('البريد')
                    ->searchable()
                    ->url(fn (User $record): ?string => RecordLink::to(UserResource::class, $record)),
                TextColumn::make('role')
                    ->label('الدور')
                    ->badge()
                    ->color(fn (?string $state): string => $state === Roles::STUDENT ? 'gray' : 'success')
                    ->formatStateUsing(fn (?string $state): string => Roles::label((string) $state)),
                TextColumn::make('joined_at')->label('انضمّ')->dateTime('Y-m-d')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('membership')
                    ->label('الصفة')
                    ->options([
                        'staff' => 'الفريق',
                        'students' => 'الطلّاب',
                    ])
                    ->default('staff')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'staff' => $query->where('workspace_members.role', '!=', Roles::STUDENT),
                        'students' => $query->where('workspace_members.role', Roles::STUDENT),
                        default => $query,
                    }),
            ]);
    }
}
