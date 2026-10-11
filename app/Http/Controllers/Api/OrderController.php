<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CancelBuyOrderService;
use App\Services\PlaceBuyOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, PlaceBuyOrderService $service): JsonResponse
    {
        try {
            $order = $service->place((int) $request->user()->id, $request->validated());

            return (new OrderResource($order->load('asset', 'executions')))
                ->additional(['message' => 'Order accepted.'])
                ->response()->setStatusCode(202);
        } catch (RuntimeException $exception) {
            $status = str_contains(strtolower($exception->getMessage()), 'idempotency key') ? 409 : 422;

            return response()->json(['error' => ['code' => $status === 409 ? 'idempotency_conflict' : 'order_rejected', 'message' => $exception->getMessage()]], $status);
        }
    }

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in(array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()))],
            'asset_id' => ['sometimes', 'integer', 'exists:assets,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'The supplied filters are invalid.', 'details' => $validator->errors()]], 422);
        }

        $query = Order::query()->where('user_id', $request->user()->id)->with('asset', 'executions')->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->query('asset_id'));
        }

        return OrderResource::collection($query->cursorPaginate((int) $request->query('per_page', 20)));
    }

    public function show(Request $request, Order $order): OrderResource|JsonResponse
    {
        if (! $request->user()->can('view', $order)) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Order not found.']], 404);
        }

        return new OrderResource($order->load('asset', 'executions'));
    }

    public function destroy(Request $request, int $order, CancelBuyOrderService $service): OrderResource|JsonResponse
    {
        $model = Order::query()->find($order);
        if (! $model || ! $request->user()->can('cancel', $model)) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Order not found.']], 404);
        }
        try {
            $cancelled = $service->cancel($order, (int) $request->user()->id);

            return (new OrderResource($cancelled->load('asset', 'executions')))->additional(['message' => 'Order cancelled successfully.']);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Order not found.']], 404);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'order_transition_rejected', 'message' => $exception->getMessage()]], 409);
        }
    }
}
