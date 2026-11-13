<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameSearchController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\PaymentController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('SELECT 1');

    return response()->json(['status' => 'ok', 'database' => 'connected']);
});

Route::get('/offers', OfferController::class);

Route::get('/games', [GameController::class, 'index']);

Route::get('/games/search', GameSearchController::class);

Route::get('/games/{game}', [GameController::class, 'show'])->whereNumber('game');

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store'])->whereNumber('order');
    Route::post('/payments/{payment}/simulate', [PaymentController::class, 'simulate'])->whereNumber('payment');
    Route::get('/orders/{order}/keys', [PaymentController::class, 'keys'])->whereNumber('order');
    Route::post('/checkout', [CheckoutController::class, 'store']);
    Route::get('/orders/{order}', [CheckoutController::class, 'show'])->whereNumber('order');
    Route::post('/orders/{order}/cancel', [CheckoutController::class, 'cancel'])->whereNumber('order');
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
