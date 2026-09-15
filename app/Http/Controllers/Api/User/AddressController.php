<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
  
    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->latest()->get();

        return response()->json([
            'addresses' => $addresses,
        ]);
    }

   
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'        => ['required', 'string', 'max:255'],
            'street'       => ['required', 'string', 'max:255'],
            'city'         => ['required', 'string', 'max:255'],
            'governorate'  => ['required', 'string', 'max:255'],
            'phone'        => ['required', 'string', 'max:20'],
            'is_default'   => ['sometimes', 'boolean'],
        ]);

        $address = DB::transaction(function () use ($request, $validated) {
            if (! empty($validated['is_default'])) {
                $request->user()->addresses()->update(['is_default' => false]);
            }

            $isFirstAddress = $request->user()->addresses()->doesntExist();

            return $request->user()->addresses()->create([
                ...$validated,
                'is_default' => $validated['is_default'] ?? $isFirstAddress,
            ]);
        });

        return response()->json([
            'message' => 'Address added successfully.',
            'address' => $address,
        ], 201);
    }

    public function update(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            abort(403, 'This address does not belong to you.');
        }

        $validated = $request->validate([
            'label'        => ['sometimes', 'string', 'max:255'],
            'street'       => ['sometimes', 'string', 'max:255'],
            'city'         => ['sometimes', 'string', 'max:255'],
            'governorate'  => ['sometimes', 'string', 'max:255'],
            'phone'        => ['sometimes', 'string', 'max:20'],
            'is_default'   => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $address, $validated) {
            if (! empty($validated['is_default'])) {
                $request->user()->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($validated);
        });

        return response()->json([
            'message' => 'Address updated successfully.',
            'address' => $address->fresh(),
        ]);
    }

    
    public function destroy(Request $request, Address $address)
    {
        if ($address->user_id !== $request->user()->id) {
            abort(403, 'This address does not belong to you.');
        }

        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $request->user()->addresses()->oldest()->first()?->update(['is_default' => true]);
        }

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }
}