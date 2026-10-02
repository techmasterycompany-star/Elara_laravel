<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MergesGuestCart;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    use MergesGuestCart;

    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Google authentication failed.',
            ], 401);
        }

        // Never trust an email address Google has not verified: linking by it would let
        // someone claim an account that belongs to another person.
        $raw      = $googleUser->getRaw() ?? [];
        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? true;

        if ($googleUser->getEmail() && filter_var($verified, FILTER_VALIDATE_BOOLEAN) === false) {
            return response()->json([
                'message' => 'Google authentication failed.',
            ], 401);
        }

        $user = DB::transaction(function () use ($googleUser) {
       
            $user = User::withTrashed()
                ->where('provider', 'google')
                ->where('provider_id', $googleUser->getId())
                ->first();

            $foundByEmail = false;

            if (! $user && $googleUser->getEmail()) {
                $user = User::withTrashed()->where('email', $googleUser->getEmail())->first();
                $foundByEmail = (bool) $user;
            }

            if ($user) {
                if ($user->trashed()) {
                    throw ValidationException::withMessages([
                        'login' => ['This account is no longer available.'],
                    ]);
                }

                if (! $user->is_active) {
                    throw ValidationException::withMessages([
                        'login' => ['This account has been suspended.'],
                    ]);
                }

                if ($foundByEmail) {
                    // The email matched, but this account is already tied to another Google identity.
                    if ($user->provider_id && $user->provider === 'google'
                        && $user->provider_id !== $googleUser->getId()) {
                        throw ValidationException::withMessages([
                            'login' => ['This email is linked to a different Google account.'],
                        ]);
                    }

                    // Pre-account takeover protection: if the local account's email was never
                    // verified, whoever registered it may not own the address. Google has just
                    // proven the real owner, so cut off any credentials the registrant holds.
                    if (is_null($user->email_verified_at)) {
                        $user->tokens()->delete();
                        $user->password = null;
                        $user->save();
                        $user->markEmailAsVerified();
                    }

                    $user->update([
                        'provider'    => 'google',
                        'provider_id' => $googleUser->getId(),
                    ]);
                }

                return $user;
            }

            $user = User::create([
                'name'        => $googleUser->getName(),
                'email'       => $googleUser->getEmail(),
                'provider'    => 'google',
                'provider_id' => $googleUser->getId(),
                'password'    => null,
                'role'        => 'customer',
                'is_active'   => true,
            ]);

            $user->markEmailAsVerified();

            return $user;
        });

        // Same behaviour as the normal login/register: keep the guest's cart.
        $this->mergeGuestCart($user, $request->header('X-Session-Id'));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }
}