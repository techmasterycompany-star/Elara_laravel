<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SellerController extends Controller
{
  
    public function register(Request $request)
    {
        $user = $request->user();

        if ($user->seller) {
            return response()->json([
                'message' => 'You already have a seller profile.',
            ], 409);
        }

        $validated = $request->validate([
            'store_name'  => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $seller = Seller::create([
            'user_id'     => $user->id,
            'store_name'  => $validated['store_name'],
            'store_slug'  => $this->generateUniqueSlug($validated['store_name']),
            'description' => $validated['description'] ?? null,
            'status'      => 'pending',
        ]);

        $user->update(['role' => 'seller']);

        return response()->json([
            'message' => 'Seller registration submitted. Waiting for admin approval.',
            'seller'  => $seller,
        ], 201);
    }

   
    public function show(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        return response()->json([
            'seller' => $seller,
        ]);
    }

    
    public function update(Request $request)
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller profile yet.',
            ], 404);
        }

        $validated = $request->validate([
            'store_name'  => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        if (isset($validated['store_name']) && $validated['store_name'] !== $seller->store_name) {
            $validated['store_slug'] = $this->generateUniqueSlug($validated['store_name']);
        }

        $seller->update($validated);

        return response()->json([
            'message' => 'Store profile updated successfully.',
            'seller'  => $seller->fresh(),
        ]);
    }

    
    private function generateUniqueSlug(string $storeName): string
    {
        $slug = Str::slug($storeName);
        $originalSlug = $slug;
        $counter = 1;

        while (Seller::where('store_slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}