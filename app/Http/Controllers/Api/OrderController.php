<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\CancelBuyOrderService;
use App\Services\PlaceBuyOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class OrderController extends Controller
{
    public function store(
        StoreOrderRequest $request,
        PlaceBuyOrderService $service
    ): JsonResponse {
        try {
            $order = $service->place(
                (int) $request->user()->id,
                $request->validated()
            );

            return response()->json([
                'message' => 'Order placed successfully.',
                'data' => $order->fresh(),
            ], 201);
        } catch (RuntimeException $exception) {
            $status = str_contains(
                $exception->getMessage(),
                'Idempotency key'
            ) ? 409 : 422;

            return response()->json([
                'message' => $exception->getMessage(),
            ], $status);
        }
    }

    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->paginate(20);

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        abort_unless($order->user_id === auth()->id(), 404);

        return response()->json([
            'data' => $order->load('asset', 'execution'),
        ]);
    }

    public function destroy(
        int $order,
        CancelBuyOrderService $service
    ): JsonResponse {
        try {
            $cancelledOrder = $service->cancel(
                $order,
                (int) auth()->id()
            );

            return response()->json([
                'message' => 'Order cancelled successfully.',
                'data' => $cancelledOrder->fresh(),
            ]);
        } catch (ModelNotFoundException $exception) {
            abort(404);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
