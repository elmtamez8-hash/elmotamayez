<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\AddChild;
use App\Modules\Identity\Actions\RegisterParent;
use App\Modules\Identity\Data\AddChildData;
use App\Modules\Identity\Data\RegisterParentData;
use App\Modules\Identity\Http\Requests\AddChildRequest;
use App\Modules\Identity\Http\Requests\RegisterParentRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\NotificationPreference;
use App\Modules\Identity\Models\ParentChildLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function register(RegisterParentRequest $request, RegisterParent $action): JsonResponse
    {
        $user = $action->handle(RegisterParentData::fromArray($request->validated()));

        // Signed in immediately: the next screen is "add your child", and a login
        // form between the two is where the flow gets abandoned.
        return response()->json([
            'user' => UserResource::make($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
        ], 201);
    }

    public function children(Request $request): JsonResponse
    {
        $links = ParentChildLink::query()
            ->where('parent_id', $this->currentUser($request)->getKey())
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $links->map($this->present(...))->all()]);
    }

    public function addChild(AddChildRequest $request, AddChild $action): JsonResponse
    {
        $link = $action->handle(
            $this->currentUser($request),
            AddChildData::fromArray($request->validated()),
        );

        return response()->json($this->present($link), 201);
    }

    public function showChild(Request $request, string $uuid): JsonResponse
    {
        $link = ParentChildLink::query()->where('uuid', $uuid)->firstOrFail();

        // 403, not 404: the row exists and the policy is what stops this parent
        // (FR-075). Hiding that would be indistinguishable from a typo.
        abort_unless($this->currentUser($request)->can('view', $link), 403);

        return response()->json($this->present($link));
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        return response()->json($this->preferences($request)->only(['weekly_reports', 'session_alerts']));
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weekly_reports' => ['required', 'boolean'],
            'session_alerts' => ['required', 'boolean'],
        ]);

        $preferences = $this->preferences($request);
        $preferences->fill($validated)->save();

        return response()->json($preferences->only(['weekly_reports', 'session_alerts']));
    }

    /**
     * Created on demand for accounts that predate the preferences table — and for
     * students, who reach this endpoint too.
     */
    private function preferences(Request $request): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            ['user_id' => $this->currentUser($request)->getKey()],
            ['weekly_reports' => true, 'session_alerts' => true],
        );
    }

    /** @return array<string, mixed> */
    private function present(ParentChildLink $link): array
    {
        return [
            'uuid' => $link->uuid,
            'name' => $link->child_name,
            'age' => $link->child_age,
            'grade_level_slug' => $link->child_grade_level_slug,
            // Whether the child has an account of their own, without exposing who
            // that account belongs to.
            'has_account' => $link->child_id !== null,
        ];
    }
}
