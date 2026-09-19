<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
   
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'    => ['nullable', 'string', 'max:255'],
            'role'      => ['nullable', 'in:customer,seller,admin'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = User::query();

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->latest()->paginate(20);

        return response()->json($users);
    }

  
    public function show(User $user)
    {
        return response()->json([
            'user' => $user,
        ]);
    }

   
    public function suspend(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot suspend your own account.',
            ], 422);
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'User suspended successfully.',
            'user'    => $user->fresh(),
        ]);
    }

   
    public function activate(User $user)
    {
        $user->update(['is_active' => true]);

        return response()->json([
            'message' => 'User activated successfully.',
            'user'    => $user->fresh(),
        ]);
    }

    
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}