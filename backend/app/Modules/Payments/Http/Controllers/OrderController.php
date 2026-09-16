<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Http\Resources\OrderResource;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->currentUser($request);
        $query = Order::query();

        /*
        | Students see only their orders; staff with view-all see all.
        |
        | ⚠️ **و«طلبي» تشملُ ما أنشأتُه لابني.** وليُّ الأمرِ يشتري باسمِ الطالب
        | (`user_id` = الابن · `granted_by` = هو)، فمُرشِّحٌ على العمودِ الأوّلِ وحدَه
        | يُخفي عنه الطلبَ الذي دفعَه للتوّ — لا شاشةَ يرفعُ فيها الإيصالَ ولا موضعَ
        | يقرأُ فيه القرار. وهي بعينُها القائمةُ التي شكا منها المستخدِمُ لأنّ الطلبَ
        | ظهرَ فيها باسمِه هو.
        */
        if (! $user->can(Permissions::ORDERS_VIEW_ALL)) {
            /*
            | ⛔ **وبلا نطاقِ مساحةِ العمل، وإلّا اختفى من المشتري طلبُه هو.**
            | `Order` مملوكٌ لمساحةِ عمل، و`WorkspaceContext::id()` يرتدُّ إلى
            | `users.last_workspace_id` — وهو مختومٌ على كلِّ طالبٍ أضافَه مدرّسٌ
            | أو دعوةٌ أو باذِر. فطالبٌ مختومٌ بمساحةِ مدرّسٍ ثمّ اشترى من مدرّسٍ
            | آخرَ كانَ يفتحُ «طلباتي» فيقرأُ «لا طلبات في سجلّك» — قائمةٌ فارغةٌ
            | بلا خطأٍ واحد، ومعها يختفي زرُّ رفعِ الإيصالِ وموضعُ قراءةِ القرار.
            |
            | قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦ بمشيٍ حقيقيّ: طلبٌ #٩ في مساحةِ العملِ
            | ٥ باسمِ طالبٍ مختومٍ بـ١ — الاستعلامُ نفسُه يردُّ **صفراً** عبرَ HTTP
            | و**واحداً** في الطرفيّةِ حيثُ السياقُ فارغٌ فالنطاقُ خامل.
            |
            | ⚠️ **والحارسُ هو المُرشِّحُ تحتَه لا النطاق**: «طلبي» = `user_id` أو
            | `granted_by`، وكلاهما أضيقُ من أيِّ مساحةِ عمل. وهي عائلةُ عطبِ
            | ٠٣٢ نفسِها — قراءةٌ يواجهُها طالبٌ لا يجوزُ أن تمرَّ بالنطاق.
            */
            $query->withoutWorkspaceScope()
                ->where(fn (Builder $mine) => $mine
                    ->where('user_id', $user->getKey())
                    ->orWhere('granted_by', $user->getKey()));
        } elseif (! $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            /*
            | ⚠️ THE SAME CUT AS `OrderPolicy::view()`, HERE BECAUSE A LIST TAKES
            | NO POLICY. A credit purchase is a sale between the student and the
            | platform (Q-4), so a teacher holding ORDERS_VIEW_ALL sees every
            | course order in their workspace and none of the platform's sales —
            | except any they made themselves as a buyer.
            |
            | ⚠️ AND IT IS AN ALLOWLIST NOW. Written `!= Credits` it was a denylist
            | over an enum of two, so spec 011's `store` and `subscription` would
            | have appeared here the day they were added — silently, because
            | nothing about `!=` says what it meant to keep out.
            */
            $query->where(fn (Builder $rows) => $rows
                ->whereIn('kind', OrderKind::teacherListedValues())
                ->orWhere('user_id', $user->getKey()));
        }

        /*
        | ⚠️ **وموظّفُ المنصّةِ كذلك، وهو الفرعُ الثالثُ بلا مُرشِّحٍ فوقَه.**
        | سياقُه يرتدُّ إلى مساحتِه هو تماماً كأيِّ حساب، فطابورٌ مُنطَّقٌ يعرضُ
        | طلباتِ مساحةٍ واحدةٍ ويُسمّيها المنصّة — وهي الطبقةُ التي لا تُصدِرُ رمزَ
        | حالةٍ أصلاً: قائمةٌ قصيرةٌ تُقرَأُ أسبوعاً هادئاً. أمّا فرعُ المدرّسِ
        | فيبقى مُنطَّقاً: هناك النطاقُ **هو** الحارس.
        */
        if ($user->can(Permissions::ORDERS_VIEW_ALL) && $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            $query->withoutWorkspaceScope();
        }

        $orders = $query
            ->with([
                'course', 'media', 'user', 'grantor',
                // ⚠️ THE SCOPE BYPASS IS THE WHOLE POINT — see Order::creditPurchase().
                // A finance officer's context falls back to their own workspace, so a
                // scoped relation answers null for every order they are there to decide.
                'creditPurchase' => fn ($rows) => $rows->withoutWorkspaceScope(),
            ])
            ->orderByDesc('created_at')
            ->paginate(15);

        /*
        | ⚠️ RETURNED, NOT `response()->json(...)`-ED, AND THE PAGE DEPENDED ON IT.
        | Wrapped in `json()` the collection serialises to a BARE ARRAY — the
        | `data`/`links`/`meta` envelope is added by the resource's own
        | `toResponse()`, which `json()` never calls. `orders/page.tsx` reads
        | `res.data`, so every order list on the product came back undefined and
        | rendered "لا طلبات في سجلّك" to people who had orders.
        */
        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $orderUuid): JsonResponse
    {
        $order = $this->orderByUuid($orderUuid);

        $this->authorize('view', $order);

        return response()->json(OrderResource::make($order->load([
            'course', 'media', 'user', 'grantor',
            'creditPurchase' => fn ($rows) => $rows->withoutWorkspaceScope(),
        ])));
    }

    public function store(Request $request, string $courseUuid, CreateOrder $action): JsonResponse
    {
        /*
        | ⛔ **وهذا البابُ أيضاً، من جهةِ الطالبِ لا الموظَّف.** التعليقُ فوقَ
        | المسارِ يشرحُ لماذا صارَت الأربعةُ الأخرى `{orderUuid}`: موظَّفُ المنصّةِ
        | يسقطُ سياقُه إلى `users.last_workspace_id`. والعمودُ نفسُه **مختومٌ لكلِّ
        | طالبٍ أُضيفَ يوماً إلى مساحةِ عمل**، فالشراءُ من مدرّسٍ ثانٍ كانَ يُجابُ
        | ٤٠٤ — والسوقُ كلُّه قائمٌ على أنّ الطالبَ يشتري من أيِّ مدرّس.
        |
        | ⚠️ ولا توسيعَ: `OrderPolicy::create()` صلاحيّةٌ بلا سؤالِ مساحة،
        | و`isPublished()` أدناه هو الحارسُ الذي كانَ ويبقى.
        */
        $course = Course::query()->withoutWorkspaceScope()->where('uuid', $courseUuid)->firstOrFail();

        $this->authorize('create', Order::class);

        if (! $course->isPublished()) {
            return response()->json(['message' => 'Course is not available.'], 422);
        }

        if ($course->isFree()) {
            return response()->json(['message' => 'This course is free; no order needed.'], 422);
        }

        // ⛔ A teacher never buys a course — not another teacher's and not their
        // own. The second door of three; the free one is
        // `EnrollmentController::enroll()` and the subscription is inside
        // `PurchaseSubscription`. Refused BEFORE the order exists: an order
        // created and then refused at fulfilment is money taken for a seat that
        // is never written.
        if ($this->currentUser($request)->teachesOnPlatform()) {
            return response()->json(['message' => 'هذا الحسابُ حسابُ مدرّسٍ على المنصّة، والمدرّسُ لا يشتركُ في الكورسات.'], 422);
        }

        $validated = $request->validate([
            'coupon_code' => ['nullable', 'string', 'max:32'],
        ]);

        $order = $action->handle(
            $course,
            $this->currentUser($request),
            $validated['coupon_code'] ?? null,
        );

        return response()->json(OrderResource::make($order), 201);
    }

    public function uploadReceipt(Request $request, string $orderUuid, UploadPaymentReceipt $action): JsonResponse
    {
        $order = $this->orderByUuid($orderUuid);

        $this->authorize('uploadReceipt', $order);

        $validated = $request->validate([
            'receipt' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf'],
            // Not required: every payer before this field existed made a bank
            // transfer, and a suddenly-required field would refuse the upload
            // from a client that has not shipped the input yet.
            'method' => ['sometimes', 'string', Rule::enum(PaymentMethod::class)],
        ]);

        try {
            $order = $action->handle(
                $order,
                $request->file('receipt'),
                $this->currentUser($request),
                PaymentMethod::tryFrom((string) ($validated['method'] ?? '')) ?? PaymentMethod::BankTransfer,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(OrderResource::make($order));
    }

    /**
     * Stream a receipt from the private disk.
     *
     * Reached by signature, not by bearer token: the client opens the URL in a new
     * tab with a plain anchor, which cannot carry an Authorization header. The
     * signature is minted in OrderResource only for a viewer who already passed
     * the `view` policy, and it expires — so the link is an authorisation that was
     * granted, not a path anyone can walk to.
     */
    public function downloadReceipt(Order $order): StreamedResponse
    {
        // ⚠️ THE LATEST. A rejected order may carry a replacement (FR-032) and the
        // collection appends — serving the first would hand the approver the very
        // image they refused, and record an approval against it.
        $media = $order->latestReceipt();

        abort_if($media === null, 404);

        return $media->toInlineResponse(request());
    }

    public function approve(Request $request, string $orderUuid, ApproveOrder $action): JsonResponse
    {
        $order = $this->orderByUuid($orderUuid);

        $this->authorize('approve', $order);

        // ٠٣٤ · FR-016ب — البابُ البرمجيُّ يحملُ الاختيارَ كما يحملُه الزرّ،
        // **والاشتراطُ في الفعلِ لا هنا**: حارسٌ على أحدِ البابَينِ يترُكُ الآخرَ
        // مفتوحاً على مصراعَيه.
        $request->validate(['cohort_uuid' => ['nullable', 'string', 'uuid']]);

        try {
            $order = $action->handle(
                $order,
                $this->currentUser($request),
                $request->ip(),
                $request->userAgent(),
                $request->string('cohort_uuid')->toString() ?: null,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(OrderResource::make($order));
    }

    public function reject(Request $request, string $orderUuid, RejectOrder $action): JsonResponse
    {
        $order = $this->orderByUuid($orderUuid);

        $this->authorize('reject', $order);

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $order = $action->handle(
                $order,
                $this->currentUser($request),
                $request->string('reason')->toString(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(OrderResource::make($order));
    }

    /**
     * ⚠️ RESOLVED WITHOUT THE WORKSPACE SCOPE — AND `OrderPolicy` IS THE GUARD.
     *
     * Implicit binding resolves through `BelongsToWorkspace`, so a row outside
     * the reader's current workspace 404s before any policy runs. Correct for a
     * member; a wall for a platform officer, whose context falls back to
     * `users.last_workspace_id` exactly like everybody else's. An officer who
     * also owns a workspace was answered 404 on every credit order outside it
     * while the policy stood ready to allow it — measured 2026-09-03, and
     * invisible until then because every fixture builds that officer with no
     * workspace at all, where a null context raises no objection.
     *
     * Nothing is widened by this: `OrderPolicy` asks the workspace question
     * itself on every non-platform branch, so a teacher reaching another
     * workspace's order is refused there instead of here. The visible change is
     * 404 → 403 for that probe on these four routes, and it is deliberate.
     */
    private function orderByUuid(string $uuid): Order
    {
        return Order::query()->withoutWorkspaceScope()->where('uuid', $uuid)->firstOrFail();
    }
}
