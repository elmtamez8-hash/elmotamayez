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

        // Students see only their orders; staff with view-all see all.
        if (! $user->can(Permissions::ORDERS_VIEW_ALL)) {
            $query->where('user_id', $user->getKey());
        } elseif (! $user->can(Permissions::BILLING_PURCHASE_APPROVE)) {
            /*
            | ⚠️ THE SAME CUT AS `OrderPolicy::view()`, HERE BECAUSE A LIST TAKES
            | NO POLICY. A credit purchase is a sale between the student and the
            | platform (Q-4), so a teacher holding ORDERS_VIEW_ALL sees every
            | course order in their workspace and none of the platform's sales —
            | except any they made themselves as a buyer.
            */
            $query->where(fn (Builder $rows) => $rows
                ->where('kind', '!=', OrderKind::Credits->value)
                ->orWhere('user_id', $user->getKey()));
        }

        $orders = $query->with(['course', 'media'])->orderByDesc('created_at')->paginate(15);

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

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json(OrderResource::make($order->load(['course', 'media'])));
    }

    public function store(Request $request, Course $course, CreateOrder $action): JsonResponse
    {
        $this->authorize('create', Order::class);

        if (! $course->isPublished()) {
            return response()->json(['message' => 'Course is not available.'], 422);
        }

        if ($course->isFree()) {
            return response()->json(['message' => 'This course is free; no order needed.'], 422);
        }

        $order = $action->handle($course, $this->currentUser($request));

        return response()->json(OrderResource::make($order), 201);
    }

    public function uploadReceipt(Request $request, Order $order, UploadPaymentReceipt $action): JsonResponse
    {
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
        $media = $order->getFirstMedia('receipt');

        abort_if($media === null, 404);

        return $media->toInlineResponse(request());
    }

    public function approve(Request $request, Order $order, ApproveOrder $action): JsonResponse
    {
        $this->authorize('approve', $order);

        try {
            $order = $action->handle($order, $this->currentUser($request), $request->ip(), $request->userAgent());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(OrderResource::make($order));
    }

    public function reject(Request $request, Order $order, RejectOrder $action): JsonResponse
    {
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
}
