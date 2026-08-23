<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\ListOwnAssignments;
use App\Modules\Community\Http\Resources\AssistantAssignmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * «Where am I an assistant?» — asked by the assistant about themselves.
 *
 * No permission and no policy: the filter is the authenticated user's own id,
 * which is ownership rather than authorisation. The same shape as a student
 * reading their own attendance row, and for the same reason — a permission here
 * would be a permission every assistant holds, which is a permission that
 * answers nothing.
 */
class AssistantController extends Controller
{
    public function me(Request $request, ListOwnAssignments $action): AnonymousResourceCollection
    {
        return AssistantAssignmentResource::collection(
            $action->handle($this->currentUser($request))
        );
    }
}
