<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\RegisterTeacher;
use App\Modules\Marketplace\Actions\SaveTeacherApplicationStep;
use App\Modules\Marketplace\Actions\SubmitTeacherApplication;
use App\Modules\Marketplace\Data\TeacherStepFourData;
use App\Modules\Marketplace\Data\TeacherStepThreeData;
use App\Modules\Marketplace\Data\TeacherStepTwoData;
use App\Modules\Marketplace\Http\Requests\RegisterTeacherRequest;
use App\Modules\Marketplace\Http\Requests\TeacherStepFourRequest;
use App\Modules\Marketplace\Http\Requests\TeacherStepThreeRequest;
use App\Modules\Marketplace\Http\Requests\TeacherStepTwoRequest;
use App\Modules\Marketplace\Models\TeacherApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The applicant's side of the wizard. The reviewer's side is TeacherReviewController.
 */
class TeacherApplicationController extends Controller
{
    public function register(RegisterTeacherRequest $request, RegisterTeacher $action): JsonResponse
    {
        $application = $action->handle($request->toDto());

        return response()->json([
            'application' => $this->payload($application),
            // Signed in immediately: the remaining three steps are authenticated,
            // and sending someone to a login form mid-wizard loses most of them.
            'token' => $application->user?->createToken('auth-token')->plainTextToken,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['application' => $this->payload($this->application($request))]);
    }

    public function stepTwo(TeacherStepTwoRequest $request, SaveTeacherApplicationStep $action): JsonResponse
    {
        $data = TeacherStepTwoData::fromArray($request->validated());

        return $this->saved($action->handle($this->application($request), 2, $data->toStepData()));
    }

    public function stepThree(TeacherStepThreeRequest $request, SaveTeacherApplicationStep $action): JsonResponse
    {
        $data = TeacherStepThreeData::fromArray($request->validated());

        return $this->saved($action->handle($this->application($request), 3, [
            'documents_acknowledged' => $data->documentsAcknowledged,
        ]));
    }

    public function stepFour(TeacherStepFourRequest $request, SaveTeacherApplicationStep $action): JsonResponse
    {
        $data = TeacherStepFourData::fromArray($request->validated());

        return $this->saved($action->handle($this->application($request), 4, $data->toStepData()));
    }

    public function submit(Request $request, SubmitTeacherApplication $action): JsonResponse
    {
        $application = $action->handle($this->application($request));

        return response()->json([
            'status' => $application->status,
            'message' => 'طلبك قيد المراجعة من فريقنا الأكاديمي',
            'expected_review_days' => (int) config('marketplace.review_days'),
        ]);
    }

    /**
     * The caller's own application.
     *
     * Resolved from the authenticated user rather than a route parameter, so there
     * is no id to tamper with and no policy needed to stop someone editing another
     * applicant's answers.
     */
    private function application(Request $request): TeacherApplication
    {
        $application = TeacherApplication::query()
            // Deliberate scope bypass (Constitution I): an applicant belongs to no
            // workspace, so WorkspaceContext resolves to null and the scope adds
            // nothing anyway — but saying so is better than relying on it. The
            // user_id filter is what makes this safe, and it is not optional.
            ->withoutWorkspaceScope()
            ->where('user_id', $this->currentUser($request)->getKey())
            ->first();

        if ($application === null) {
            throw new NotFoundHttpException('لا يوجد طلب تدريس لهذا الحساب.');
        }

        return $application;
    }

    private function saved(TeacherApplication $application): JsonResponse
    {
        return response()->json(['application' => $this->payload($application)]);
    }

    /** @return array<string, mixed> */
    private function payload(TeacherApplication $application): array
    {
        return [
            'uuid' => $application->uuid,
            'status' => $application->status,
            'current_step' => $application->current_step,
            'step_data' => $application->step_data ?? [],
            'rejection_reason' => $application->rejection_reason,
        ];
    }
}
