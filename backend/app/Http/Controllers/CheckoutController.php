<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function store(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:20']]);
        $order = $checkout->create($request->user(), $data['items'], (string) $request->header('Idempotency-Key', ''));

        return (new OrderResource($this->details($order)))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $order, CheckoutService $checkout): OrderResource
    {
        return new OrderResource($this->details($checkout->get($request->user(), $this->orderId($order))));
    }

    public function cancel(Request $request, string $order, CheckoutService $checkout): OrderResource
    {
        return new OrderResource($this->details($checkout->cancel($request->user(), $this->orderId($order))));
    }

    private function details(Order $order): Order
    {
        return $order->load(['items.offer.game:id,name,img', 'items.offer.seller:id,name',
            'payments' => fn ($query) => $query->orderByDesc('id')]);
    }

    private function orderId(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }
}
