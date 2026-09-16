<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\User\AddressController;
use App\Http\Controllers\Api\User\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\User\PaymentMethodController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;




Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::middleware('throttle:6,1')->prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});


Route::prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/callback', [GoogleAuthController::class, 'callback']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');
});

Route::middleware('auth:sanctum')->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::post('/', [ProfileController::class, 'update']);
    Route::put('/password', [ProfileController::class, 'updatePassword']);
});

Route::middleware('auth:sanctum')->prefix('addresses')->group(function () {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::put('/{address}', [AddressController::class, 'update']);
    Route::delete('/{address}', [AddressController::class, 'destroy']);
});
Route::middleware('auth:sanctum')->prefix('payment-methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::post('/', [PaymentMethodController::class, 'store']);
    Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
});
Route::get('/categories', [CategoryController::class, 'index']);
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('categories')->group(function () {
    Route::post('/', [CategoryController::class, 'store']);
    Route::put('/{category}', [CategoryController::class, 'update']);
    Route::delete('/{category}', [CategoryController::class, 'destroy']);
});

Route::get('/products/search', [ProductController::class, 'search']);
Route::get('/products/filter', [ProductController::class, 'filter']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::middleware(['auth:sanctum', 'role:admin,seller'])->prefix('products')->group(function () {
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{product}', [ProductController::class, 'update']);
    Route::patch('/{product}/status', [ProductController::class, 'updateStatus']);
    Route::delete('/{product}', [ProductController::class, 'destroy']);
});