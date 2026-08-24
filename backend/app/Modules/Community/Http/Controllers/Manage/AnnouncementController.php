<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\CreateAnnouncement;
use App\Modules\Community\Actions\HideAnnouncement;
use App\Modules\Community\Actions\PublishAnnouncement;
use App\Modules\Community\Actions\ReadAnnouncementStats;
use App\Modules\Community\Actions\UpdateAnnouncement;
use App\Modules\Community\Data\AnnouncementData;
use App\Modules\Community\Http\Requests\SaveAnnouncementRequest;
use App\Modules\Community\Http\Resources\AnnouncementResource;
use App\Modules\Community\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The publisher's side of an announcement (FR-042 · FR-046 · FR-047).
 *
 * ⚠️ THERE IS NO REPLY ROUTE ANYWHERE, AND THE ABSENCE IS THE REQUIREMENT
 * (FR-045). A reply endpoint on a message sent to three hundred people is a
 * three-hundred-way thread with no moderation surface and no read model — and the
 * product already has the right place for an answer, which is the private
 * conversation the student can open with one tap. Nothing here creates one.
 *
 * ⚠️ AND `{announcement}` IS AN IMPLICIT BINDING, taking the exemption the
 * `{assignment}` and `{review}` routes document: `announcements` is
 * workspace-scoped and everybody who reaches these routes is a workspace MEMBER,
 * so another teacher's uuid 404s before the policy runs. Nothing a student can
 * reach may copy it — and nothing a student can reach exists here at all, because
 * the notification centre is the recipient's whole surface.
 */
class AnnouncementController extends Controller
{
    public function __construct(private readonly ReadAnnouncementStats $stats) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage', Announcement::class);

        $announcements = Announcement::query()
            ->with('author:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();

        return AnnouncementResource::collection($this->withStats($announcements));
    }

    public function store(SaveAnnouncementRequest $request, CreateAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', Announcement::class);

        $announcement = $action->handle(
            $this->currentUser($request),
            AnnouncementData::fromArray($request->validated()),
        );

        return response()->json(
            AnnouncementResource::make($this->withStats(collect([$announcement]))->first()),
            201,
        );
    }

    /**
     * Publishing is idempotent by a conditional UPDATE inside the Action, so a
     * second press answers 200 with the same row and starts no second fan-out.
     */
    public function publish(Request $request, Announcement $announcement, PublishAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', Announcement::class);

        return response()->json(
            AnnouncementResource::make($this->withStats(collect([$action->handle($announcement)]))->first()),
        );
    }

    public function update(SaveAnnouncementRequest $request, Announcement $announcement, UpdateAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', Announcement::class);

        $data = AnnouncementData::fromArray($request->validated());

        return response()->json(
            AnnouncementResource::make(
                $this->withStats(collect([$action->handle($announcement, $data->body, $data->isUrgent)]))->first(),
            ),
        );
    }

    public function destroy(Request $request, Announcement $announcement, HideAnnouncement $action): JsonResponse
    {
        $this->authorize('manage', Announcement::class);

        return response()->json(
            AnnouncementResource::make($this->withStats(collect([$action->handle($announcement)]))->first()),
        );
    }

    /**
     * ⚠️ ONE GROUPED QUERY FOR THE PAGE, NEVER A COUNT INSIDE THE RESOURCE. A
     * Resource runs once per row, so counting there is an N+1 by construction —
     * twice over, since the screen shows both numbers.
     *
     * @param  Collection<int, Announcement>  $announcements
     * @return Collection<int, Announcement>
     */
    private function withStats(Collection $announcements): Collection
    {
        $stats = $this->stats->forMany($announcements);

        return $announcements->each(function (Announcement $announcement) use ($stats): void {
            $announcement->setAttribute('stats', $stats[(int) $announcement->getKey()] ?? ['notified' => 0, 'read' => 0]);
        });
    }
}
