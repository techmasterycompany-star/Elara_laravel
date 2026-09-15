<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    
    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

   
    public function callback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = DB::transaction(function () use ($googleUser) {

            $user = User::where('provider', 'google')
                ->where('provider_id', $googleUser->getId())
                ->first();

            if ($user) {
                return $user;
            }

            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                $user->update([
                    'provider'    => 'google',
                    'provider_id' => $googleUser->getId(),
                ]);
                return $user;
            }

            return User::create([
                'name'              => $googleUser->getName(),
                'email'             => $googleUser->getEmail(),
                'provider'          => 'google',
                'provider_id'       => $googleUser->getId(),
                'password'          => null,
                'role'              => 'customer',
                'is_active'         => true,
                'email_verified_at' => now(), 
            ]);
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }
}