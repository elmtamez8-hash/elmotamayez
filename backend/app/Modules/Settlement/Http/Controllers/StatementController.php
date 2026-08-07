<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\BuildTeacherStatement;
use App\Modules\Settlement\Actions\ExportTeacherStatement;
use App\Modules\Settlement\Http\Controllers\Concerns\ResolvesOwnTeacher;
use App\Modules\Settlement\Http\Resources\TeacherStatementResource;
use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The teacher's own contract, and there is no way to ask for anyone else's.
 *
 * There is deliberately no `teacher` parameter anywhere here (FR-019). A
 * parameter would make "may I read this teacher?" a question the controller has
 * to answer correctly every time; resolving the profile from the bearer token
 * makes it a question nobody can ask. That is the same reasoning that keeps a
 * public route from binding a model implicitly.
 */
class StatementController extends Controller
{
    use ResolvesOwnTeacher;

    public function show(Request $request, BuildTeacherStatement $action): JsonResponse
    {
        $this->authorize('viewAny', TeachingUnit::class);

        $statement = $action->handle($this->ownProfile($request));

        return response()->json(TeacherStatementResource::make($statement));
    }

    /**
     * The same statement as a file (FR-021).
     *
     * `streamDownload` rather than a plain response for the Content-Disposition
     * it sets — the file itself is built in memory by the Action, which streams
     * the ROWS it reads (`lazy()`) but returns the finished CSV. Ten thousand
     * unit lines is on the order of half a megabyte; the models behind them were
     * the allocation worth avoiding, and that one is.
     */
    public function export(Request $request, ExportTeacherStatement $action): StreamedResponse
    {
        $this->authorize('viewAny', TeachingUnit::class);

        $csv = $action->handle($this->ownProfile($request));

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            'settlement-statement.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** A reader with no teacher profile has no statement — not somebody else's. */
    private function ownProfile(Request $request): TeacherProfile
    {
        $profile = $this->ownTeacherProfile($request);

        abort_if($profile === null, 404);

        return $profile;
    }
}
