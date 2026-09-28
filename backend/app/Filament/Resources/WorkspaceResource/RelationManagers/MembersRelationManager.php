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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * أعضاءُ مكانِ العملِ ودورُ كلٍّ منهم — قراءةً فقط.
 *
 * ⚠️ **الفريقُ بدورِ العضويّة، لا بمجرّدِ العضويّة.** `workspace_members` يحملُ
 * صفوفَ الطلّابِ أيضاً، فهذا الجدولُ يعرضُ `role != student` وحدَه، والطلّابُ
 * جدولٌ مستقلّ ({@see StudentsRelationManager}) — لا مرشِّحٌ يخلطُ الاثنين.
 *
 * ⚠️ **ولا إرفاقَ ولا فكَّ ولا تعديل.** العضويّةُ تُكتَبُ من الدعوةِ وقبولِها
 * وتغييرِ الدور، وكلٌّ منها يمرُّ بـ`StaffAccounts` الذي يرفضُ حسابَ طالبٍ أو وليِّ
 * أمرٍ موظّفاً؛ إرفاقُ Filament يكتبُ الجدولَ الوسيطَ مباشرةً فيتخطّاه.
 * `users` منصّيٌّ بلا نطاق، فلا تجاوزَ مطلوبٌ هنا.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $modelLabel = 'عضو فريق';

    protected static ?string $pluralModelLabel = 'الفريق';

    protected static ?string $title = 'الفريق';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUsers;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * The team: every pivot role but a student's.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    protected function scopeMembers(Builder $query): Builder
    {
        return $query->where('workspace_members.role', '!=', Roles::STUDENT);
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeMembers($query));
    }
}
