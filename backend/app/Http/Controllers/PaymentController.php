<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }

    public function store(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        $data = Validator::make(['input' => $request->all()], ['input' => ['required', 'array:provider'], 'input.provider' => ['required', 'string', 'max:50']])->validate();
        $payment = $payments->create($request->user(), $this->id($order), $data['input']['provider'], (string) $request->header('Idempotency-Key', ''));

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    public function simulate(Request $request, string $payment, PaymentService $payments): PaymentResource
    {
        $data = Validator::make(['input' => $request->all()], ['input' => ['required', 'array:result'], 'input.result' => ['required', 'in:success,failure']])->validate();

        return new PaymentResource($payments->simulate($request->user(), $this->id($payment), $data['input']['result']));
    }

    public function keys(Request $request, string $order, PaymentService $payments): JsonResponse
    {
        return response()->json(['data' => $payments->keys($request->user(), $this->id($order))])
            ->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache')->header('Expires', '0');
    }
}
