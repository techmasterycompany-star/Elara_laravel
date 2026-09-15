<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * عرض بيانات المستخدم الحالي (صاحب التوكن).
     */
    public function show(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * تحديث الاسم و/أو الصورة الشخصية.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'avatar' => ['sometimes', 'image', 'max:2048'], 
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $path;
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
        ]);
    }

   
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        if (is_null($user->password)) {
            return response()->json([
                'message' => 'This account uses social login and has no password to change.',
            ], 422);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'          => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json([
            'message' => 'Password updated successfully. Other devices have been logged out.',
        ]);
    }
}