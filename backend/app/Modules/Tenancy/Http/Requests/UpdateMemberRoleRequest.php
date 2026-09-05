<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRoleRequest extends FormRequest
{
    /**
     * ⚠️ `members.update`, WHICH UNTIL NOW WAS READ BY NOTHING AT ALL — declared,
     * seeded, granted to the owner by the matrix, offered on the roles screen as
     * «تعديل — الأعضاء», and asked by no file in the tree. This is its first
     * reader, and it is deliberately NOT `members.invite`: an owner who delegates
     * inviting has delegated adding a student, while changing a role is how an
     * assistant becomes a teacher.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::MEMBERS_UPDATE) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
            | ⚠️ THE SAME FOUR `InviteMemberRequest` ALLOWS, LITERALLY. «Which
            | roles may a member hold» is one question, and two doors answering it
            | differently is this repository's two-spellings defect: a role you can
            | be invited into but never moved to, or moved to but never invited
            | into, and nothing anywhere saying which.
            |
            | The owner's own row is refused by the Action, not by this list —
            | `tenant-owner` is a legitimate role for a second member, and the
            | hazard is editing the person `workspaces.owner_user_id` names.
            */
            'role' => ['required', 'in:tenant-owner,teacher,assistant-teacher,student'],
        ];
    }
}
