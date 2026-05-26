<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;

Route::post('/v1/login', [AuthController::class, 'login']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/v1/register', [AuthController::class, 'register']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/v1/register/send-code', [AuthController::class, 'sendVerificationCode']);
Route::post('/v1/register/verify-code', [AuthController::class, 'verifyCode']);
Route::post('/v1/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/v1/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/v1/products/lookup/{barcode}', [ProductController::class, 'lookup']);
Route::get('/v1/products', [ProductController::class, 'index']);
Route::post('/v1/products', [ProductController::class, 'store'])->middleware(['auth:sanctum', 'role:admin']);
Route::get('/v1/operators', [AuthController::class, 'listOperators'])->middleware(['auth:sanctum', 'role:admin']);
Route::post('/v1/operators', [AuthController::class, 'createOperator'])->middleware(['auth:sanctum', 'role:admin']);
Route::put('/v1/products/{product}', [ProductController::class, 'update']);
Route::post('/v1/products/{product}/restock', [ProductController::class, 'restock']);
Route::post('/v1/webhooks/mpesa', [WebhookController::class, 'handleMpesaWebhook']);
Route::post('/v1/checkout', [CheckoutController::class, 'processCheckout']);
Route::post('/v1/checkout/calculate', [CheckoutController::class, 'calculate']);
Route::get('/v1/payments/{payment}/status', [CheckoutController::class, 'checkStatus']);
Route::post('/v1/payments/{payment}/abort', [CheckoutController::class, 'abortMpesaPayment']);
Route::post('/v1/payments/{payment}/simulate-complete', [CheckoutController::class, 'simulateMpesaComplete']);
Route::get('/v1/orders', [CheckoutController::class, 'listOrders']);

Route::get('/v1/customers', [CustomerController::class, 'index']);
Route::post('/v1/customers', [CustomerController::class, 'store']);
Route::get('/v1/customers/{customer}', [CustomerController::class, 'show']);
Route::put('/v1/customers/{customer}', [CustomerController::class, 'update']);
Route::delete('/v1/customers/{customer}', [CustomerController::class, 'destroy']);
Route::get('/v1/customers/{customer}/orders', [CustomerController::class, 'orders']);

Route::get('/v1/promotions', [\App\Http\Controllers\DiscountRuleController::class, 'index']);
Route::post('/v1/promotions', [\App\Http\Controllers\DiscountRuleController::class, 'store']);
Route::put('/v1/promotions/{id}/toggle', [\App\Http\Controllers\DiscountRuleController::class, 'toggle']);
Route::delete('/v1/promotions/{id}', [\App\Http\Controllers\DiscountRuleController::class, 'destroy']);
