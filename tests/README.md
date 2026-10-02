# GlowThera API

REST API for **GlowThera**, a multi-vendor cosmetics marketplace. Customers browse and buy products, sellers manage their own store and request payouts, and admins moderate the platform.

Built with **Laravel 10**, **Sanctum** token authentication and a full automated test suite (**507 tests**).

---

## Table of contents

1. [Features](#features)
2. [Tech stack](#tech-stack)
3. [Getting started](#getting-started)
4. [Environment variables](#environment-variables)
5. [Running the tests](#running-the-tests)
6. [API overview](#api-overview)
7. [Business rules](#business-rules)
8. [Security notes](#security-notes)
9. [Project structure](#project-structure)
10. [Test coverage](#test-coverage)
11. [Known decisions and open items](#known-decisions-and-open-items)

---

## Features

- **Authentication**: register and login with email or phone, Google social login, email verification, password reset. Every login or reset issues or revokes Sanctum tokens as appropriate.
- **Catalog**: categories (root and sub-category), products with images, search, filters, sorting, reviews limited to verified purchasers.
- **Cart and checkout**: guest carts (by `X-Session-Id`) that merge into the user's cart on login, register or Google login. Coupons, tiered discounts, flat shipping.
- **Payments**: Cash on delivery, wallet, Stripe, PayPal and Razorpay, with signed webhooks and idempotent payment recording.
- **Orders**: place, cancel (stock and coupon usage are restored), reorder, per-item status flow for sellers and admins, status emails.
- **Sellers**: store registration with admin approval, own product management, earnings, payout requests.
- **Admin**: users (suspend, activate, delete), products (bulk status), orders and shipping, coupons and statistics, banners, seller approval, payouts, newsletter.
- **Extras**: wishlist, loyalty points, saved payment methods, newsletter, queued promo notifications, Arabic and English responses.

## Tech stack

| Area | Choice |
|---|---|
| Language / framework | PHP 8.1+, Laravel 10 |
| Auth | Laravel Sanctum (API tokens), Laravel Socialite (Google) |
| Payments | Stripe SDK, PayPal and Razorpay over Guzzle |
| Database | MySQL (default) |
| Tests | PHPUnit 10, Mockery |
| Code style | Laravel Pint |

## Getting started

### Requirements

- PHP 8.1 or newer with the usual Laravel extensions
- Composer 2
- MySQL 8 (or MariaDB)
- Node.js (only if you build front-end assets)

### Installation

```bash
git clone <repository-url>
cd GlowThera

composer install
cp .env.example .env        # on Windows cmd: copy .env.example .env
php artisan key:generate
```

Create a database, set the `DB_*` values in `.env`, then:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

The API is now available at `http://localhost:8000/api`.

### Queue worker

Promo notifications are queued. For local development the default `QUEUE_CONNECTION=sync` is enough. In production run a worker:

```bash
php artisan queue:work
```

## Environment variables

Besides the standard Laravel variables, the app reads:

| Variable | Purpose |
|---|---|
| `FRONTEND_URL` | Base URL of the front-end. Used to build the email verification and password reset links and the PayPal return URLs. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Google social login |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | Stripe |
| `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_MODE`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_EGP_TO_USD_RATE` | PayPal (`PAYPAL_MODE` is `sandbox` or `live`) |
| `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `RAZORPAY_EGP_TO_INR_RATE` | Razorpay |

A webhook whose secret is not configured is rejected, so unset secrets can never be used to fake a payment. All of these keys are listed in `.env.example`.

## Running the tests

```bash
php artisan test
```

Run one area at a time while developing:

```bash
php artisan test --filter=SellerPayoutTest
php artisan test tests/Feature/Admin
```

> **Use a separate test database.** The tests use `RefreshDatabase`, which wipes the configured database on every run. By default `phpunit.xml` does not override the connection, so the tests would run against the database in your `.env`.
>
> Create a `.env.testing` file (Laravel loads it automatically when `APP_ENV=testing`) with its own database:
>
> ```dotenv
> DB_CONNECTION=mysql
> DB_DATABASE=glowthera_test
> ```

External services (Google, Stripe, PayPal, Razorpay) are faked in the tests, so no real credentials are needed.

## API overview

All routes are prefixed with `/api`. Protected routes expect `Authorization: Bearer <token>`. Guests use the cart and order endpoints with an `X-Session-Id` header. Send `Accept-Language: ar` (or `?lang=ar`) for Arabic messages.

| Area | Main endpoints | Access |
|---|---|---|
| Auth | `POST /auth/register`, `/auth/login`, `/auth/forgot-password`, `/auth/reset-password`, `POST /auth/logout` | Public, logout needs a token |
| Google | `GET /auth/google/redirect`, `GET /auth/google/callback` | Public |
| Email verification | `GET /email/verify/{id}/{hash}`, `POST /email/resend` | Authenticated |
| Profile | `/profile`, `/addresses`, `/payment-methods`, `/wishlist`, `/loyalty/*` | Authenticated |
| Catalog | `GET /categories`, `GET /products`, `GET /products/{id}`, `GET /products/{id}/reviews` | Public |
| Manage catalog | `/categories` (write), `/products` (write, status, stock, images) | Admin, sellers for their own products |
| Reviews | `POST /products/{id}/reviews`, `PUT/DELETE /reviews/{id}` | Verified purchasers |
| Cart | `/cart`, `/cart/summary`, `/cart/items`, `/cart/coupon` | Guest or user |
| Orders | `POST /orders`, `GET /orders`, `GET /orders/{id}`, `POST /orders/{id}/cancel`, `/pay`, `/reorder` | Guest or user (owner checks apply) |
| Order items | `PATCH /order-items/{id}/status` | Admin, seller |
| Webhooks | `POST /webhooks/stripe`, `/paypal`, `/razorpay` | Signature verified |
| Seller | `POST /seller/register`, `/seller/profile`, `/seller/products`, `/seller/orders`, `/seller/earnings`, `/seller/payouts` | Authenticated, seller role |
| Admin | `/admin/users`, `/admin/products`, `/admin/orders`, `/admin/coupons`, `/admin/banners`, `/admin/sellers/{id}/status`, `/admin/payouts/{id}/mark-paid`, `/admin/wallet/{user}/top-up`, `/admin/newsletter/*` | Admin |
| Newsletter | `POST /newsletter/subscribe`, `GET /newsletter/unsubscribe/{token}` | Public |

Run `php artisan route:list --path=api` for the complete list.

## Business rules

- **Currency**: EGP. PayPal and Razorpay amounts are converted with the configurable rates above.
- **Shipping**: flat fee of 100, not charged on an empty cart.
- **Tier discounts**: 100 off at a subtotal of 1000 or more, 250 off at 2000 or more.
- **Coupons**: percent (up to 100) or fixed amount, optional expiry and usage limit. A coupon is valid through the whole last day. When both a coupon and a tier discount apply, the bigger one wins and the coupon wins a tie. A fixed coupon never discounts more than the subtotal.
- **Stock**: decremented when an order is placed, restored when an order or an item is cancelled (exactly once).
- **Seller item flow**: `pending → processing → shipped`. Sellers cannot skip steps or go backwards. Admins can set any status. Delivered and cancelled items are final.
- **Seller earnings**: counted only from delivered items of that seller.
- **Payouts**: available balance is earnings minus pending and paid payouts. Money is calculated in integer cents, so there are no floating point errors. A payout request locks the seller row inside a transaction to stop concurrent over-withdrawal. An admin can mark a pending payout as paid, once.
- **Guest cart merge**: on login, register and Google login. Quantities are added and capped at stock, inactive or out-of-stock products are skipped, and a merge failure never blocks authentication.
- **Reviews**: only users with a delivered purchase of the product, one review per product.

## Security notes

- **Pre-account takeover protection**: if a Google login matches a local account whose email was never verified, the local password is removed and all of its tokens are revoked before linking. Emails that Google reports as unverified are rejected, and an account already linked to a different Google identity cannot be taken over.
- **Unverified accounts**: they receive a token at registration (it is needed to resend the verification mail) but cannot register as a seller, request payouts or use saved payment methods. The check answers with JSON `403` and `code: email_not_verified` regardless of the `Accept` header.
- **Suspended and deleted users** cannot log in by any method, and suspending a user revokes all of their tokens.
- **Webhooks** verify signatures, are idempotent on retries, and a payment for an already cancelled order is refunded instead of reviving the order.
- Login, register and password routes are throttled (6 requests per minute).
- Public responses only expose safe user fields.

## Project structure

```
app/
  Http/Controllers/Api/
    Admin/            admin controllers (users, products, orders, coupons, payouts, ...)
    Cart/             cart and coupon endpoints (+ ResolvesCart trait)
    Catalog/          categories, products, reviews
    Concerns/         shared controller traits (MergesGuestCart)
    Order/            checkout, cancel, reorder, item status
    Payment/          pay endpoint and gateway webhooks
    Seller/           store profile, products, orders, payouts
    User/             profile, addresses, saved cards, wishlist, loyalty
    AuthController, GoogleAuthController, PasswordResetController, ...
  Http/Middleware/    CheckRole, EnsureEmailIsVerifiedForApi, SetLocale, ...
  Jobs/               queued promo notifications
  Models/
  Support/Payments/   Stripe, PayPal, Razorpay and wallet gateways
database/             migrations, factories, seeders
lang/                 Arabic translations
routes/api.php
tests/Feature/        feature tests grouped by area
```

## Test coverage

507 tests, all feature level except one example unit test.

| Area | What is covered |
|---|---|
| Auth | register, login, Google login (linking, takeover protection, cart merge), email verification, password reset, guest cart merge, unverified account restrictions |
| Catalog | categories (nesting rules, slugs), product browse and filters, product management and permissions, reviews |
| Cart and orders | cart rules, coupons, totals and discount tiers, place order, cancel, reorder, per-item status flow |
| Payments | pay order, wallet, Stripe, PayPal and Razorpay webhooks, cash confirmation |
| Sellers | registration, profile, earnings, payout requests |
| Admin | users, products, sellers, orders and shipping, coupons and stats, payouts |
| Other | wishlist, localization, promo notification job |

## Known decisions and open items

These behaviours are intentionally documented by tests and are product decisions rather than bugs. Each one should be decided before handling real money:

1. **Seller earnings** are counted when an item is delivered, which can happen before the customer's payment is confirmed (for example cash on delivery).
2. **Cancelling an item of a paid order** restores stock but does not refund the customer.
3. **Seller approval** is not checked when requesting a payout, so a pending or suspended seller with delivered sales can withdraw.
4. **Admin shipping edits** are allowed on cancelled and delivered orders, and the change is not logged.
5. **Registration** issues a token before the email is verified (see the security notes for how this is limited).

## License

This project is built on the Laravel framework, which is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
