<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkspaceResource\RelationManagers;

use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * طلّابُ مكانِ العملِ — نصفُ `workspace_members` الذي لا يُعرَضُ في «الفريق».
 * قراءةً فقط، وبالبابِ نفسِه ({@see MembersRelationManager}).
 */
class StudentsRelationManager extends MembersRelationManager
{
    protected static ?string $modelLabel = 'طالب';

    protected static ?string $pluralModelLabel = 'الطلّاب';

    protected static ?string $title = 'الطلّاب';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedAcademicCap;

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    protected function scopeMembers(Builder $query): Builder
    {
        return $query->where('workspace_members.role', Roles::STUDENT);
    }
}
