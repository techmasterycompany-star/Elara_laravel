<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;

class BannerController extends Controller
{
  
    public function index()
    {
        $banners = Banner::active()->get();

        return response()->json([
            'banners' => $banners,
        ]);
    }

    public function adminIndex()
    {
        $banners = Banner::orderBy('position')->get();

        return response()->json([
            'banners' => $banners,
        ]);
    }

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'    => ['required', 'string', 'max:255'],
            'image'    => ['required', 'image', 'max:2048'],
            'link'     => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $lastPosition = Banner::max('position') ?? 0;

        $banner = Banner::create([
            'title'      => $validated['title'],
            'link'       => $validated['link'] ?? null,
            'position'   => $validated['position'] ?? $lastPosition + 1,
            'image_path' => $request->file('image')->store('banners', 'public'),
        ]);

        return response()->json([
            'message' => 'Banner created successfully.',
            'banner'  => $banner,
        ], 201);
    }

   
    public function update(Request $request, Banner $banner)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:2048'],
            'link'  => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('banners', 'public');
        }

        $banner->update($validated);

        return response()->json([
            'message' => 'Banner updated successfully.',
            'banner'  => $banner->fresh(),
        ]);
    }

   
    public function destroy(Banner $banner)
    {
        $banner->delete();

        return response()->json([
            'message' => 'Banner deleted successfully.',
        ]);
    }

   
    public function toggleActive(Banner $banner)
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return response()->json([
            'message' => $banner->is_active ? 'Banner activated.' : 'Banner deactivated.',
            'banner'  => $banner->fresh(),
        ]);
    }

    
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:banners,id'],
        ]);

        foreach ($validated['order'] as $index => $bannerId) {
            Banner::where('id', $bannerId)->update(['position' => $index + 1]);
        }

        return response()->json([
            'message' => 'Banners reordered successfully.',
            'banners' => Banner::orderBy('position')->get(),
        ]);
    }
}