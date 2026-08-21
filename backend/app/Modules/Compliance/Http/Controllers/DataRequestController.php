<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Http\Requests\StoreDataRequestRequest;
use App\Modules\Compliance\Http\Resources\DataRequestResource;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The person's own data requests, and the archive one of them produced.
 *
 * ⚠️ THE DOWNLOAD IS A `302`, AND THE PATH NEVER TRAVELS IN JSON. Same rule as
 * `PlaybackGrantResource`: a path in a payload is a link that gets copied into a
 * ticket and kept, and this one addresses everything the platform knows about one
 * person. The signature is minted at the moment the button is pressed and is dead
 * five minutes later.
 *
 * ⚠️ AND THE STREAMING ROUTE CARRIES NO `auth:sanctum`, WHICH IS DELIBERATE AND
 * IS THE SHIPPED PRECEDENT. A browser following a redirect to another origin does
 * not forward an `Authorization` header — the same fact that put `/playback/{grant}`
 * behind a grant rather than a token. So the signature IS the credential there,
 * which is exactly the "signed, short-lived, not shareable afterwards" link FR-018
 * asks for. The request's own `export_expires_at` is re-read inside the stream, so
 * a signature outliving the archive opens nothing.
 */
class DataRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->currentUser($request);

        /*
        | ⚠️ FILTERED EXPLICITLY, NEVER LEFT TO A SCOPE. `data_requests` is
        | platform-owned and carries no `workspace_id`, and a student is a member of
        | no workspace — so `WorkspaceScope` adds no condition at all on this route.
        | An unfiltered list here is every rights request on the platform.
        */
        $requests = DataRequest::query()
            ->where(function ($query) use ($user): void {
                $query->where('subject_user_id', $user->getKey())
                    ->orWhere('requested_by_user_id', $user->getKey());
            })
            ->latest('id')
            ->limit(50)
            ->get();

        return DataRequestResource::collection($requests);
    }

    public function store(StoreDataRequestRequest $request, CreateDataRequest $action): JsonResponse
    {
        $this->authorize('create', DataRequest::class);

        $dataRequest = $action->handle(
            $this->currentUser($request),
            $request->subjectUuid($this->currentUser($request)),
            $request->type(),
        );

        /*
        | ⚠️ AN ERASURE IS **NOT** DISPATCHED HERE, AND THAT IS THE WHOLE SHAPE OF
        | FR-019. The right is to ASK with an announced execution period — not to
        | have a minor's entire record destroyed a few seconds after a button press,
        | irreversibly, with nobody having looked. It waits in the officer's queue
        | until `POST /manage/compliance/requests/{request}/execute` runs it, which
        | is also what writes `executed_by_user_id` (FR-026): an erasure nobody
        | performed is an audit line nobody can answer for.
        |
        | An access or an export carries no such weight — it reads and hands back
        | what the person already owns — so it goes to the queue immediately.
        |
        | Dispatched by ID. The job takes nothing else — see its docblock: Laravel
        | serialises constructor arguments into Redis and into `failed_jobs`.
        */
        if ($dataRequest->type !== DataRequestType::Erasure) {
            FulfilDataRequestJob::dispatch((int) $dataRequest->getKey());
        }

        return DataRequestResource::make($dataRequest)
            ->response()
            ->setStatusCode(201);
    }

    public function download(Request $request, DataRequest $dataRequest): RedirectResponse
    {
        $this->authorize('download', $dataRequest);

        return redirect()->away(URL::temporarySignedRoute(
            'compliance.exports.stream',
            now()->addMinutes(5),
            ['dataRequest' => $dataRequest->uuid],
        ));
    }

    /**
     * The bytes. Reached only through the signature minted by {@see self::download()}.
     */
    public function stream(DataRequest $dataRequest): StreamedResponse
    {
        // Re-read rather than trusted from the signature: the cleaner runs nightly,
        // and a five-minute signature can outlive the file it was minted for.
        abort_unless($dataRequest->isDownloadable(), 404);

        $disk = Storage::disk(ComplianceSettings::exportDisk());
        $path = (string) $dataRequest->export_path;

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, 'my-data-'.$dataRequest->uuid.'.zip');
    }
}
