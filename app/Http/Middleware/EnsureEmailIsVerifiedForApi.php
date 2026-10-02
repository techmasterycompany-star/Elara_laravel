<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like Laravel's "verified" middleware, but it always answers with JSON.
 * (The stock one redirects to a "verification.notice" route when the client
 * does not send "Accept: application/json", and this API has no such route.)
 */
class EnsureEmailIsVerifiedForApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is not verified.',
                'code'    => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}
