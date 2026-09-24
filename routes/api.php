<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\User\AddressController;
use App\Http\Controllers\Api\User\PaymentMethodController;
use App\Http\Controllers\Api\User\ProfileController;
use App\Http\Controllers\Api\User\WishlistController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Catalog\ReviewController;
use App\Http\Controllers\Api\Cart\CartController;
use App\Http\Controllers\Api\Order\OrderController;
use App\Http\Controllers\Api\Payment\PaymentController;
use App\Http\Controllers\Api\Payment\WebhookController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminCouponController;
use App\Http\Controllers\Api\Admin\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Auth (Register / Login / Password)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:6,1')->prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| Google Social Login
|--------------------------------------------------------------------------
*/
Route::prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/callback', [GoogleAuthController::class, 'callback']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Auth Actions (Logout / Email Verification)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');
});

/*
|--------------------------------------------------------------------------
| User Profile
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::post('/', [ProfileController::class, 'update']);
    Route::put('/password', [ProfileController::class, 'updatePassword']);
});

/*
|--------------------------------------------------------------------------
| User Addresses
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('addresses')->group(function () {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::put('/{address}', [AddressController::class, 'update']);
    Route::delete('/{address}', [AddressController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| User Payment Methods (Saved Cards)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('payment-methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index']);
    Route::post('/', [PaymentMethodController::class, 'store']);
    Route::delete('/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
    Route::post('/setup-intent', [PaymentMethodController::class, 'createSetupIntent']);
});

/*
|--------------------------------------------------------------------------
| User Wishlist
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('wishlist')->group(function () {
    Route::get('/', [WishlistController::class, 'index']);
    Route::post('/', [WishlistController::class, 'store']);
    Route::delete('/{product}', [WishlistController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/
Route::get('/categories', [CategoryController::class, 'index']);

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('categories')->group(function () {
    Route::post('/', [CategoryController::class, 'store']);
    Route::put('/{category}', [CategoryController::class, 'update']);
    Route::delete('/{category}', [CategoryController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Products (Public: browse/search/filter, Admin+Seller: manage)
|--------------------------------------------------------------------------
*/
Route::get('/products/search', [ProductController::class, 'search']);
Route::get('/products/filter', [ProductController::class, 'filter']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

Route::middleware(['auth:sanctum', 'role:admin,seller'])->prefix('products')->group(function () {
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{product}', [ProductController::class, 'update']);
    Route::patch('/{product}/status', [ProductController::class, 'updateStatus']);
    Route::delete('/{product}', [ProductController::class, 'destroy']);
    Route::post('/{product}/images', [ProductController::class, 'storeImage']);
    Route::delete('/{product}/images/{image}', [ProductController::class, 'destroyImage']);
});

/*
|--------------------------------------------------------------------------
| Product Reviews
|--------------------------------------------------------------------------
*/
Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/products/{product}/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
*/
Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::get('/summary', [CartController::class, 'summary']);
    Route::post('/items', [CartController::class, 'store']);
    Route::put('/items/{item}', [CartController::class, 'update']);
    Route::delete('/items/{item}', [CartController::class, 'destroy']);
    Route::post('/coupon', [CartController::class, 'applyCoupon']);
    Route::delete('/coupon', [CartController::class, 'removeCoupon']);
});

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
*/
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/{order}/reorder', [OrderController::class, 'reorder']);
});

Route::middleware(['auth:sanctum', 'role:admin,seller'])->group(function () {
    Route::patch('/order-items/{item}/status', [OrderController::class, 'updateItemStatus']);
});

/*
|--------------------------------------------------------------------------
| Payments & Webhooks
|--------------------------------------------------------------------------
*/
Route::post('/orders/{order}/pay', [PaymentController::class, 'pay']);

Route::middleware(['auth:sanctum', 'role:admin'])
    ->post('/orders/{order}/confirm-cash-payment', [PaymentController::class, 'confirmCashPayment']);

Route::post('/webhooks/stripe', [WebhookController::class, 'stripe']);
Route::post('/webhooks/paypal', [WebhookController::class, 'paypal']);
Route::post('/webhooks/razorpay', [WebhookController::class, 'razorpay']);

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/wallet')->group(function () {
    Route::post('/{user}/top-up', [WalletController::class, 'topUp']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/users')->group(function () {
    Route::get('/', [AdminUserController::class, 'index']);
    Route::get('/{user}', [AdminUserController::class, 'show']);
    Route::patch('/{user}/suspend', [AdminUserController::class, 'suspend']);
    Route::patch('/{user}/activate', [AdminUserController::class, 'activate']);
    Route::delete('/{user}', [AdminUserController::class, 'destroy']);
});

// ---- #32 Admin: manage products & categories ----
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/products')->group(function () {
    Route::get('/', [AdminProductController::class, 'index']);
    Route::patch('/bulk-status', [AdminProductController::class, 'bulkUpdateStatus']);
});

// ---- #33 Admin: manage orders & shipping ----
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/orders')->group(function () {
    Route::get('/', [AdminOrderController::class, 'index']);
    Route::get('/{order}', [AdminOrderController::class, 'show']);
    Route::put('/{order}/shipping', [AdminOrderController::class, 'updateShipping']);
});
// ---- #34 Admin: promo code management ----
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/coupons')->group(function () {
    Route::get('/', [AdminCouponController::class, 'index']);
    Route::post('/', [AdminCouponController::class, 'store']);
    Route::put('/{coupon}', [AdminCouponController::class, 'update']);
    Route::patch('/{coupon}/deactivate', [AdminCouponController::class, 'deactivate']);
    Route::get('/{coupon}/stats', [AdminCouponController::class, 'stats']);
});