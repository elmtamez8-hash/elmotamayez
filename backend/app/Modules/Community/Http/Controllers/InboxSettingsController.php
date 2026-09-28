<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Community\Support\TeacherInboxSettings;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «استقبال رسائل من غير المشتركين» — the teacher's switch (2026-09-28).
 *
 * ⚠️ `settings.update` FOR THE READ AS WELL AS THE WRITE. The only screen that
 * reads it is the card that writes it, and that card is shown on the same
 * permission; an assistant — who holds neither — sees neither, which is the
 * owner's rule for every other workspace setting (`GradingController::
 * updateSettings()` is the precedent this follows, directly on the controller).
 *
 * The workspace is the CURRENT one, never a parameter: a teacher changes their
 * own switch, and a uuid in the body would be a second way to name somebody
 * else's.
 */
class InboxSettingsController extends Controller
{
    public function show(Request $request, TeacherInboxSettings $settings): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::SETTINGS_UPDATE), 403);

        $workspace = app(WorkspaceContext::class)->current();

        abort_if($workspace === null, 422, 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.');

        return response()->json(['data' => ['accepts_prospects' => $settings->acceptsProspects($workspace)]]);
    }

    public function update(Request $request, TeacherInboxSettings $settings): JsonResponse
    {
        abort_unless($this->currentUser($request)->can(Permissions::SETTINGS_UPDATE), 403);

        $validated = $request->validate(['accepts_prospects' => ['required', 'boolean']]);
        $workspace = app(WorkspaceContext::class)->current();

        abort_if($workspace === null, 422, 'تعذّر تحديد مكان عملك. أعد تحميل الصفحة.');

        $settings->setAcceptsProspects($workspace, (bool) $validated['accepts_prospects']);

        return response()->json(['data' => ['accepts_prospects' => $settings->acceptsProspects($workspace)]]);
    }
}
