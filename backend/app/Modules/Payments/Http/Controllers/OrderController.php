<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\RejectOrder;
use App\Modules\Payments\Actions\UploadPaymentReceipt;
use App\Modules\Payments\Http\Resources\OrderResource;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::query();

        // Students see only their orders; staff with view-all see all.
        if (! $this->currentUser($request)->can(Permissions::ORDERS_VIEW_ALL)) {
            $query->where('user_id', $this->currentUser($request)->getKey());
        }

        $orders = $query->with(['course', 'media'])->orderByDesc('created_at')->paginate(15);

        return response()->json(OrderResource::collection($orders));
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

        $request->validate([
            'receipt' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf'],
        ]);

        $action->handle($order, $request->file('receipt'), $this->currentUser($request));

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
            $order = $action->handle($order, $this->currentUser($request));
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
            $order = $action->handle($order, $this->currentUser($request), $request->input('reason'));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(OrderResource::make($order));
    }
}
