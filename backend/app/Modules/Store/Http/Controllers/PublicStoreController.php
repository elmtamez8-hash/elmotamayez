<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Store\Enums\StoreItemKind;
use App\Modules\Store\Http\Resources\PublicStoreItemDetailResource;
use App\Modules\Store\Http\Resources\PublicStoreItemResource;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The PUBLIC store: every listed teacher's products, for a guest or anybody
 * signed in (`/marketplace/store/*`, `throttle:public`, no auth).
 *
 * ⛔ EVERY READ STARTS FROM `StoreItem::publiclyListed()` — on sale, the
 * teacher's profile listed and approved, the workspace in the marketplace — and
 * every relation is loaded with the workspace scope DROPPED. The victim of a
 * scoped read here is not the guest (the scope is inert for them) but a
 * signed-in student from another workspace, whose context would AND their own
 * workspace onto every query (docs/gotchas/tenancy.md).
 *
 * ⚠️ THE BUY DOOR (`POST /store/purchases`) ASKS `is_active` ALONE, deliberately
 * wider than this screen: a uuid is not enumerable and a sale is not a leak, and
 * a student who bought from their teacher's in-app list (an unlisted teacher)
 * must still be able to.
 */
class PublicStoreController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'teacher' => ['nullable', 'uuid'],
            'subject' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(StoreItemKind::class)],
            'course' => ['nullable', 'uuid'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
        ]);

        $items = $this->listed()
            ->when($filters['teacher'] ?? null, fn (Builder $query, string $uuid) => $query->whereIn(
                'teacher_profile_id',
                TeacherProfile::query()->withoutGlobalScopes()->select('id')->where('uuid', $uuid),
            ))
            ->when($filters['subject'] ?? null, fn (Builder $query, string $slug) => $query->whereIn(
                'subject_id',
                Subject::query()->select('id')->where('slug', $slug),
            ))
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['course'] ?? null, fn (Builder $query, string $uuid) => $query->whereIn(
                'course_id',
                fn ($sub) => $sub->select('id')->from('courses')->where('uuid', $uuid),
            ));

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $items->orderBy('price_minor')->orderByDesc('id'),
            'price_desc' => $items->orderByDesc('price_minor')->orderByDesc('id'),
            default => $items->orderByDesc('id'),
        };

        return PublicStoreItemResource::collection($items->paginate(24)->withQueryString());
    }

    public function show(string $item): PublicStoreItemDetailResource
    {
        // A string, resolved here — never route-model binding on a public route.
        $found = $this->listed()->where('store_items.uuid', $item)->first();

        abort_if($found === null, 404);

        return new PublicStoreItemDetailResource($found);
    }

    /**
     * What the filter bar offers: only teachers and subjects that HAVE a listed
     * product, so no choice leads to an empty page.
     */
    public function facets(): JsonResponse
    {
        $listed = StoreItem::query()->publiclyListed()->select('store_items.id');

        $teachers = TeacherProfile::query()
            ->withoutGlobalScopes()
            ->whereIn('id', StoreItem::query()->publiclyListed()->select('store_items.teacher_profile_id'))
            ->with('user:id,first_name,last_name')
            ->get()
            ->map(fn (TeacherProfile $profile): array => ['uuid' => $profile->uuid, 'name' => $profile->user?->name])
            ->sortBy('name')
            ->values();

        $subjects = Subject::query()
            ->whereIn('id', StoreItem::query()->publiclyListed()->whereNotNull('subject_id')->select('store_items.subject_id'))
            ->get()
            ->map(fn (Subject $subject): array => ['slug' => $subject->slug, 'name' => $subject->getAttribute('name')])
            ->values();

        return response()->json([
            'teachers' => $teachers,
            'subjects' => $subjects,
            'total' => $listed->count(),
        ]);
    }

    /** @return Builder<StoreItem> */
    private function listed(): Builder
    {
        return StoreItem::query()
            ->publiclyListed()
            ->with([
                'teacherProfile' => fn ($query) => $query->withoutGlobalScopes()->with('user:id,first_name,last_name'),
                'subject',
                // The course link only when its own public page exists.
                'course' => fn ($query) => $query->publiclyListed()->select(['courses.id', 'courses.uuid', 'courses.title', 'courses.slug']),
            ]);
    }
}
