<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\CheckoutController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/v1/products/lookup/{barcode}', [ProductController::class, 'lookup']);
Route::get('/v1/products', [ProductController::class, 'index']);
Route::post('/v1/products', [ProductController::class, 'store']);
Route::post('/v1/products/{product}/restock', [ProductController::class, 'restock']);
Route::post('/v1/webhooks/mpesa', [WebhookController::class, 'handleMpesaWebhook']);
Route::post('/v1/checkout', [CheckoutController::class, 'processCheckout']);
Route::get('/v1/payments/{payment}/status', [CheckoutController::class, 'checkStatus']);
